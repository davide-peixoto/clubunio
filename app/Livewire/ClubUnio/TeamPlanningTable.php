<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;

class TeamPlanningTable extends PlanningAdminTable
{
    protected function authorizeModule(Account $actor, Tenant $tenant): void
    {
        app(TeamPlanningService::class)->enter($actor, $tenant);
    }

    protected function initializeFilters(array $filters): void
    {
        $this->tableFilters = [
            'team' => ['value' => $filters['team'] ?? null],
            'status' => ['value' => $filters['status'] ?? null],
            'dates' => ['from' => array_key_exists('from', $filters) ? $filters['from'] : now('Europe/Zurich')->toDateString(), 'to' => $filters['to'] ?? null],
        ];
    }

    public function table(Table $table): Table
    {
        [$actor, $tenant] = $this->context();
        $service = app(TeamPlanningService::class);
        $teams = $service->teams($actor, $tenant)->orderByDesc('season_id')->orderBy('label')->get();
        $options = $service->options($tenant);
        $displayOptions = collect($options)->map(fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [
            $row->code => app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $row),
        ])->all())->all();

        return $table
            ->records(function (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage) {
                [$actor, $tenant] = $this->context();
                $service = app(TeamPlanningService::class);
                $dates = Validator::make($filters['dates'] ?? [], [
                    'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
                ])->validate();
                $query = $service->table($tenant, 'team_events')
                    ->where(fn ($q) => $q->whereIn('team_season_id', $service->teams($actor, $tenant)->select('id'))
                        ->orWhereIn('id', $service->invitedEvents($actor, $tenant)->select('event_id')))
                    ->select(['id', 'title', 'team_season_id', 'starts_at', 'kind', 'location', 'status', 'deadline_at'])
                    ->selectSub($service->table($tenant, 'team_seasons')->select('label')->whereColumn('id', 'clubora.team_events.team_season_id')->limit(1), 'team_label');
                if (filled($filters['team']['value'] ?? null)) {
                    $query->where('team_season_id', (int) $filters['team']['value']);
                }
                if (filled($filters['status']['value'] ?? null)) {
                    $query->where('status', (string) $filters['status']['value']);
                }
                if (filled($search)) {
                    $query->where('title', 'ilike', '%'.mb_substr($search, 0, 100).'%');
                }
                if (! empty($dates['from'])) {
                    $query->where('starts_at', '>=', CarbonImmutable::parse($dates['from'], 'Europe/Zurich')->toIso8601String());
                }
                if (! empty($dates['to'])) {
                    $query->where('starts_at', '<', CarbonImmutable::parse($dates['to'], 'Europe/Zurich')->addDay()->toIso8601String());
                }
                $column = in_array($sortColumn, ['starts_at', 'title', 'team_label', 'location', 'status', 'deadline_at'], true) ? $sortColumn : 'starts_at';
                $query->orderBy($column, $sortDirection === 'desc' ? 'desc' : 'asc')->orderBy('id');

                return $this->recordsPage($query, $page, $recordsPerPage);
            })
            ->columns([
                TextColumn::make('starts_at')->label('Beginn')->dateTime('d.m.Y H:i', 'Europe/Zurich')->sortable(),
                TextColumn::make('title')->label('Termin')->searchable()->sortable()
                    ->description(fn (array $record) => $displayOptions['kind'][$record['kind']] ?? $record['kind'])
                    ->url(fn (array $record) => route('clubunio.planning.show', [$tenant->slug, $record['id']])),
                TextColumn::make('team_label')->label('Mannschaft')->sortable(),
                TextColumn::make('location')->label('Ort')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->color(fn (string $state) => StatusAppearance::palette($options['status'][$state]->name ?? $state))
                    ->formatStateUsing(fn (string $state) => $displayOptions['status'][$state] ?? $state),
                TextColumn::make('deadline_at')->label('Frist')->dateTime('d.m.Y H:i', 'Europe/Zurich')->placeholder(__('Keine Frist'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('team')->label('Mannschaft')->searchable()->options($teams->pluck('label', 'id')->all()),
                SelectFilter::make('status')->label('Status')->options($displayOptions['status'] ?? []),
                Filter::make('dates')->label('Zeitraum')->schema([
                    DatePicker::make('from')->label('Von')->default(now('Europe/Zurich')->toDateString()),
                    DatePicker::make('to')->label('Bis')->afterOrEqual('from'),
                ])->indicateUsing(fn (array $data) => array_filter([
                    filled($data['from'] ?? null) ? __('Von: :date', ['date' => $data['from']]) : null,
                    filled($data['to'] ?? null) ? __('Bis: :date', ['date' => $data['to']]) : null,
                ])),
            ])->deferFilters(false)
            ->headerActions([
                Action::make('create')->label('Termin erstellen')->icon('heroicon-o-plus')
                    ->url(route('clubunio.planning.create', $tenant->slug))
                    ->visible(fn () => ProfileUi::profileAllows($actor, $tenant, 'planning', 'create')
                        && $service->teams($actor, $tenant, true)->exists()),
            ])
            ->defaultSort('starts_at')->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->modelLabel(__('Termin'))->pluralModelLabel(__('Termine'))
            ->emptyStateHeading(__('Keine Termine gefunden.'))->emptyStateDescription(null);
    }
}
