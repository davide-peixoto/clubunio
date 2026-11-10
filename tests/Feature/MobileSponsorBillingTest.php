<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BexioConnectionService, SponsorRunBexioClient, SponsorRunBillingService, TestMailGuard};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

final class SyntheticMobileSponsorBillingProvider extends SponsorRunBexioClient
{
    public array $writes = [], $contact = [], $invoice = [];
    public bool $uncertain = false, $identityValid = true;
    public int $reads = 0, $verified = 0;
    public function verify(string $token): void { $this->verified++; abort_unless($this->identityValid, 409); }
    public function catalog(string $token, string $kind): array { return [['id' => 1, 'name' => $kind === 'currencies' ? 'CHF' : 'Synthetic', 'iso_3166_alpha2' => 'CH']]; }
    public function findContact(string $token, string $reference): ?array { $this->reads++; return ($this->contact['remarks'] ?? null) === $reference ? $this->contact : null; }
    public function findInvoice(string $token, string $reference): ?array { $this->reads++; return ($this->invoice['api_reference'] ?? null) === $reference ? $this->invoice : null; }
    public function read(string $token, string $path, array $query = []): array { $this->reads++; return str_contains($path, '/contact/') ? $this->contact : $this->invoice; }
    public function pdf(string $token, int $id): string { return "%PDF-1.4\nsynthetic sponsor invoice, no real person"; }
    public function write(string $token, string $path, array $payload = []): array {
        $this->writes[] = [$path, $payload];
        if ($path === '/2.0/contact') { $this->contact = ['id' => 77] + $payload; return ['state' => 'accepted', 'id' => 77]; }
        if ($path === '/2.0/kb_invoice') {
            $this->invoice = ['id' => 88, 'kb_item_status_id' => 7, 'total' => $payload['positions'][0]['unit_price'],
                'total_received_payments' => '0.00', 'total_credit_vouchers' => '0.00'] + $payload;
            return ['state' => $this->uncertain ? 'uncertain' : 'accepted', 'id' => 88];
        }
        if (str_ends_with($path, '/issue')) $this->invoice['kb_item_status_id'] = 8;
        if (str_ends_with($path, '/cancel')) $this->invoice['kb_item_status_id'] = 19;
        return ['state' => 'accepted', 'id' => null];
    }
}

class MobileSponsorBillingTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private int $event, $pledge, $member;
    private string $base, $token;
    private array $configuration;
    private SyntheticMobileSponsorBillingProvider $provider;
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Synthetic rollback opt-in required');
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_sponsor_enabled' => true,
            'clubunio-mobile.native_sponsor_billing_enabled' => true, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests(); Mail::fake(); $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name TO 'clubunio-mobile-sponsor-billing-test'");
        $this->tenant = Tenant::create(['slug' => 'mobile-billing-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic mobile billing']);
        $this->actor = new Account; $this->actor->forceFill(['username' => 'mobile-billing-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic billing admin',
            'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin', 'portal_enabled' => true]);
        $season = $this->insert('seasons', ['name' => 'Synthetic billing season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $team = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic billing team']), 'season_id' => $season, 'label' => 'Synthetic team']);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Runner']);
        $this->insert('sponsor_run_statuses', ['code' => 'open', 'name' => 'Synthetic open']);
        $this->event = $this->insert('sponsor_run_events', ['season_id' => $season, 'title' => 'Synthetic mobile run', 'place' => 'Synthetic field',
            'event_date' => now('Europe/Zurich')->toDateString(), 'registration_deadline' => now('Europe/Zurich')->toDateString(), 'status_code' => 'open', 'created_by' => $this->actor->id]);
        $participant = $this->insert('sponsor_run_participants', ['event_id' => $this->event, 'member_id' => $this->member, 'team_season_id' => $team,
            'draft_laps' => 8, 'final_laps' => 8, 'approved_at' => now(), 'approved_by' => $this->actor->id]);
        $this->pledge = $this->insert('sponsor_run_pledges', ['event_id' => $this->event, 'participant_id' => $participant, 'sponsor_name' => 'Synthetic donor',
            'payment_mode' => 'invoice', 'amount_type' => 'per_lap', 'amount_cents' => 250, 'cap_cents' => 1800, 'recipient_type' => 'person',
            'recipient_encrypted' => Crypt::encryptString(json_encode(['company' => '', 'first_name' => 'Synthetic', 'last_name' => 'Payer', 'street' => 'Synthetic street',
                'house_number' => '1', 'postal_code' => '4000', 'city' => 'Synthetic city', 'country' => 'CH', 'email' => 'synthetic-never-send@example.invalid'])),
            'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_by' => $this->actor->id]);
        $this->insert('tenant_settings', ['area' => 'bexio', 'key' => BexioConnectionService::CONNECTION_KEY, 'label' => 'Synthetic connection binding only', 'value_type' => 'string',
            'encrypted_value' => Crypt::encryptString(json_encode(['fingerprint' => str_repeat('a', 64), 'connected_at' => '2026-11-03T00:00:00+00:00']))]);
        // Only token transport/provider bytes are substituted; authorization, leases, SyncWork and billing domain remain real.
        $this->partialMock(BexioConnectionService::class, fn ($mock) => $mock->shouldReceive('accessToken')->andReturn('synthetic-no-provider-token'));
        $this->provider = new SyntheticMobileSponsorBillingProvider; $this->app->instance(SponsorRunBexioClient::class, $this->provider);
        $this->configuration = ['enabled' => true, ...array_fill_keys(array_keys(SponsorRunBillingService::FIELDS), 1), 'mwst_type' => 2, 'due_days' => 30];
        app(SponsorRunBillingService::class)->saveConfiguration($this->actor, $this->tenant, $this->configuration);
        $this->token = $this->postJson('/api/mobile/v1/session', ['username' => $this->actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic billing'])->assertOk()->json('data.token');
        $this->withToken($this->token); $this->base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/sponsor-run/billing';
    }
    protected function tearDown(): void { if (isset($this->db)) $this->db->rollBack(); parent::tearDown(); }
    private function insert(string $table, array $values): int { return $this->db->table('clubunio.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values); }
    private function listing() { return $this->getJson($this->base.'/events/'.$this->event)->assertOk(); }
    private function prepare(): array {
        $row = $this->listing()->json('data.Candidates.0');
        return $this->postJson($this->base.'/events/'.$this->event.'/prepare', ['PledgeID' => $this->pledge, 'SourceVersion' => $row['SourceVersion'], 'Confirm' => true])
            ->assertOk()->json('data.Invoice');
    }
    private function action(array $shown, string $action, array $extra = []) {
        return $this->postJson($this->base.'/invoices/'.$shown['InvoiceID'].'/'.$action,
            ['EventID' => $this->event, 'Revision' => $shown['Revision'], 'Confirm' => true] + $extra);
    }
    public function test_preview_is_local_and_prepare_pins_displayed_source_and_is_idempotent(): void {
        config(['clubunio-mobile.native_sponsor_billing_enabled' => false]);
        $row = $this->listing()->assertJsonPath('data.Settings.ProviderActionsEnabled', false)->assertJsonPath('data.Candidates.0.TotalCents', 1800)
            ->assertJsonPath('data.Candidates.0.AvailableActions', [])->json('data.Candidates.0');
        $this->assertSame(0, $this->db->table('clubunio.sponsor_run_invoices')->where('tenant_id', $this->tenant->id)->count());
        $input = ['PledgeID' => $this->pledge, 'SourceVersion' => $row['SourceVersion'], 'Confirm' => true];
        $this->postJson($this->base.'/events/'.$this->event.'/prepare', $input)->assertStatus(503);
        config(['clubunio-mobile.native_sponsor_billing_enabled' => true]);
        $this->db->table('clubunio.sponsor_run_pledges')->where('id', $this->pledge)->increment('revision');
        $this->postJson($this->base.'/events/'.$this->event.'/prepare', $input)->assertConflict();
        $fresh = $this->listing()->json('data.Candidates.0'); $input['SourceVersion'] = $fresh['SourceVersion'];
        $invoice = $this->postJson($this->base.'/events/'.$this->event.'/prepare', $input)->assertOk()->assertJsonPath('data.Invoice.State', 'DRAFT')->json('data.Invoice');
        $this->postJson($this->base.'/events/'.$this->event.'/prepare', $input)->assertOk()->assertJsonPath('data.Invoice.InvoiceID', $invoice['InvoiceID']);
        $this->assertSame(1, $this->db->table('clubunio.sponsor_run_invoices')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame([], $this->provider->writes); $this->assertSame(0, $this->provider->reads); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_original_provider_cycle_pdf_replays_and_paid_invoice_guard_use_real_billing_domain(): void {
        $invoice = $this->prepare();
        $first = $invoice; $invoice = $this->action($invoice, 'create-contact')->assertOk()->assertJsonPath('data.Invoice.State', 'CONTACT_READY')->json('data.Invoice');
        $reads = $this->provider->reads;
        $this->action($first, 'create-contact')->assertOk()->assertJsonPath('data.Replayed', true)->assertJsonPath('data.Invoice.Revision', $invoice['Revision']);
        $this->assertSame($reads, $this->provider->reads); $this->assertCount(1, $this->provider->writes);
        $invoice = $this->action($invoice, 'create-draft')->assertOk()->assertJsonPath('data.Invoice.State', 'BEXIO_DRAFT')->json('data.Invoice');
        $invoice = $this->action($invoice, 'issue')->assertOk()->assertJsonPath('data.Invoice.State', 'ISSUED')->json('data.Invoice');
        $invoice = $this->action($invoice, 'send')->assertOk()->assertJsonPath('data.Invoice.State', 'SENT')->json('data.Invoice');
        $send = collect($this->provider->writes)->first(fn ($row) => str_ends_with($row[0], '/send'));
        $this->assertSame(TestMailGuard::RECIPIENT, $send[1]['recipient_email']); $this->assertCount(4, $this->provider->writes);
        $path = $this->base.'/invoices/'.$invoice['InvoiceID'].'/pdf?event='.$this->event.'&revision='.$invoice['Revision'];
        $this->get($path)->assertOk()->assertHeader('X-Sponsor-Billing-Revision', (string) $invoice['Revision'])
            ->assertHeader('X-Content-SHA256', hash('sha256', $this->provider->pdf('synthetic', 88)));
        $this->get($path.'0')->assertConflict();
        $this->provider->invoice['kb_item_status_id'] = 9; $this->provider->invoice['total_received_payments'] = '18.00';
        $invoice = $this->action($invoice, 'sync')->assertOk()->assertJsonPath('data.Invoice.State', 'PAID')->assertJsonPath('data.Invoice.PaidCents', 1800)->json('data.Invoice');
        $this->assertNotContains('cancel', $invoice['AvailableActions']);
        $this->action($invoice, 'cancel', ['Reason' => 'Synthetic paid invoice rejection'])->assertConflict();
        $this->assertCount(4, $this->provider->writes); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_ambiguous_creation_stays_durable_and_replay_only_reconciles_without_another_write(): void {
        $invoice = $this->action($this->prepare(), 'create-contact')->assertOk()->json('data.Invoice');
        $this->provider->uncertain = true;
        $this->action($invoice, 'create-draft')->assertConflict();
        $pending = $this->listing()->assertJsonPath('data.Candidates.0.State', 'CREATE_PENDING')->assertJsonPath('data.Candidates.0.RequiresReconciliation', true)->json('data.Candidates.0');
        $this->assertSame(['sync'], $pending['AvailableActions']);
        $this->assertTrue($this->db->table('clubunio.sync_work_items')->where('tenant_id', $this->tenant->id)->where('state', 'attention')->exists());
        $this->action($invoice, 'create-draft')->assertOk()->assertJsonPath('data.Invoice.State', 'BEXIO_DRAFT');
        $this->assertCount(2, $this->provider->writes);
        $this->action($invoice, 'create-draft')->assertOk()->assertJsonPath('data.Replayed', true);
        $this->assertCount(2, $this->provider->writes); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_scope_configuration_binding_and_revocation_never_expand_to_provider_or_bank_authority(): void {
        $invoice = $this->prepare();
        app(SponsorRunBillingService::class)->saveConfiguration($this->actor, $this->tenant, array_replace($this->configuration, ['due_days' => 31]));
        $changed = $this->listing()->json('data.Candidates.0'); $this->assertNotContains('create-contact', $changed['AvailableActions']);
        $this->action($invoice, 'create-contact')->assertConflict(); $this->assertSame([], $this->provider->writes);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $this->listing()->assertJsonPath('data.Candidates.0.AvailableActions', []);
        $this->action($invoice, 'create-contact')->assertForbidden();
        $this->db->table('clubunio.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id,
            'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'member']);
        $this->getJson($this->base.'/events/'.$this->event)->assertForbidden();
        $this->db->table('clubunio.mobile_sessions')->where('account_id', $this->actor->id)->update(['revoked_at' => now()]);
        $this->getJson($this->base.'/events/'.$this->event)->assertUnauthorized();
        $this->assertSame([], $this->provider->writes); Http::assertNothingSent(); Mail::assertNothingSent();
    }
}
