<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\LegacyTrainingPlanResource;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\TrainingPlanService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrainingPlansTable extends PlanningAdminTable
{
    protected function authorizeModule(Account $actor, Tenant $tenant): void
    {
        app(TrainingPlanService::class)->authorizeOperation($actor, $tenant, 'view');
    }

    protected function initializeFilters(array $filters): void
    {
        $this->tableFilters = [
            'team' => ['value' => $filters['team'] ?? null],
            'archived' => ['value' => $filters['archived'] ?? '0'],
        ];
    }

    public function table(Table $table): Table
    {
        [$actor, $tenant] = $this->context();
        $teams = app(TrainingPlanService::class)->teams($actor, $tenant);

        return $table
            ->records(function (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage) {
                [$actor, $tenant] = $this->context();
                $query = app(TrainingPlanService::class)->visible($actor, $tenant)
                    ->select(['id', 'title', 'team_season_id', 'shared', 'updated_at'])
                    ->when(($filters['archived']['value'] ?? '0') === '1', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'));
                if (filled($filters['team']['value'] ?? null)) {
                    $query->where('team_season_id', (int) $filters['team']['value']);
                }
                if (filled($search)) {
                    $query->where('title', 'ilike', '%'.mb_substr($search, 0, 100).'%');
                }
                $column = in_array($sortColumn, ['title', 'shared', 'updated_at'], true) ? $sortColumn : 'updated_at';
                $query->orderBy($column, $sortDirection === 'asc' ? 'asc' : 'desc')->orderBy('id');

                return $this->recordsPage($query, $page, $recordsPerPage);
            })
            ->columns([
                TextColumn::make('title')->label('Training')->searchable()->sortable()
                    ->url(fn (array $record) => route('clubunio.training.show', [$tenant->slug, $record['id']])),
                TextColumn::make('team_season_id')->label('Mannschaft')->formatStateUsing(fn ($state) => $teams->firstWhere('id', $state)?->label),
                TextColumn::make('shared')->label('Freigabe')->badge()->sortable()
                    ->color(fn ($state) => StatusAppearance::palette($state ? 'Teamverantwortliche' : 'Privat'))
                    ->formatStateUsing(fn ($state) => $state ? 'Teamverantwortliche' : 'Privat'),
                TextColumn::make('updated_at')->label('Geaendert')->dateTime('d.m.Y H:i', 'Europe/Zurich')->sortable(),
            ])
            ->filters([
                SelectFilter::make('team')->label('Mannschaft')->options($teams->pluck('label', 'id')->all()),
                SelectFilter::make('archived')->label('Bestand')->options(['0' => 'Aktuell', '1' => 'Archiv'])->default('0')->selectablePlaceholder(false),
            ])->deferFilters(false)
            ->headerActions([
                Action::make('legacy')->label('Altpläne prüfen und zuordnen')->icon('heroicon-o-archive-box')
                    ->url(LegacyTrainingPlanResource::getUrl())
                    ->visible(fn () => ProfileUi::profileAllows($actor, $tenant, 'training', 'view')),
                Action::make('create')->label('Neuer Trainingsplan')->icon('heroicon-o-plus')->url(route('clubunio.training.create', $tenant->slug))
                    ->visible(fn () => ProfileUi::profileAllows($actor, $tenant, 'training', 'create')),
            ])
            ->defaultSort('updated_at', 'desc')->paginationPageOptions([10, 20, 50, 100])->defaultPaginationPageOption(20)
            ->emptyStateHeading('Keine Trainingspläne vorhanden.');
    }
}
