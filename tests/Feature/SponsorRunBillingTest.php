<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\SponsorRunBilling;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\SponsorRunBexioClient;
use App\ClubUnio\Services\SponsorRunBillingService;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SponsorRunBillingTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private SponsorRunBillingService $service;

    private FakeSponsorBillingClient $client;

    private int $pledge;

    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in outer rollback required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'Synthetic billing', 'slug' => 'billing-'.Str::random(15)]);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(25), 'display_name' => 'Synthetic billing admin', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $season = $this->insert('seasons', ['name' => 'Synthetic season']);
        $team = $this->insert('teams', ['name' => 'Synthetic team']);
        $teamSeason = $this->insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Synthetic team']);
        $member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Runner']);
        $this->insert('sponsor_run_statuses', ['code' => 'open', 'name' => 'Offen']);
        $event = $this->insert('sponsor_run_events', ['season_id' => $season, 'title' => 'Synthetic run', 'place' => 'Test', 'event_date' => now('Europe/Zurich')->toDateString(), 'registration_deadline' => now('Europe/Zurich')->toDateString(), 'status_code' => 'open', 'created_by' => $this->actor->id]);
        $participant = $this->insert('sponsor_run_participants', ['event_id' => $event, 'member_id' => $member, 'team_season_id' => $teamSeason, 'draft_laps' => 8, 'final_laps' => 8, 'approved_at' => now(), 'approved_by' => $this->actor->id]);
        $this->pledge = $this->insert('sponsor_run_pledges', ['event_id' => $event, 'participant_id' => $participant, 'sponsor_name' => 'Synthetic Sponsor', 'payment_mode' => 'invoice', 'amount_type' => 'per_lap', 'amount_cents' => 250, 'cap_cents' => 1800, 'recipient_type' => 'person', 'recipient_encrypted' => Crypt::encryptString(json_encode(['company' => '', 'first_name' => 'Synthetic', 'last_name' => 'Sponsor', 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Sissach', 'country' => 'CH', 'email' => 'never-send@example.invalid'])), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_by' => $this->actor->id]);
        $this->mock(BexioConnectionService::class, fn ($m) => $m->shouldReceive('accessToken')->andReturn('fake-only'));
        $this->mock(BexioSandboxFixtureService::class, function ($m) {
            $m->shouldReceive('connectionHash')->andReturn(str_repeat('a', 64));
            $m->shouldReceive('optionalConnectionHash')->andReturn(str_repeat('a', 64));
        });
        $this->client = new FakeSponsorBillingClient;
        $this->app->instance(SponsorRunBexioClient::class, $this->client);
        $this->service = app(SponsorRunBillingService::class);
        $this->config = ['enabled' => true, ...array_fill_keys(array_keys(SponsorRunBillingService::FIELDS), 1), 'mwst_type' => 2, 'due_days' => 30];
        $this->service->saveConfiguration($this->actor, $this->tenant, $this->config);
        $this->actingAs($this->actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function action(int $id, string $action, array $input = []): object
    {
        $row = $this->service->get($this->actor, $this->tenant, $id);

        return $this->service->execute($this->actor, $this->tenant, $id, $row->revision, $action, $input);
    }

    private function rejects(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP rejection');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_snapshot_capped_amount_replay_and_complete_provider_cycle(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->assertSame($id, $this->service->prepare($this->actor, $this->tenant, $this->pledge));
        $this->assertSame(1800, $this->service->get($this->actor, $this->tenant, $id)->total_cents);
        $this->assertSame('contact_ready', $this->action($id, 'create-contact')->state);
        $this->assertSame('draft', $this->action($id, 'create-draft')->state);
        $this->assertSame('issued', $this->action($id, 'issue')->state);
        $this->assertSame('sent', $this->action($id, 'send')->state);
        $send = collect($this->client->writes)->first(fn ($x) => str_ends_with($x[0], '/send'));
        $this->assertSame('admin@svsissach.ch', $send[1]['recipient_email']);
        $this->assertStringContainsString('[Network Link]', $send[1]['message']);
        $this->assertCount(4, $this->client->writes);
        $this->client->invoice['kb_item_status_id'] = 9;
        $this->client->invoice['total_received_payments'] = '18.00';
        $paid = $this->action($id, 'sync');
        $this->assertSame('paid', $paid->state);
        $this->assertSame(1800, $paid->paid_cents);
        $this->rejects(409, fn () => $this->action($id, 'cancel'));
        $this->assertCount(4, $this->client->writes);
        Mail::assertNothingSent();
    }

    public function test_ambiguous_creation_reconciles_without_second_write(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'create-contact');
        $this->client->uncertain = true;
        $this->rejects(409, fn () => $this->action($id, 'create-draft'));
        $this->assertSame('draft_uncertain', $this->service->get($this->actor, $this->tenant, $id)->state);
        $work = app(SyncWorkService::class)->table()->where('tenant_id', $this->tenant->id)->where('state', 'attention')->first();
        $this->assertNotNull($work);
        $result = app(SyncWorkService::class)->retry($this->actor, $this->tenant, $work->id, $work->revision);
        $this->assertSame('draft', $result->state);
        $this->assertCount(2, $this->client->writes);
    }

    public function test_changed_config_blocks_writes_but_historical_pdf_still_opens(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'create-contact');
        $this->action($id, 'create-draft');
        $this->service->saveConfiguration($this->actor, $this->tenant, array_replace($this->config, ['enabled' => false]));
        $this->rejects(409, fn () => $this->action($id, 'issue'));
        $response = $this->service->pdf($this->actor, $this->tenant, $id);
        $this->assertSame('%PDF-1.4 fake', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_revoked_admin_foreign_tenant_and_stale_revision_cannot_write(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->rejects(409, fn () => $this->service->execute($this->actor, $this->tenant, $id, 99, 'create-contact'));
        $this->assertCount(0, $this->client->writes);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $this->rejects(403, fn () => $this->action($id, 'create-contact'));
        $other = Tenant::create(['name' => 'Other synthetic', 'slug' => 'other-'.Str::random(14)]);
        $this->rejects(403, fn () => $this->service->get($this->actor, $other, $id));
        $this->assertCount(0, $this->client->writes);
    }

    public function test_contact_change_blocks_invoice_and_snapshot_refresh_keeps_replay_identity(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'refresh-preview');
        $this->action($id, 'create-contact');
        $this->client->contact['street_name'] = 'Wrong address';
        $this->rejects(409, fn () => $this->action($id, 'create-draft'));
        $this->assertCount(1, $this->client->writes);
        $this->assertSame($id, $this->service->prepare($this->actor, $this->tenant, $this->pledge));
    }

    public function test_billing_page_renders_with_filters_and_modal(): void
    {
        $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        Livewire::test(SponsorRunBilling::class)->assertSuccessful()->assertSee('Vorbereitet')->set('state', 'paid')->assertSee('Keine vorbereiteten Sponsorenrechnungen.')->set('state', '')->mountAction('configure')->assertActionMounted('configure');
    }

    public function test_open_status_is_not_an_email_delivery_proof_and_manual_evidence_is_encrypted(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'create-contact');
        $this->action($id, 'create-draft');
        $this->action($id, 'issue');
        $this->service->table($this->tenant)->where('id', $id)->update(['state' => 'send_uncertain']);
        $this->assertSame('send_uncertain', $this->action($id, 'sync')->state);
        $this->assertSame('sent', $this->action($id, 'confirm-sent', ['reason' => 'Synthetic dispatch checked manually'])->state);
        $audit = $this->db->table('clubora.sponsor_run_billing_audit')->where('invoice_id', $id)->where('action', 'confirm-sent')->sole();
        $this->assertSame('Synthetic dispatch checked manually', Crypt::decryptString($audit->note_encrypted));
        $this->assertCount(3, $this->client->writes);
    }

    public function test_explicit_provider_rejection_preserves_correctable_state(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->client->reject = true;
        $this->rejects(422, fn () => $this->action($id, 'create-contact'));
        $this->assertSame('prepared', $this->service->get($this->actor, $this->tenant, $id)->state);
        $this->client->reject = false;
        $this->assertSame('contact_ready', $this->action($id, 'create-contact')->state);
        $this->assertCount(2, $this->client->writes);
    }

    public function test_crashed_send_stays_uncertain_after_open_invoice_reconciliation(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'create-contact');
        $this->action($id, 'create-draft');
        $this->action($id, 'issue');
        $this->service->table($this->tenant)->where('id', $id)->update(['last_operation' => 'send', 'operation_token' => (string) Str::uuid(), 'lease_until' => now()->subMinutes(10)->toIso8601String()]);
        $this->assertSame('send_uncertain', $this->action($id, 'sync')->state);
        $this->rejects(409, fn () => $this->action($id, 'send'));
        $this->assertCount(3, $this->client->writes);
    }

    public function test_crashed_creation_without_visible_remote_result_never_reopens_creation(): void
    {
        $id = $this->service->prepare($this->actor, $this->tenant, $this->pledge);
        $this->action($id, 'create-contact');
        $this->service->table($this->tenant)->where('id', $id)->update(['last_operation' => 'create-draft', 'operation_token' => (string) Str::uuid(), 'lease_until' => now()->subMinutes(10)->toIso8601String()]);
        $this->rejects(409, fn () => $this->action($id, 'sync'));
        $this->assertSame('draft_uncertain', $this->service->get($this->actor, $this->tenant, $id)->state);
        $this->rejects(409, fn () => $this->action($id, 'create-draft'));
        $this->assertCount(1, $this->client->writes);
    }
}

