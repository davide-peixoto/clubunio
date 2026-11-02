<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\DocumentSeries as Series;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\DocumentSeries;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\DocumentSeriesRenderer;
use App\ClubUnio\Services\DocumentSeriesService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentSeriesHistoryTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These fixtures exist only in an attached in-memory SQLite database.
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->statement("ATTACH DATABASE ':memory:' AS clubora");
        $schema = Schema::connection('clubunio');
        $schema->create('clubora.document_series_templates', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id');
            $table->string('name');
            $table->integer('version');
        });
        $schema->create('clubora.document_series', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id');
            $table->integer('template_id');
            $table->text('snapshot');
            $table->string('snapshot_hash');
            $table->timestamps();
        });
        $schema->create('clubora.document_series_recipients', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id');
            $table->integer('series_id');
            $table->integer('document_id')->nullable();
        });

        $tenant = (new Tenant)->forceFill(['id' => 1, 'slug' => 'test', 'name' => 'Test']);
        $actor = (new Account)->forceFill(['id' => 1, 'active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($actor, 'clubunio');
        Filament::setTenant($tenant);
        $this->mock(BackofficeReadContext::class, function ($mock) use ($tenant) {
            $mock->makePartial()->shouldReceive('tenantsFor')->andReturn(collect([$tenant]));
            $mock->shouldReceive('duringRender')->andReturnUsing(fn ($next) => $next());
        });
        $this->mock(DocumentSeriesService::class, function ($mock) {
            $mock->makePartial()->shouldReceive('authorize')->andReturnNull();
        });

        DB::connection('clubunio')->table('clubora.document_series_templates')->insert([
            ['id' => 1, 'tenant_id' => 1, 'name' => 'Material 100%', 'version' => 1],
            ['id' => 2, 'tenant_id' => 1, 'name' => 'Keys', 'version' => 2],
            ['id' => 3, 'tenant_id' => 2, 'name' => 'Private Other Club', 'version' => 1],
        ]);
        foreach (range(1, 31) as $id) {
            $series = new Series;
            $series->forceFill(['id' => $id, 'tenant_id' => $id === 31 ? 2 : 1,
                'template_id' => $id === 31 ? 3 : ($id <= 15 ? 1 : 2),
                'snapshot' => [], 'snapshot_hash' => DocumentSeriesRenderer::hash([])])->save();
        }
        DB::connection('clubunio')->table('clubora.document_series_recipients')->insert([
            ['tenant_id' => 1, 'series_id' => 30, 'document_id' => 100],
            ['tenant_id' => 1, 'series_id' => 30, 'document_id' => null],
            ['tenant_id' => 2, 'series_id' => 30, 'document_id' => 101],
        ]);
    }

    public function test_history_is_native_paginated_sorted_and_tenant_scoped(): void
    {
        $component = Livewire::test(DocumentSeriesHistoryFixture::class)
            ->assertSeeHtml('fi-ta')
            ->assertCountTableRecords(30)
            ->assertCanSeeTableRecords([30, 29, 28], inOrder: true)
            ->assertCanNotSeeTableRecords([1, 31])
            ->assertDontSee('Private Other Club')
            ->call('setPage', 2)
            ->assertStatus(200)
            ->assertCanSeeTableRecords([5, 4, 3, 2, 1], inOrder: true)
            ->assertCanNotSeeTableRecords([30, 31]);

        $component->sortTable('id', 'asc')->assertCanSeeTableRecords([1, 2, 3], inOrder: true);
    }

    public function test_native_search_filter_and_reset_use_all_records(): void
    {
        $component = Livewire::test(DocumentSeriesHistoryFixture::class)
            ->call('setPage', 2)
            ->searchTable('keys')
            ->assertSet('paginators.page', 1)
            ->assertCountTableRecords(15)
            ->assertCanSeeTableRecords([30, 16])
            ->assertCanNotSeeTableRecords([15, 31])
            ->searchTable('100%')->assertCountTableRecords(15)
            ->searchTable('%')->assertCountTableRecords(15)
            ->searchTable('no match')->assertCountTableRecords(0)
            ->assertSee('Keine Serien gefunden.')
            ->searchTable('')->assertCountTableRecords(30)
            ->filterTable('template_id', 2)->assertCountTableRecords(15)
            ->removeTableFilters()->assertCountTableRecords(30);

        $component->filterTable('template_id', 3)->assertCountTableRecords(0);
    }

    public function test_open_action_uses_guarded_service_and_counts_only_own_recipients(): void
    {
        $component = Livewire::test(DocumentSeriesHistoryFixture::class);
        $row = $component->instance()->getTableRecords()->first();
        $this->assertSame(2, (int) $row->recipients_count);
        $this->assertSame(1, (int) $row->completed_count);
        $action = $component->instance()->getTable()->getAction('openSeries')->record($row);
        $this->assertFalse($action->isHidden());
        $this->assertSame('$wire.openSeries(30)', $action->getAlpineClickHandler());
        $component->call('openSeries', 30)->assertSet('seriesId', 30);
    }

    public function test_open_series_keeps_tenant_and_snapshot_guards(): void
    {
        Livewire::test(DocumentSeriesHistoryFixture::class)->call('openSeries', 31)->assertNotFound();
        DB::connection('clubunio')->table('clubora.document_series')->where('id', 30)->update(['snapshot_hash' => 'invalid']);
        Livewire::test(DocumentSeriesHistoryFixture::class)->call('openSeries', 30)->assertStatus(409);
    }

    public function test_history_rechecks_read_authorization(): void
    {
        $this->mock(DocumentSeriesService::class, fn ($mock) => $mock->shouldReceive('authorize')->andReturnUsing(fn () => abort(403)));
        Livewire::test(DocumentSeriesHistoryFixture::class)->assertForbidden();
    }
}

class DocumentSeriesHistoryFixture extends DocumentSeries
{
    public function mount(): void {}

    protected function getViewData(): array
    {
        return ['lookups' => ['templates' => [], 'teams' => []], 'filteredCandidates' => collect(),
            'previewHtml' => '', 'recipients' => collect()];
    }
}
