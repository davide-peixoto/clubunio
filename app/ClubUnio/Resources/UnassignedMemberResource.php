<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Season;
use App\ClubUnio\Services\MembershipReportService;
use App\ClubUnio\Services\ReportExportService;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnassignedMemberResource extends MemberReportResource
{
    protected static ?string $pluralModelLabel = 'Mitglieder ohne Mannschaft';

    public static function getEloquentQuery(): Builder
    {
        return app(MembershipReportService::class)->teamCandidates(Filament::auth()->user(), Filament::getTenant());
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->filters([...array_values($table->getFilters()),
            SelectFilter::make('season')->label('Saison')->searchable()
                ->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id'))
                ->default(fn () => app(MembershipReportService::class)->currentSeason(Filament::getTenant()))
                ->query(fn (Builder $query, array $data) => app(MembershipReportService::class)->withoutTeam($query, Filament::getTenant(), $data['value'] ?? null)),
        ])->headerActions([ReportExportService::action('unassigned')])->filtersLayout(FiltersLayout::AboveContent)->filtersFormColumns(4)
            ->emptyStateHeading(fn ($livewire) => blank($livewire->tableFilters['season']['value'] ?? null)
                ? 'Keine Saison ausgewählt' : 'Keine Mitglieder ohne Mannschaft');
    }

    public static function getPages(): array
    {
        return ['index' => UnassignedMemberResource\Pages\ListUnassignedMembers::route('/')];
    }
}
