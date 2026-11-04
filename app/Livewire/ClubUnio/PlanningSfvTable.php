<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\SfvSchedule;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class PlanningSfvTable extends WorkspaceScopedTable
{
    protected function context(): array
    {
        $tenant = $this->authorizedTenant();
        $actor = auth('clubunio')->user()->fresh();
        $service = app(TeamPlanningService::class);
        $service->enter($actor, $tenant);
        $teams = $service->teams($actor, $tenant)->get();
        $managed = $service->teams($actor, $tenant, true)->pluck('id');
        abort_unless($managed->count(), 403);
        $sfvIds = $service->table($tenant, 'teams')->whereIn('id', $teams->whereIn('id', $managed)->pluck('team_id'))->whereNotNull('sfv_team_id')->pluck('sfv_team_id');
        $range = $this->tableFilters['period'] ?? $this->initialFilters;
        $schedule = $service->table($tenant, 'sfv_schedule')->select('match_id')
            ->where(fn ($q) => $q->whereIn('team_a_id', $sfvIds)->orWhereIn('team_b_id', $sfvIds));
        if (Validator::make($range, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->fails()) {
            $schedule->whereRaw('1 = 0');
        } else {
            $schedule->where('starts_at', '>=', CarbonImmutable::parse($range['from'], 'Europe/Zurich')->toIso8601String())
                ->where('starts_at', '<', CarbonImmutable::parse($range['to'], 'Europe/Zurich')->addDay()->toIso8601String());
        }
        $imported = $service->table($tenant, 'team_events')->whereIn('team_season_id', $managed)->whereIn('sfv_match_id', $schedule)->get(['id', 'team_season_id', 'sfv_match_id'])->groupBy('sfv_match_id');
        $profileAllows = fn (string $function) => ProfileUi::profileAllows($actor, $tenant, 'planning', $function);

        return compact('tenant', 'actor', 'teams', 'managed', 'sfvIds', 'imported', 'profileAllows');
    }

    protected function matches(): Builder
    {
        $context = $this->context();

        return SfvSchedule::query()->where('tenant_id', $context['tenant']->id)
            ->where(fn ($q) => $q->whereIn('team_a_id', $context['sfvIds'])->orWhereIn('team_b_id', $context['sfvIds']));
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->matches())
            ->columns([
                TextColumn::make('starts_at')->label('Datum')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
                TextColumn::make('title')->label('Spiel')->wrap()->searchable(),
                TextColumn::make('location')->label('Ort')->wrap()->searchable(),
                TextColumn::make('home')->label('Heim/Auswärts')->formatStateUsing(fn ($state) => $state ? __('Heim') : __('Auswärts')),
                TextColumn::make('state')->label('Verbandstatus')->badge()->formatStateUsing(fn ($state) => $state === null ? null : __($state))->color(fn ($state) => StatusAppearance::palette($state)),
                ViewColumn::make('import')->label('Übernahme')->view('livewire.clubunio.workspace-sfv-actions')->viewData($this->context()),
            ])
            ->deferFilters(false)->filters([
                Filter::make('period')->label('Zeitraum')->schema([
                    DatePicker::make('from')->label('Von')->required()->default($this->initialFilters['from'] ?? now('Europe/Zurich')->toDateString()),
                    DatePicker::make('to')->label('Bis')->required()->default($this->initialFilters['to'] ?? now('Europe/Zurich')->addMonths(3)->toDateString()),
                ])->query(function (Builder $query, array $data): Builder {
                    if (Validator::make($data, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->fails()) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->where('starts_at', '>=', CarbonImmutable::parse($data['from'], 'Europe/Zurich')->toIso8601String())
                        ->where('starts_at', '<', CarbonImmutable::parse($data['to'], 'Europe/Zurich')->addDay()->toIso8601String());
                }),
            ])
            ->defaultSort('starts_at')->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('Keine Spiele im gewählten Zeitraum.'))->emptyStateDescription(null);
    }

    public function render()
    {
        $context = $this->context();

        return view('livewire.clubunio.workspace-sfv-table', $context + [
            'filters' => $this->tableFilters['period'] ?? $this->initialFilters,
            'workspaceToken' => Crypt::encryptString(json_encode($this->workspaceContext)),
        ]);
    }
}
