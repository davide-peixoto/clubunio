<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\PlanningAbsence;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\TeamPlanningService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

class PlanningAbsencesTable extends WorkspaceScopedTable
{
    protected function absences(): Builder
    {
        $tenant = $this->authorizedTenant();

        $service = app(TeamPlanningService::class);
        $query = $service->absences(auth('clubunio')->user()->fresh(), $tenant)->select('clubora.team_absences.*');
        $query->selectSub($service->table($tenant, 'members')
            ->whereColumn('clubora.members.id', 'clubora.team_absences.member_id')
            ->selectRaw("concat(first_name, ' ', last_name)"), 'member_name');

        return PlanningAbsence::query()->setQuery($query);
    }

    protected function context(): array
    {
        $tenant = $this->authorizedTenant();
        $actor = auth('clubunio')->user()->fresh();
        $service = app(TeamPlanningService::class);
        $service->enter($actor, $tenant);
        $own = app(ModulePermissionService::class)->isProfile($actor, $tenant)
            ? collect() : app(MemberAccessService::class)->represented($actor, $tenant)->get();
        $options = $service->options($tenant);
        $profileAllows = fn (string $function) => ProfileUi::profileAllows($actor, $tenant, 'planning', $function);
        $workspaceToken = Crypt::encryptString(json_encode($this->workspaceContext));

        return compact('tenant', 'actor', 'own', 'options', 'profileAllows', 'workspaceToken');
    }

    public function table(Table $table): Table
    {
        $context = $this->context();
        $kinds = collect($context['options']['absence'] ?? [])->pluck('name', 'code');

        return $table->query(fn () => $this->absences())
            ->columns([
                TextColumn::make('member_name')->label('Mitglied'),
                TextColumn::make('starts_on')->label('Von')->date('d.m.Y')->sortable(),
                TextColumn::make('ends_on')->label('Bis')->date('d.m.Y')->sortable(),
                TextColumn::make('kind')->label('Art')->badge()->formatStateUsing(fn ($state) => $kinds[$state] ?? $state)->color(fn ($state) => StatusAppearance::palette($kinds[$state] ?? $state)),
                TextColumn::make('note')->label('Bemerkung')->state(fn ($record) => $record->note_encrypted ? Crypt::decryptString($record->note_encrypted) : '')->wrap(),
                ViewColumn::make('actions')->label('')->view('livewire.clubunio.workspace-absence-actions')->viewData($context),
            ])
            ->defaultSort('starts_on', 'desc')->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('Keine Absenzen vorhanden.')->emptyStateDescription(null);
    }

    public function render()
    {
        $this->context();

        return view('livewire.clubunio.workspace-absences-table');
    }
}
