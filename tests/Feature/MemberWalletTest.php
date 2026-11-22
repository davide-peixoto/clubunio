<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberCardService;
use App\ClubUnio\Wallet\{ApplePassBundle, AppleWalletPush, MemberWalletController, MemberWalletService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletCardFixture extends MemberCardService
{
    public bool $valid = true;
    public string $name = 'Synthetic member';
    public string $version = 'initial';
    public function ensureCard(Tenant $tenant, int $memberId): object { return DB::connection('clubunio')->table('clubora.member_cards')->firstOrFail(); }
    public function snapshot(Tenant $tenant, int $memberId): array
    {
        return ['Name' => $this->name, 'TenantName' => $tenant->name, 'Valid' => $this->valid,
            'StatusText' => 'Synthetic status', 'Brand' => ['PrimaryColor' => '#a02030'], 'SourceVersion' => hash('sha256', $this->version)];
    }
    public function verification(Tenant $tenant, int $memberId, ?array $snapshot = null): ?array
    {
        return ['Url' => 'https://wallet.example.invalid/member-card/'.str_repeat('a', 64)];
    }
    public function logoBytes(Tenant $tenant): ?string
    {
        $image = imagecreatetruecolor(8, 8); imagefill($image, 0, 0, imagecolorallocate($image, 160, 32, 48));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image); return $bytes;
    }
}

class WalletBundleFixture extends ApplePassBundle
{
    public function available(): bool { return true; }
    public function signingVersion(): string { return 'synthetic-signing-version'; }
    public function build(array $pass, array $images): string { return 'PK'.json_encode($pass, JSON_THROW_ON_ERROR); }
}

class WalletPushFixture extends AppleWalletPush
{
    public int $status = 200;
    public int $calls = 0;
    public function send(string $token, string $type): int { $this->calls++; return $this->status; }
}

