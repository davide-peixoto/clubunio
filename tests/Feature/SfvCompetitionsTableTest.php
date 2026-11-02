<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\SfvCompetitions;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use Filament\Facades\Filament;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SfvCompetitionsTableTest extends TestCase
{
    private array $queries = [];

    private bool $allowed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $tenant = (new Tenant)->forceFill(['id' => 42, 'slug' => 'table-fixture', 'name' => 'Table fixture']);
        $actor = Mockery::mock(Account::class)->makePartial();
        $actor->forceFill(['id' => 12, 'active' => true, 'display_name' => 'Table fixture']);
        $actor->shouldReceive('fresh')->andReturnSelf();
        $actor->shouldReceive('canAccessTenant')->andReturnUsing(fn () => $this->allowed);
        $actor->shouldReceive('hasUnrestrictedBackofficeAccess')->andReturn(false);
        $this->actingAs($actor, 'clubunio');
        Filament::setTenant($tenant);
        $this->app->instance(ModulePermissionService::class, new class(fn () => $this->allowed)
        {
            public function __construct(private \Closure $allowed) {}

            public function isProfile(...$arguments): bool
            {
                return true;
            }

            public function allows($actor, $tenant, string $module, string $function): bool
            {
                return ($this->allowed)() && $module === 'competitions' && $function === 'view';
            }
        });
        $this->mock(SfvPlanningService::class)->shouldNotReceive('refresh');
        $this->mock(SfvCompetitionService::class)->shouldNotReceive('apply', 'applyBatch');

        $connection = Mockery::mock(PostgresConnection::class.'[select,insert,update,delete,statement,affectingStatement]', [null, 'fixture', '', []]);
        $connection->shouldReceive('select')->andReturnUsing(function ($sql, $bindings) {
            $this->queries[] = [$sql, $bindings];
            if (! str_contains($sql, 'sfv_schedule') || str_contains($sql, '1 = 0') || in_array('%missing%', $bindings, true)) {
                return str_contains($sql, 'count(*)') ? [(object) ['aggregate' => 0]] : [];
            }
            if (str_contains($sql, 'count(*)')) {
                return [(object) ['aggregate' => 525]];
            }
            if (str_contains($sql, '"id" = ?') && in_array('999', $bindings, true)) {
                return [];
            }
            $offset = str_contains($sql, 'offset 500') ? 500 : 0;

            return array_map(fn ($id) => (object) ['id' => $id, 'tenant_id' => 42,
                'starts_at' => '2026-11-02 18:30:00+00', 'title' => 'Fixture game '.$id,
                'location' => 'Fixture pitch', 'state' => 'Angesetzt'], range($offset + 1, $offset + 25));
        });
        $connection->shouldReceive('insert', 'update', 'delete', 'statement', 'affectingStatement')->never();
        DB::extend('sfv-table-fixture', fn () => $connection);
        config(['database.default' => 'clubunio', 'database.connections.clubunio' => ['driver' => 'sfv-table-fixture']]);
        DB::purge('clubunio');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Filament::setTenant(null);
        DB::purge('clubunio');
        parent::tearDown();
    }

    public function test_native_table_search_sort_and_pagination_are_scoped_and_not_capped(): void
    {
        $page = Livewire::test(SfvCompetitions::class)->assertSuccessful()->assertSee('Fixture game 1');
        $page->call('gotoPage', 21)->assertSee('Fixture game 501');
        $page->sortTable('title', 'desc')->searchTable('missing')->assertDontSee('Fixture game 1');
        $queries = collect($this->queries)->filter(fn ($query) => str_contains($query[0], 'sfv_schedule'));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query[0], 'offset 500')));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query[0], '"title" desc')));
        $this->assertTrue($queries->contains(fn ($query) => in_array('%missing%', $query[1], true)));
        foreach ($queries as [$sql, $bindings]) {
            $this->assertStringContainsString('"tenant_id" = ?', $sql);
            $this->assertContains(42, $bindings);
            $this->assertStringContainsString("AT TIME ZONE 'Europe/Zurich'", $sql);
            $this->assertStringNotContainsString('limit 501', $sql);
        }
    }

    public function test_selection_feeds_existing_review_without_applying_or_refreshing(): void
    {
        $this->mock(SfvCompetitionService::class)->shouldReceive('review')->once()
            ->withArgs(fn ($actor, $tenant, $match, $target, $separate) => $tenant->id === 42 && $match === 1 && $target === 77 && $separate)
            ->andReturn([]);
        Livewire::test(SfvCompetitions::class)->set('target', '77')->set('separate', true)
            ->callTableAction('inspect', '1')->assertSet('match', '1')
            ->assertSet('target', '77')->assertSet('separate', true)->call('inspect')->assertHasNoErrors();
    }

    public function test_missing_or_foreign_record_cannot_be_selected_by_mounted_action(): void
    {
        Livewire::test(SfvCompetitions::class)->call('mountTableAction', 'inspect', '999')->assertSet('match', '');
    }

    public function test_preview_invalidation_ignores_table_browsing_but_keeps_workflow_guards(): void
    {
        $page = app(SfvCompetitions::class);
        $page->bootedInteractsWithTable();
        $page->preview = ['fingerprint' => 'review'];
        $page->batchPreview = ['entries' => []];
        foreach (['tableSearch', 'tableSort', 'tableRecordsPerPage', 'paginators.page', 'mountedActions'] as $property) {
            $page->updated($property);
            $this->assertNotNull($page->preview);
            $this->assertNotNull($page->batchPreview);
        }
        foreach (['match', 'target', 'separate', 'from', 'to'] as $property) {
            $page->preview = ['fingerprint' => 'review'];
            $page->batchPreview = ['entries' => []];
            $page->updated($property);
            $this->assertNull($page->preview);
            $this->assertNull($page->batchPreview);
        }
    }

    public function test_invalid_range_is_empty_and_page_access_is_still_enforced(): void
    {
        Livewire::test(SfvCompetitions::class)->set('from', 'invalid')->assertDontSee('Fixture game 1')
            ->call('inspectBatch')->assertHasErrors('from');
        $this->allowed = false;
        Livewire::test(SfvCompetitions::class)->assertForbidden();
    }

    public function test_read_only_profile_can_batch_review_but_cannot_refresh_or_apply(): void
    {
        $this->mock(SfvCompetitionService::class)->shouldReceive('previewBatch')->once()
            ->withArgs(fn ($actor, $tenant, $from, $to) => $tenant->id === 42 && $from <= $to)
            ->andReturn(['entries' => [], 'unchanged' => 3, 'issues' => []]);
        Livewire::test(SfvCompetitions::class)->call('inspectBatch')->assertHasNoErrors()
            ->assertSee('Sammelübernahme')->assertSee('3 unverändert')->assertDontSee('SFV abrufen');
        foreach (['refreshSchedule', 'apply', 'applyBatch'] as $method) {
            Livewire::test(SfvCompetitions::class)->call($method)->assertForbidden();
        }
    }
}
