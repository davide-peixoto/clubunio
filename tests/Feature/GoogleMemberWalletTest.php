<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberCardService;
use App\ClubUnio\Wallet\{GoogleMemberWalletService, GoogleWalletClient};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleCardFixture extends MemberCardService
{
    public bool $valid = true;
    public string $version = 'initial';
    public function enabled(): bool { return true; }
    public function origin(): string { return 'https://stage.clubunio.com'; }
    public function ensureCard(Tenant $tenant, int $memberId): object {
        return DB::connection('clubunio')->table('clubora.member_cards')->where('tenant_id', $tenant->id)->where('member_id', $memberId)->firstOrFail();
    }
    public function snapshot(Tenant $tenant, int $memberId): array {
        return ['Name' => 'Synthetic Holder', 'TenantName' => $tenant->name, 'Valid' => $this->valid,
            'Brand' => ['PrimaryColor' => '#cf1032'], 'SourceVersion' => hash('sha256', $this->version)];
    }
    public function verification(Tenant $tenant, int $memberId, ?array $snapshot = null): ?array {
        return ['Url' => $this->origin().'/member-card/'.hash('sha256', $this->version)];
    }
    public function logoBytes(Tenant $tenant): ?string { return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWj8AAAAASUVORK5CYII='); }
}

class GoogleClientFixture extends GoogleWalletClient
{
    public array $sent = [];
    public bool $fail = false;
    public $during = null;
    public function available(): bool { return true; }
    public function upsert(array $payload): void {
        $this->sent[] = $payload;
        if ($this->fail) throw new \RuntimeException('synthetic_failure');
        if ($this->during) ($this->during)();
    }
}

class GoogleMemberWalletTest extends TestCase
{
    private GoogleCardFixture $cards;
    private GoogleClientFixture $client;
    private GoogleMemberWalletService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://stage.clubunio.com', 'clubunio-google-wallet.environment' => 'stage',
            'clubunio-google-wallet.public_origin' => 'https://stage.clubunio.com',
            'clubunio-google-wallet.issuer_id' => '3388000000023233410',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio'); $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,name text,slug text,created_at text,updated_at text);
CREATE TABLE clubora.member_cards(id text primary key,tenant_id integer,member_id integer);
CREATE TABLE clubora.member_google_wallet_passes(id text primary key,card_id text unique,tenant_id integer,member_id integer,
environment text,object_id text unique,projection_encrypted text,logo_encrypted text,logo_hash text,fingerprint text,
desired_fingerprint text,dirty integer default 1,attempts integer default 0,next_attempt_at text,checked_at text,synced_at text,last_error text,
created_at text default CURRENT_TIMESTAMP,updated_at text default CURRENT_TIMESTAMP);
SQL);
        $db->table('clubora.tenants')->insert(['id' => 1, 'name' => 'Synthetic Club', 'slug' => 'synthetic']);
        $db->table('clubora.member_cards')->insert(['id' => (string) Str::uuid(), 'tenant_id' => 1, 'member_id' => 44]);
        $this->tenant = Tenant::findOrFail(1);
        $this->app->instance(MemberCardService::class, $this->cards = new GoogleCardFixture);
        $this->app->instance(GoogleWalletClient::class, $this->client = new GoogleClientFixture);
        $this->service = new GoogleMemberWalletService;
    }

    public function test_stable_object_branding_qr_privacy_and_unchanged_cache(): void
    {
        $one = $this->service->issue($this->tenant, 44);
        $two = $this->service->issue($this->tenant, 44);
        $this->assertSame($one->object_id, $two->object_id);
        $this->assertCount(1, $this->client->sent);
        $payload = $this->client->sent[0];
        $this->assertSame('#cf1032', $payload['hexBackgroundColor']);
        $this->assertSame($this->cards->verification($this->tenant, 44)['Url'], $payload['barcode']['value']);
        $this->assertSame(['de', 'en'], array_column($payload['textModulesData'][0]['localizedBody']['translatedValues'], 'language'));
        $this->assertStringNotContainsString('Synthetic Holder', $one->projection_encrypted);
        $this->assertStringNotContainsString('birth', json_encode($payload));
        $this->assertStringNotContainsString('email', json_encode($payload));
        $this->assertSame(0, (int) $two->dirty);
    }

    public function test_status_rotation_and_revocation_are_synced_with_same_object(): void
    {
        $row = $this->service->issue($this->tenant, 44);
        $this->cards->valid = false; $this->cards->version = 'revoked';
        $this->assertSame(['checked' => 1, 'failed' => 0], $this->service->refresh());
        $this->assertSame('INACTIVE', $this->client->sent[1]['state']);
        $this->assertSame($row->object_id, $this->client->sent[1]['id']);
        $this->assertStringNotContainsString('Synthetic Holder', json_encode($this->client->sent[1]));
        $this->assertNotSame($this->client->sent[0]['barcode']['value'], $this->client->sent[1]['barcode']['value']);
    }

    public function test_provider_failure_durable_retry_and_changed_status_bypasses_old_backoff(): void
    {
        $this->client->fail = true;
        try { $this->service->issue($this->tenant, 44); $this->fail('Expected provider failure'); } catch (\RuntimeException) {}
        $row = $this->service->passes()->firstOrFail();
        $this->assertSame(1, (int) $row->dirty); $this->assertSame(1, $row->attempts);
        $this->service->refresh(); $this->assertCount(1, $this->client->sent);
        $this->cards->valid = false; $this->cards->version = 'changed'; $this->client->fail = false;
        $this->service->refresh(); $this->assertCount(2, $this->client->sent);
        $this->assertSame(0, (int) $this->service->passes()->firstOrFail()->dirty);
    }

    public function test_revision_change_during_provider_write_leaves_retry(): void
    {
        $this->client->during = function () { $this->cards->version = 'raced'; $this->cards->valid = false; };
        try { $this->service->issue($this->tenant, 44); $this->fail('Expected stale revision'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertSame(1, (int) $this->service->passes()->firstOrFail()->dirty);
        $this->client->during = null;
        $this->service->refresh();
        $this->assertSame('INACTIVE', $this->client->sent[1]['state']);
    }

    public function test_cross_environment_row_is_rejected_before_network(): void
    {
        $row = $this->service->issue($this->tenant, 44);
        $this->service->passes()->where('id', $row->id)->update(['environment' => 'prod']);
        try { $this->service->synchronize($row->id); $this->fail('Expected isolation'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(503, $error->getStatusCode()); }
        $this->assertCount(1, $this->client->sent);
    }
}