class MemberWalletTest extends TestCase
{
    private Tenant $tenant;
    private WalletCardFixture $cards;
    private MemberWalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'clubunio-wallet.enabled' => true,
            'clubunio-wallet.web_service_origin' => 'https://wallet.example.invalid',
            'clubunio-wallet.pass_type_identifier' => 'pass.com.example.member', 'clubunio-wallet.team_identifier' => 'TEAM000001',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio'); $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,name text,slug text,created_at text,updated_at text);
CREATE TABLE clubora.member_cards(id text primary key,tenant_id integer,member_id integer,revoked_at text);
CREATE TABLE clubora.member_wallet_passes(serial text primary key,card_id text unique,tenant_id integer,member_id integer,
pass_type text,auth_hash text,auth_encrypted text,snapshot_encrypted text,artwork_encrypted text,fingerprint text,
revision integer default 1,update_tag integer default 0,package_encrypted text,scanned_at text,
created_at text default CURRENT_TIMESTAMP,updated_at text default CURRENT_TIMESTAMP);
CREATE TABLE clubora.member_wallet_changes(id integer primary key autoincrement,serial text,created_at text default CURRENT_TIMESTAMP);
CREATE TABLE clubora.member_wallet_registrations(id integer primary key autoincrement,serial text,device_hash text,push_encrypted text,
notified_revision integer default 0,attempts integer default 0,next_attempt_at text,
created_at text default CURRENT_TIMESTAMP,updated_at text default CURRENT_TIMESTAMP,unique(serial,device_hash));
SQL);
        $db->table('clubora.tenants')->insert(['id' => 1, 'name' => 'Synthetic club', 'slug' => 'synthetic']);
        $db->table('clubora.member_cards')->insert(['id' => (string) Str::uuid(), 'tenant_id' => 1, 'member_id' => 44]);
        $this->tenant = Tenant::findOrFail(1);
        $this->cards = new WalletCardFixture;
        $this->app->instance(MemberCardService::class, $this->cards);
        $this->app->instance(ApplePassBundle::class, new WalletBundleFixture);
        $this->service = new MemberWalletService;
        $this->app->instance(MemberWalletService::class, $this->service);
    }

    protected function tearDown(): void
    {
        DB::purge('clubunio');
        parent::tearDown();
    }

    private function request(string $path, string $method, array $route, ?string $auth = null, array $data = []): Request
    {
        $request = Request::create('https://wallet.example.invalid/wallet/v1/'.$path, $method, $data);
        $request->setRouteResolver(fn () => new class($route) {
            public function __construct(private array $values) {}
            public function parameter($key, $default = null) { return $this->values[$key] ?? $default; }
        });
        if ($auth) { $request->headers->set('Authorization', $auth); }
        return $request;
    }

    public function test_reissue_keeps_serial_and_secret_and_snapshot_change_uses_monotonic_tags_even_at_frozen_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first = $this->service->issue($this->tenant, 44);
        $again = $this->service->issue($this->tenant, 44);
        $this->assertSame($first->serial, $again->serial);
        $this->assertSame($first->auth_hash, $again->auth_hash);
        $this->assertSame(1, $again->revision);
        $this->cards->name = 'Changed synthetic member'; $this->cards->version = 'name changed';
        $changed = $this->service->issue($this->tenant, 44);
        $this->assertGreaterThan($again->update_tag, $changed->update_tag);
        $this->assertSame(2, $changed->revision);
        $this->cards->valid = false; $this->cards->version = 'membership ended';
        $ended = $this->service->refresh($changed);
        $this->assertGreaterThan($changed->update_tag, $ended->update_tag);
        $this->assertTrue($this->service->payload($ended)['voided']);
        $this->assertSame($first->serial, $ended->serial);
        $this->assertSame($first->auth_hash, $ended->auth_hash);
        $this->travelBack();
    }

    public function test_pass_contains_club_brand_and_minimal_identity_and_live_verification_not_private_fields(): void
    {
        $row = $this->service->issue($this->tenant, 44); $payload = $this->service->payload($row);
        $this->assertSame('Synthetic club', $payload['organizationName']);
        $this->assertSame('rgb(160, 32, 48)', $payload['backgroundColor']);
        $this->assertSame($this->cards->verification($this->tenant, 44)['Url'], $payload['barcodes'][0]['message']);
        $json = json_encode($payload);
        foreach (['Geburtsdatum', 'birth_date', 'email', 'AHV', 'street', 'member_id', 'tenant_id'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertFalse($payload['voided']);
        $this->assertNotSame(hash('sha256', str_repeat('a', 64)), $row->auth_hash);
    }

    public function test_registration_authentication_and_device_scoped_updates_and_idempotent_unregister(): void
    {
        $row = $this->service->issue($this->tenant, 44);
        $auth = 'ApplePass '.Crypt::decryptString($row->auth_encrypted);
        $route = ['device' => str_repeat('D', 32), 'type' => $row->pass_type, 'serial' => $row->serial];
        $controller = new MemberWalletController;
        $request = $this->request('register', 'POST', $route, $auth, ['pushToken' => str_repeat('b', 64)]);
        $this->assertSame(201, $controller->register($request)->getStatusCode());
        $this->assertSame(200, $controller->register($request)->getStatusCode());
        $this->assertSame(1, $this->service->registrations()->count());
        $result = $controller->updates($this->request('updates', 'GET', $route));
        $this->assertSame([$row->serial], json_decode($result->getContent(), true)['serialNumbers']);
        $other = array_replace($route, ['device' => str_repeat('Z', 32)]);
        $this->assertSame(204, $controller->updates($this->request('updates', 'GET', $other))->getStatusCode());
        $since = ['passesUpdatedSince' => (string) $row->update_tag];
        $this->assertSame(204, $controller->updates($this->request('updates', 'GET', $route, null, $since))->getStatusCode());
        $this->assertSame(200, $controller->unregister($this->request('remove', 'DELETE', $route, $auth))->getStatusCode());
        $this->assertSame(200, $controller->unregister($this->request('remove', 'DELETE', $route, $auth))->getStatusCode());
        $this->assertSame(0, $this->service->registrations()->count());
    }

    public function test_wrong_pass_secret_or_type_cannot_authenticate_an_existing_serial(): void
    {
        $row = $this->service->issue($this->tenant, 44);
        foreach ([['pass.com.wrong', 'ApplePass '.Crypt::decryptString($row->auth_encrypted)], [$row->pass_type, 'ApplePass '.str_repeat('0', 64)], [$row->pass_type, null]] as [$type, $auth]) {
            try { $this->service->authorizedPass($type, $row->serial, $auth); $this->fail('Unauthorised pass accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(401, $error->getStatusCode()); }
        }
    }

    public function test_wallet_protocol_rejects_plain_http_wrong_host_and_oversized_log_body(): void
    {
        $controller = new MemberWalletController;
        foreach ([['http://wallet.example.invalid/wallet/v1/log', '', 404], ['https://other.example.invalid/wallet/v1/log', '', 404],
            ['https://wallet.example.invalid/wallet/v1/log', str_repeat('x', 10_241), 413]] as [$url, $body, $status]) {
            try { $controller->logs(Request::create($url, 'POST', [], [], [], [], $body)); $this->fail('Protocol guard bypassed'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
        }
        $this->assertSame(200, $controller->logs(Request::create('https://wallet.example.invalid/wallet/v1/log', 'POST', [], [], [], [], '{"logs":["discard"]}'))->getStatusCode());
    }

    public function test_background_push_only_marks_delivered_revision_after_success_and_prunes_gone_tokens(): void
    {
        $row = $this->service->issue($this->tenant, 44);
        $id = $this->service->registrations()->insertGetId(['serial' => $row->serial, 'device_hash' => hash('sha256', 'device'), 'push_encrypted' => Crypt::encryptString(str_repeat('a', 64))]);
        $push = new WalletPushFixture; $push->status = 503; $this->app->instance(AppleWalletPush::class, $push);
        $failed = $this->service->refreshAndNotify();
        $this->assertSame(1, $failed['failed']);
        $saved = $this->service->registrations()->where('id', $id)->first();
        $this->assertSame(0, $saved->notified_revision); $this->assertSame(1, $saved->attempts);
        $this->service->registrations()->where('id', $id)->update(['next_attempt_at' => now()->subSecond()]);
        $push->status = 200; $this->assertSame(1, $this->service->refreshAndNotify()['sent']);
        $this->assertSame(1, $this->service->registrations()->where('id', $id)->value('notified_revision'));
        $this->assertSame(0, $this->service->refreshAndNotify()['sent']);
        $this->cards->version = 'revoked'; $this->cards->valid = false; $push->status = 410;
        $this->service->refreshAndNotify(); $this->assertSame(0, $this->service->registrations()->count());
    }

    public function test_disabled_wallet_does_not_query_tables_or_send_external_requests(): void
    {
        $this->app->instance(ApplePassBundle::class, new ApplePassBundle);
        config(['clubunio-wallet.enabled' => false]);
        DB::connection('clubunio')->enableQueryLog();
        $this->assertFalse($this->service->available());
        $this->assertSame(['refreshed' => 0, 'sent' => 0, 'failed' => 0, 'configured' => false], $this->service->refreshAndNotify());
        $this->assertSame([], DB::connection('clubunio')->getQueryLog());
    }
}
