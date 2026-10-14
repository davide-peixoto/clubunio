<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\StaffFunction;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\TeamAssignmentService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

abstract class TeamAssignmentResource extends CatalogResource
{
    public static function getNavigationGroup(): ?string
    {
        return 'Mannschaften';
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label('Zuordnen')->slideOver()->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(TeamAssignmentService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $data)));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()->slideOver()->iconButton()->tooltip('Zuordnung bearbeiten')
            ->mutateRecordDataUsing(fn (array $data, CatalogModel $record) => array_merge($data, ['_version' => FamilyService::version($record)]))
            ->using(fn (CatalogModel $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(TeamAssignmentService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), $record->id, $data)));
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [Hidden::make('_version'), MemberResource::memberSelect('member_id')->required(),
            Select::make('team_season_id')->label('Mannschaft / Saison')->searchable()->required()->options(fn (?CatalogModel $record) => TeamSeason::where('tenant_id', Filament::getTenant()->id)
                ->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->team_season_id ?? -1))->orderByDesc('id')->pluck('label', 'id')),
            Toggle::make('active')->label('Aktiv')->default(true)->required()];
        if (static::getModel() === TeamStaff::class) {
            $fields[] = Select::make('staff_function_id')->label('Funktion')->required(fn (?CatalogModel $record) => $record === null || $record->staff_function_id !== null)->searchable()
                ->options(fn (?CatalogModel $record) => MemberResource::lookupOptions(StaffFunction::class, $record?->staff_function_id));
        }

        return $schema->columns(2)->components($fields);
    }

    public static function table(Table $table): Table
    {
        $columns = [TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('teamSeason.label')->label('Mannschaft / Saison')->searchable()->sortable(),
            IconColumn::make('active')->label('Aktiv')->boolean()];
        if (static::getModel() === TeamStaff::class) {
            $columns[] = TextColumn::make('staffFunction.name')->label('Funktion');
        }

        return $table->columns($columns)->filters([
            SelectFilter::make('team_season_id')->label('Mannschaft / Saison')->searchable()->options(fn () => TeamSeason::where('tenant_id', Filament::getTenant()->id)->orderByDesc('id')->pluck('label', 'id')),
            SelectFilter::make('season')->label('Saison')->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id'))
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $value) => $q->whereHas('teamSeason', fn ($q) => $q->where('season_id', $value)))),
            TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([static::createAction()])->recordActions([static::editAction()])
            ->defaultSort('id', 'desc')->paginated([25, 50, 100])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }
}
