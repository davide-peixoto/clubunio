<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\Synchronisation;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\StatusStyleService;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Facades\Filament;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SynchronisationTableTest extends TestCase
{
    private bool $allowed = true;

    private array $queries = [];

    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $actor = (new Account)->forceFill(['id' => 12, 'active' => true, 'display_name' => 'Sync fixture']);
        $this->actingAs($actor, 'clubunio');
        $this->mock(StatusStyleService::class)->shouldReceive('colors')->andReturn([]);
        Filament::setTenant((new Tenant)->forceFill(['id' => 42, 'slug' => 'sync-fixture', 'name' => 'Sync fixture']));
        $readContext = $this->mock(BackofficeReadContext::class);
        $readContext->shouldReceive('withoutSnapshot', 'duringRender')->andReturnUsing(fn ($callback) => $callback());
        $readContext->shouldReceive('allowsReadPage')
            ->withArgs(fn ($actor, $tenant, $adminOnly) => $actor->id === 12 && $tenant->id === 42 && $adminOnly)
            ->andReturnUsing(fn () => $this->allowed);
        $this->app->instance(ModulePermissionService::class, new class
        {
            public function isProfile(...$arguments): bool
            {
                return false;
            }
        });
        $service = $this->partialMock(SyncWorkService::class);
        $service->shouldReceive('authorize')->andReturnUsing(fn () => abort_unless($this->allowed, 403));
        $service->shouldReceive('retry', 'close')->never()->byDefault();
        $service->shouldNotReceive('importOutstanding', 'run');
        for ($id = 1; $id <= 65; $id++) {
            $operation = [2 => 'training_review', 3 => 'invoice_transfer', 4 => 'expense_status', 5 => 'azure_upload', 7 => 'sfv_schedule'][$id] ?? 'catalog';
            $payload = match ($operation) {
                'sfv_schedule' => ['from' => '2026-11-02', 'to' => '2026-11-10'],
                'training_review' => ['input' => ['goal' => 'Synthetic training goal']],
                'azure_upload' => ['kind' => 'member', 'owner_id' => 73],
                default => null,
            };
            $this->items[$id] = (object) ['id' => $id, 'operation' => $operation, 'object_id' => 70 + $id,
                'state' => [2 => 'attention', 3 => 'running', 4 => 'running', 5 => 'succeeded', 6 => 'closed'][$id] ?? 'pending',
                'attempts' => 3, 'error_code' => $id === 2 ? 'status_503' : null, 'revision' => 4,
                'lease_until' => $id === 3 ? now()->addHour()->toIso8601String() : now()->subHour()->toIso8601String(),
                'updated_at' => '2026-11-02 16:00:00+00',
                'encrypted_payload' => $payload === null ? null : Crypt::encryptString(json_encode($payload))];
        }
        $connection = Mockery::mock(PostgresConnection::class.'[select,insert,update,delete,statement,affectingStatement]', [null, 'fixture', '', []]);
        $connection->shouldReceive('insert', 'update', 'delete', 'statement', 'affectingStatement')->never();
        $connection->shouldReceive('select')->andReturnUsing(function ($sql, $bindings) {
            $this->queries[] = [$sql, $bindings];
            if (str_contains($sql, 'sync_work_events')) {
                if (str_contains($sql, 'count(*)')) {
                    return [(object) ['aggregate' => 45]];
                }
                preg_match('/offset (\d+)/', $sql, $offset);
                $start = (int) ($offset[1] ?? 0);

                return array_map(fn ($id) => (object) ['id' => $id, 'actor_id' => $id % 2 ? 12 : null,
                    'event' => 'attention', 'code' => 'status_503', 'created_at' => '2026-11-02 16:00:00+00',
                    'encrypted_note' => Crypt::encryptString('History note '.$id)], range(45 - $start, max(1, 26 - $start)));
            }
            if (! str_contains($sql, 'sync_work_items')) {
                throw new \RuntimeException('Unexpected read: '.$sql);
            }
            if (str_contains($sql, 'group by')) {
                return collect($this->items)->groupBy('state')->map(fn ($rows, $state) => (object) ['state' => $state, 'total' => $rows->count()])->values()->all();
            }
            $rows = collect($this->items);
            if (str_contains($sql, '"state" in')) {
                $rows = $rows->whereIn('state', ['pending', 'attention', 'running']);
            }
            foreach (['succeeded', 'closed'] as $state) {
                if (in_array($state, $bindings, true)) {
                    $rows = $rows->where('state', $state);
                }
            }
            if (str_contains($sql, '"operation" = ?')) {
                $rows = $rows->where('operation', end($bindings));
            }
            if (str_contains($sql, '"operation" not in')) {
                $rows = $rows->whereNotIn('operation', ['sfv_schedule', 'training_review', 'azure_upload']);
            }
            if (str_contains($sql, '"id" = ?')) {
                $rows = $rows->where('id', end($bindings));
            }
            if (in_array('%missing%', $bindings, true)) {
                $rows = collect();
            }
            if (str_contains($sql, 'select exists')) {
                return [(object) ['exists' => $rows->isNotEmpty()]];
            }
            if (str_contains($sql, 'count(*)')) {
                return [(object) ['aggregate' => $rows->count()]];
            }
            preg_match('/offset (\d+)/', $sql, $offset);
            preg_match('/limit (\d+)/', $sql, $limit);

            return $rows->slice((int) ($offset[1] ?? 0), (int) ($limit[1] ?? 20))->values()->all();
        });
        DB::extend('sync-table-fixture', fn () => $connection);
        config(['database.default' => 'clubunio', 'database.connections.clubunio' => ['driver' => 'sync-table-fixture']]);
        DB::purge('clubunio');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Filament::setTenant(null);
        DB::purge('clubunio');
        parent::tearDown();
    }

    public function test_native_list_scopes_projection_search_sort_pagination_and_counts(): void
    {
        $page = Livewire::test(Synchronisation::class)->assertSuccessful()
            ->assertSee('Bexio · Artikel und Steuern')->assertSee('Zielsystem nicht verfügbar')
            ->assertSee('Synthetic training goal')->assertSee('2026-11-10')
            ->assertSee('Offen (63)')->assertSee('Manuell abgeschlossen (1)');
        $this->assertSame(20, $page->viewData('rows')->count());
        $page->call('gotoPage', 2)->assertSee('Auftrag 23');
        $page->sortTable('attempts', 'asc')->searchTable('missing')->assertSee('Keine Aufträge');
        $queries = collect($this->queries)->filter(fn ($query) => str_contains($query[0], 'sync_work_items'));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query[0], 'offset 20')));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query[0], '"attempts" asc')));
        $this->assertTrue($queries->contains(fn ($query) => in_array('%missing%', $query[1], true)));
        foreach ($queries as [$sql, $bindings]) {
            $this->assertContains(42, $bindings);
            $this->assertStringContainsString('"tenant_id" = ?', $sql);
            foreach (['lease_token', 'connection_hash', 'deduplication_key'] as $secret) {
                $this->assertStringNotContainsString($secret, $sql);
            }
        }
        $this->assertFalse(collect($this->queries)->contains(fn ($query) => str_contains($query[0], 'sync_work_events')));
    }

    public function test_native_and_existing_filters_reset_history_and_preserve_provider_groups(): void
    {
        $page = Livewire::test(Synchronisation::class)->call('showHistory', 7)->assertSee('History note 45');
        $page->filterTable('provider', 'sfv')->assertSet('provider', 'sfv')->assertSet('historyItemId', null)
            ->assertSee('SFV · Spielplan abrufen')->assertDontSee('Bexio · Artikel und Steuern');
        $page->set('provider', 'bexio')->assertSee('Bexio · Artikel und Steuern')->assertDontSee('SFV · Spielplan abrufen');
        $page->set('provider', 'all')->filterTable('state', 'closed')->assertSet('state', 'closed')
            ->assertSee('Auftrag 6')->assertDontSee('Auftrag 1 ·');
        $page->call('resetTableFiltersForm')->assertSet('state', 'open')->assertSet('provider', 'all');
    }

    public function test_row_actions_keep_retry_lease_rules_and_document_links_without_execution(): void
    {
        $page = Livewire::test(Synchronisation::class)->filterTable('state', 'all');
        $page->assertTableActionVisible('sync', '1')->assertTableActionHidden('sync', '2')
            ->assertTableActionHidden('sync', '3')->assertTableActionVisible('sync', '4')
            ->assertTableActionHidden('sync', '5')->assertTableActionHidden('sync', '6')
            ->assertTableActionVisible('closeItem', '1')->assertTableActionHidden('closeItem', '4')
            ->assertTableActionVisible('view', '3')->assertTableActionVisible('details', '4')
            ->mountTableAction('sync', '1')->assertSet('mountedActions.0.name', 'sync');
    }

    public function test_history_is_lazy_encrypted_and_independently_paginated_then_closed_on_list_change(): void
    {
        $page = Livewire::test(Synchronisation::class)->assertDontSee('History note')
            ->callTableAction('history', '1')->assertSee('History note 45')->assertSee('Öffentliche Anmeldung');
        $this->assertSame(45, $page->viewData('history')->total());
        $page->call('gotoPage', 2, 'historyPage')->assertSee('History note 25')->assertDontSee('History note 45')
            ->assertSet('paginators.page', 1)->call('gotoPage', 2)->assertSet('historyItemId', null);
        $page->call('gotoPage', 1)->call('showHistory', 1)->searchTable('missing')->assertSet('historyItemId', null);
    }

    public function test_foreign_history_and_revoked_admin_access_are_rejected(): void
    {
        Livewire::test(Synchronisation::class)->call('showHistory', 999)->assertNotFound();
        $page = Livewire::test(Synchronisation::class)->call('showHistory', 1);
        $this->allowed = false;
        $page->call('gotoPage', 2, 'historyPage')->assertForbidden();
        Livewire::test(Synchronisation::class)->assertForbidden();
    }

    public function test_history_selection_is_locked(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Synchronisation::class)->set('historyItemId', 999);
    }

    public function test_confirmation_keeps_reviewed_revision_when_record_changes(): void
    {
        $this->partialMock(SyncWorkService::class)->shouldReceive('retry')->once()
            ->withArgs(fn ($actor, $tenant, $id, $revision) => $id === 1 && $revision === 4)->andReturn(0);
        $page = Livewire::test(Synchronisation::class)->mountTableAction('sync', '1')
            ->assertSet('reviewedItem', ['id' => 1, 'revision' => 4, 'action' => 'sync']);
        $this->items[1]->revision = 5;
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
    }

    public function test_manual_closure_requires_note_and_keeps_reviewed_revision(): void
    {
        $this->partialMock(SyncWorkService::class)->shouldReceive('close')->once()
            ->withArgs(fn ($actor, $tenant, $id, $revision, $note) => $id === 1 && $revision === 4 && $note === 'Synthetic closure reason')
            ->andReturnNull();
        $page = Livewire::test(Synchronisation::class)->mountTableAction('closeItem', '1')
            ->callMountedTableAction()->assertHasTableActionErrors(['note' => 'required']);
        $this->items[1]->revision = 5;
        $page->setTableActionData(['note' => 'Synthetic closure reason'])
            ->callMountedTableAction()->assertHasNoTableActionErrors()->assertSet('note', '');
    }
}
