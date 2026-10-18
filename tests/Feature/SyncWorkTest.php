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
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    public function test_history_loads_on_demand_and_all_older_notes_remain_reachable(): void
    {
        $id = app(SyncWorkService::class)->remember($this->actor, $this->tenant, 'catalog', 1, []);
        $rows = [];
        for ($i = 0; $i < 45; $i++) {
            $rows[] = ['work_item_id' => $id, 'actor_id' => $this->actor->id, 'event' => 'attention', 'code' => 'status_503',
                'encrypted_note' => Crypt::encryptString(sprintf('History entry %03d', $i))];
        }
        $this->db->table('clubora.sync_work_events')->insert($rows);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        try {
            $page = Livewire::test(Synchronisation::class)->assertSuccessful()->assertDontSee('History entry');
            $queries = collect($this->db->getQueryLog())->pluck('query');
        } finally {
            $this->db->disableQueryLog();
        }
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'sync_work_events')));
        $page->call('showHistory', $id)->assertSuccessful()->assertSee('History entry 044')->assertDontSee('History entry 024');
        $this->assertSame(20, $page->viewData('history')->count());
        $this->assertSame(46, $page->viewData('history')->total());
        $page->call('gotoPage', 2, 'historyPage')->assertSee('History entry 024')->assertDontSee('History entry 044');
        $page->call('gotoPage', 3, 'historyPage')->assertSee('History entry 000')->assertSee('Auftrag gespeichert');
        $page->call('closeHistory')->assertSet('historyItemId', null)->assertDontSee('History entry');
        $this->assertSame(46, $this->db->table('clubora.sync_work_events')->where('work_item_id', $id)->count());
        $this->assertSame(1, app(SyncWorkService::class)->table()->find($id)->revision);
        Http::assertNothingSent();
    }

    public function test_history_switch_and_filters_reset_only_read_selection(): void
    {
        $service = app(SyncWorkService::class);
        $first = $service->remember($this->actor, $this->tenant, 'catalog', 1, []);
        $second = $service->remember($this->actor, $this->tenant, 'sfv_schedule', 2, ['from' => '2026-10-15', 'to' => '2026-10-16']);
        $this->db->table('clubora.sync_work_events')->insert(['work_item_id' => $second, 'actor_id' => null, 'event' => 'attention',
            'code' => 'status_503', 'encrypted_note' => Crypt::encryptString('SFV history only')]);
        $page = Livewire::test(Synchronisation::class)->assertSee('2026-10-15')->call('showHistory', $first);
        $page->call('showHistory', $second)->assertSet('historyItemId', $second)->assertSee('SFV history only')->assertSee('Öffentliche Anmeldung');
        $page->set('provider', 'bexio')->assertSet('historyItemId', null)->assertDontSee('SFV history only')->assertDontSee('SFV · Spielplan abrufen');
        $page->set('provider', 'all')->call('showHistory', $second)->set('state', 'closed')->assertSet('historyItemId', null)->assertDontSee('SFV history only');
    }

    public function test_list_does_not_read_unused_provider_payload_or_technical_tokens(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', 1, ['fixture' => 'Provider payload not for display']);
        $service->table()->where('id', $id)->update(['encrypted_payload' => 'Not even a valid ciphertext', 'lease_token' => Str::uuid()]);
        $page = Livewire::test(Synchronisation::class)->assertSuccessful()->assertSee('Bexio · Artikel und Steuern');
        $row = $page->viewData('rows')->first();
        $this->assertNull($row->encrypted_payload);
        $this->assertFalse(property_exists($row, 'lease_token'));
        $this->assertFalse(property_exists($row, 'connection_hash'));
        $this->assertFalse(property_exists($row, 'deduplication_key'));
    }

    public function test_foreign_history_is_not_accessible_and_role_revocation_blocks_paging(): void
    {
        $service = app(SyncWorkService::class);
        $id = $service->remember($this->actor, $this->tenant, 'catalog', 1, []);
        $other = Tenant::create(['slug' => 'history-other-'.Str::random(10), 'name' => 'Synthetic']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $foreign = $service->remember($this->actor, $other, 'catalog', 2, []);
        Livewire::test(Synchronisation::class)->call('showHistory', $foreign)->assertNotFound();
        $page = Livewire::test(Synchronisation::class)->call('showHistory', $id)->assertSuccessful();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $page->call('gotoPage', 2, 'historyPage')->assertForbidden();
    }

    public function test_main_page_change_closes_history_and_locked_selection_rejects_client_input(): void
    {
        $id = app(SyncWorkService::class)->remember($this->actor, $this->tenant, 'catalog', 1, []);
        $page = Livewire::test(Synchronisation::class)->call('showHistory', $id);
        $page->call('gotoPage', 2)->assertSet('historyItemId', null)->assertDontSee('Auftrag gespeichert');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('historyItemId', $id);
    }
}
