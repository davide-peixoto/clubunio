<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioDispatchTrials;
use App\ClubUnio\Pages\BexioSandbox;
use App\ClubUnio\Pages\Synchronisation;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioInvoiceTransferService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SyncWorkTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'sync-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(15).'@example.invalid', 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->mock(BexioSandboxFixtureService::class, fn ($m) => $m->shouldReceive('optionalConnectionHash')->andReturn('synthetic'));
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_failed_sfv_read_survives_and_retry_keeps_same_item_and_history(): void
    {
        $input = ['from' => '2026-10-15', 'to' => '2026-10-20'];
        $this->mock(SfvPlanningService::class, fn ($m) => $m->shouldReceive('refreshNow')->once()->andThrow(new \RuntimeException('secret-token-do-not-store', 503)));
        $service = app(SyncWorkService::class);
        try {
            $service->run($this->actor, $this->tenant, 'sfv_schedule', $this->tenant->id, $input);
            $this->fail('Expected provider failure');
        } catch (\RuntimeException) {
        }
        $row = $service->table()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('attention', $row->state);
        $this->assertSame('status_503', $row->error_code);
        $this->assertStringNotContainsString('2026-10-15', $row->encrypted_payload);
        $this->assertSame($input, json_decode(Crypt::decryptString($row->encrypted_payload), true));
        $this->mock(SfvPlanningService::class, fn ($m) => $m->shouldReceive('refreshNow')->once()->withArgs(fn ($a, $t, $p) => $p === $input)->andReturn(4));
        $this->assertSame(4, $service->retry($this->actor, $this->tenant, $row->id, $row->revision));
        $fresh = $service->table()->find($row->id);
        $this->assertSame('succeeded', $fresh->state);
        $this->assertSame(2, $fresh->attempts);
        $this->assertSame(1, $service->table()->where('tenant_id', $this->tenant->id)->count());
        $history = $this->db->table('clubora.sync_work_events')->where('work_item_id', $row->id)->get()->toJson();
        $this->assertStringNotContainsString('secret-token', $history);
        $this->assertStringContainsString('status_503', $history);
    }

    public function test_active_lease_and_stale_revision_block_parallel_execution(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', $this->tenant->id, []);
        $service->table()->where('id', $id)->update(['state' => 'running', 'lease_until' => now()->addMinute()->toIso8601String()]);
        $this->mock(BexioCatalogService::class, fn ($m) => $m->shouldNotReceive('syncNow'));
        try {
            $service->retry($this->actor, $this->tenant, $id, 1);
            $this->fail('Lease must block');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->table()->where('id', $id)->update(['state' => 'attention', 'revision' => 2]);
        $this->expectException(HttpException::class);
        $service->retry($this->actor, $this->tenant, $id, 1);
    }

    public function test_changed_connection_is_not_replayed(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', $this->tenant->id, []);
        $service->table()->where('id', $id)->update(['connection_hash' => 'different']);
        $this->mock(BexioCatalogService::class, fn ($m) => $m->shouldNotReceive('syncNow'));
        $this->expectException(HttpException::class);
        $service->retry($this->actor, $this->tenant, $id, 1);
    }

    public function test_void_or_unconfirmed_provider_result_is_not_success(): void
    {
        $this->mock(BexioInvoiceTransferService::class, fn ($m) => $m->shouldReceive('transferNow')->once()->andReturn((object) ['state' => 'uncertain']));
        $service = app(SyncWorkService::class);
        $service->run($this->actor, $this->tenant, 'invoice_transfer', 77, ['revision' => 1]);
        $row = $service->table()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('attention', $row->state);
        $this->assertSame('provider_unconfirmed', $row->error_code);
    }

    public function test_manual_closure_is_audited_encrypted_and_not_provider_confirmation(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', $this->tenant->id, []);
        $service->close($this->actor, $this->tenant, $id, 1, 'Fachlich geprüft, Aufgabe ersetzt.');
        $this->assertSame('closed', $service->table()->find($id)->state);
        $event = $this->db->table('clubora.sync_work_events')->where('work_item_id', $id)->orderByDesc('id')->first();
        $this->assertSame('closed_manually', $event->event);
        $this->assertSame('Fachlich geprüft, Aufgabe ersetzt.', Crypt::decryptString($event->encrypted_note));
        $this->expectException(HttpException::class);
        $service->retry($this->actor, $this->tenant, $id, 2);
    }

    public function test_office_foreign_tenant_and_disabled_actor_cannot_retry(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', $this->tenant->id, []);
        $other = Tenant::create(['slug' => 'other-'.Str::random(10), 'name' => 'Synthetic']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        try {
            $service->retry($this->actor, $other, $id, 1);
            $this->fail('Foreign item');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        try {
            $service->retry($this->actor, $this->tenant, $id, 1);
            $this->fail('Office must not write integrations');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actor->forceFill(['active' => false])->save();
        $this->expectException(HttpException::class);
        $service->run($this->actor, $this->tenant, 'catalog', $this->tenant->id);
    }

    public function test_queue_page_renders_and_test_pages_are_not_navigation_items(): void
    {
        app(SyncWorkService::class)->remember($this->actor, $this->tenant, 'sfv_schedule', $this->tenant->id, ['from' => '2026-10-15', 'to' => '2026-10-16']);
        Livewire::test(Synchronisation::class)->assertSuccessful()->assertSee('SFV · Spielplan abrufen')->assertSee('Erneut prüfen');
        $this->assertFalse(BexioSandbox::shouldRegisterNavigation());
        $this->assertFalse(BexioDispatchTrials::shouldRegisterNavigation());
    }

    public function test_historical_latest_errors_import_idempotently_without_external_calls(): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'object_type' => 'invoice',
            'object_id' => 91, 'remote_id' => '1', 'fingerprint' => 'synthetic'];
        $this->db->table('clubora.bexio_checks')->insert($base + ['state' => 'failed', 'reference' => Str::uuid()]);
        $service = app(SyncWorkService::class);
        $service->importOutstanding($this->actor, $this->tenant);
        $service->importOutstanding($this->actor, $this->tenant);
        $this->assertSame(1, $service->table()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('invoice_status', $service->table()->where('tenant_id', $this->tenant->id)->sole()->operation);
        // An already recovered historical failure must not introduce another item.
        $this->db->table('clubora.bexio_checks')->insert(array_replace($base, ['object_id' => 92, 'state' => 'failed', 'reference' => Str::uuid()]));
        $this->db->table('clubora.bexio_checks')->insert(array_replace($base, ['object_id' => 92, 'state' => 'unchanged', 'reference' => Str::uuid()]));
        $service->importOutstanding($this->actor, $this->tenant);
        $this->assertSame(1, $service->table()->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_expired_worker_lease_recovers_read_with_same_item(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', $this->tenant->id, []);
        $service->table()->where('id', $id)->update(['state' => 'running', 'lease_until' => now()->subMinute()->toIso8601String(), 'lease_token' => Str::uuid()]);
        $this->mock(BexioCatalogService::class, fn ($m) => $m->shouldReceive('syncNow')->once()->andReturn(5));
        $this->assertSame(5, $service->retry($this->actor, $this->tenant, $id, 1));
        $this->assertSame('succeeded', $service->table()->find($id)->state);
        $this->assertNull($service->table()->find($id)->lease_token);
    }
}
