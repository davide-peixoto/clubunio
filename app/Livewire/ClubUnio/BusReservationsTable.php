<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\BusReservation;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\StatusAppearance;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class BusReservationsTable extends WorkspaceScopedTable
{
    protected function reservations(): Builder
    {
        $tenant = $this->authorizedTenant();

        return BusReservation::query()->setQuery(app(BusReservationService::class)->visible(auth('clubunio')->user()->fresh(), $tenant));
    }

    public function table(Table $table): Table
    {
        $tenant = $this->authorizedTenant();
        $service = app(BusReservationService::class);
        $teams = $service->teams(auth('clubunio')->user()->fresh(), $tenant)->orderByDesc('season_id')->orderBy('label')->pluck('label', 'id')->all();
        $buses = $service->table($tenant, 'buses')->orderBy('name')->pluck('name', 'id')->all();
        $statusRows = $service->table($tenant, 'bus_statuses')->orderBy('id')->get();
        $statuses = $statusRows->pluck('name', 'code')->all();
        $statusLabels = $statusRows->mapWithKeys(fn ($row) => [
            $row->code => app(\App\ClubUnio\Services\LookupTranslations::class)->label('bus_statuses', $row),
        ])->all();
        $teamLabels = $service->table($tenant, 'team_seasons')->whereIn('id', $service->visible(auth('clubunio')->user()->fresh(), $tenant)->select('team_season_id'))->pluck('label', 'id')->all();

        return $table->query(fn () => $this->reservations())
            ->columns([
                TextColumn::make('starts_at')->label('Von')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable()
                    ->url(fn ($record) => route('clubunio.workspace.bus.show', $record->id)),
                TextColumn::make('ends_at')->label('Bis')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
                TextColumn::make('bus_id')->label('Bus')->formatStateUsing(fn ($state) => $buses[$state] ?? ''),
                TextColumn::make('team_season_id')->label('Mannschaft')->formatStateUsing(fn ($record) => $teamLabels[$record->team_season_id] ?? __('Nicht zugeordnet'))->placeholder(__('Nicht zugeordnet')),
                TextColumn::make('location')->label('Ziel')->wrap()->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q->where('location', 'ilike', '%'.$search.'%')->orWhere('description', 'ilike', '%'.$search.'%'))),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => $statusLabels[$state] ?? $state)->color(fn ($state) => StatusAppearance::palette($statuses[$state] ?? $state)),
            ])
            ->deferFilters(false)->filters([
                SelectFilter::make('bus_id')->label('Bus')->options($buses)->default($this->initialFilters['bus'] ?? null),
                SelectFilter::make('team_season_id')->label('Mannschaft')->searchable()->options($teams)->default($this->initialFilters['team'] ?? null),
                SelectFilter::make('status')->label('Status')->options($statusLabels)->default($this->initialFilters['status'] ?? null),
                Filter::make('period')->label('Zeitraum')->schema([
                    DatePicker::make('from')->label('Von')->default($this->initialFilters['from'] ?? now('Europe/Zurich')->toDateString()),
                    DatePicker::make('to')->label('Bis')->default($this->initialFilters['to'] ?? null),
                ])->query(function (Builder $query, array $data): Builder {
                    if (Validator::make($data, ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from'])->fails()) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->when($data['from'] ?? null, fn ($q, $date) => $q->where('ends_at', '>', CarbonImmutable::parse($date, 'Europe/Zurich')->toIso8601String()))
                        ->when($data['to'] ?? null, fn ($q, $date) => $q->where('starts_at', '<', CarbonImmutable::parse($date, 'Europe/Zurich')->addDay()->toIso8601String()));
                }),
            ])
            ->headerActions([
                Action::make('create')->label('Reservation erfassen')->icon('heroicon-o-plus')->url(route('clubunio.workspace.bus.create'))
                    ->visible(fn () => count($teams) && ProfileUi::profileAllows(auth('clubunio')->user()->fresh(), $this->authorizedTenant(), 'bus', 'create')),
            ])
            ->defaultSort('starts_at')->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('Keine Reservationen gefunden.'))->emptyStateDescription(null);
    }

    public function render()
    {
        $tenant = $this->authorizedTenant();
        app(BusReservationService::class)->enter(auth('clubunio')->user()->fresh(), $tenant);

        return view('livewire.clubunio.workspace-bus-table');
    }
}