class FakeSponsorBillingClient extends SponsorRunBexioClient
{
    public array $writes = [];

    public array $contact = [];

    public array $invoice = [];

    public bool $uncertain = false;

    public bool $reject = false;

    public function verify(string $token): void {}

    public function catalog(string $token, string $kind): array
    {
        return [['id' => 1, 'name' => $kind === 'currencies' ? 'CHF' : 'Synthetic', 'iso_3166_alpha2' => 'CH']];
    }

    public function findContact(string $token, string $reference): ?array
    {
        return $this->contact ?: null;
    }

    public function findInvoice(string $token, string $reference): ?array
    {
        return $this->invoice ?: null;
    }

    public function pdf(string $token, int $id): string
    {
        return '%PDF-1.4 fake';
    }

    public function read(string $token, string $path, array $query = []): array
    {
        return str_contains($path, 'contact') ? $this->contact : $this->invoice;
    }

    public function write(string $token, string $path, array $payload = []): array
    {
        $this->writes[] = [$path, $payload];
        if ($this->reject) {
            return ['state' => 'rejected', 'id' => null];
        }
        if ($path === '/2.0/contact') {
            $this->contact = ['id' => 77] + $payload;

            return ['state' => 'accepted', 'id' => 77];
        }
        if ($path === '/2.0/kb_invoice') {
            $this->invoice = ['id' => 88, 'kb_item_status_id' => 7, 'total' => $payload['positions'][0]['unit_price'], 'total_received_payments' => '0.00', 'total_credit_vouchers' => '0.00'] + $payload;

            return ['state' => $this->uncertain ? 'uncertain' : 'accepted', 'id' => 88];
        }
        if (str_ends_with($path, '/issue')) {
            $this->invoice['kb_item_status_id'] = 8;
        }
        if (str_ends_with($path, '/cancel')) {
            $this->invoice['kb_item_status_id'] = 19;
        }

        return ['state' => 'accepted', 'id' => null];
    }
}
