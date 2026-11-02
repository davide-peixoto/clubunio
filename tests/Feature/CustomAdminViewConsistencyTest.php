<?php

namespace Tests\Feature;

use App\ClubUnio\Pages\DocumentSeries;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Tests\TestCase;

class CustomAdminViewConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant(null);
    }

    public function test_recipient_search_stays_available_when_no_results_match(): void
    {
        Livewire::test(DocumentSeriesViewFixture::class)
            ->assertSee('Example Member')
            ->set('recipientSearch', 'missing')
            ->assertSee('Keine passenden')
            ->assertSeeHtml('wire:model.live.debounce.300ms="recipientSearch"')
            ->assertSeeHtml('wire:click="$set(\'recipientSearch\', \'\')"')
            ->call('$set', 'recipientSearch', '')
            ->assertSee('Example Member');
    }

    public function test_editor_renders_real_components_and_preserves_bindings(): void
    {
        $html = Livewire::test(DocumentSeriesViewFixture::class)
            ->set('editing', true)->html();

        $this->assertStringContainsString('fi-input-wrp', $html);
        $this->assertStringContainsString('fi-section', $html);
        $this->assertStringContainsString('wire:model="editor.items.0.quantity"', $html);
        $this->assertStringContainsString('max="999"', $html);
        $this->assertStringContainsString('value="7"', $html);
        $this->assertStringContainsString('wire:model.live="teams"', $html);
        $this->assertStringContainsString('cu-admin-journal', $html);
        $this->assertStringContainsString('tooltip', $html);
    }

    public function test_history_uses_native_search_and_resets_pagination(): void
    {
        Livewire::test(DocumentSeriesViewFixture::class)
            ->call('setPage', 2)
            ->assertSeeHtml('fi-ta')
            ->set('tableSearch', 'missing')
            ->assertSet('paginators.page', 1)
            ->set('tableSearch', '')
            ->assertSet('paginators.page', 1);
    }

    public function test_season_transfer_uses_shared_journal_and_status_badges(): void
    {
        $html = view('clubunio.season-transfer-preview', ['plan' => ['rows' => [
            ['type' => 'Spieler', 'label' => 'Example', 'state' => 'blockiert', 'reason' => 'Pruefen'],
        ]]])->render();

        $this->assertStringContainsString('cu-admin-journal', $html);
        $this->assertStringContainsString('fi-badge', $html);
        $this->assertStringContainsString('blockiert', $html);
        $this->assertStringNotContainsString('<strong style="color:', $html);
    }
}

// Exercise the production Blade without database access or write operations.
class DocumentSeriesViewFixture extends DocumentSeries
{
    public function table(Table $table): Table
    {
        return parent::table($table)->query(null)->filters([])
            ->records(fn (int $page, int $recordsPerPage) => new LengthAwarePaginator([], 30, $recordsPerPage, $page));
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public function mount(): void
    {
        $this->candidates = [['id' => 1, 'name' => 'Example Member', 'teams' => 'Team A']];
        $this->editor['items'] = [['description' => '', 'brand' => '', 'reference' => '',
            'quantity' => 1, 'price_cents' => 0, 'size' => '', 'initials' => '']];
    }

    protected function getViewData(): array
    {
        return [
            'lookups' => ['types' => [], 'templates' => [], 'teams' => collect([
                (object) ['id' => 7, 'team' => (object) ['name' => 'Team A'], 'season' => (object) ['name' => '2026']],
            ])],
            'filteredCandidates' => collect($this->candidates)->filter(fn ($p) => $this->recipientSearch === ''
                || str_contains($p['name'], $this->recipientSearch)),
            'previewHtml' => '',
            'recipients' => collect(),
        ];
    }
}
