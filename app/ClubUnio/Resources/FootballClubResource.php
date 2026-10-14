<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\FootballClub;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FootballClubResource extends FacilityResource
{
    protected static ?string $model = FootballClub::class;

    protected static ?string $modelLabel = 'Verein';

    protected static ?string $pluralModelLabel = 'Vereine und Logos';

    protected static ?string $tenantRelationshipName = 'footballClubs';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            TextInput::make('club_number')->label('SFV-Vereinsnummer')->integer()->minValue(1)->maxValue(2147483647)->required()
                ->disabled(fn (?FootballClub $record) => $record !== null)->dehydrated(fn (?FootballClub $record) => $record === null),
            TextInput::make('name')->label('Vereinsname')->required()->maxLength(255),
            Toggle::make('logo_enabled')->label('SFV-Logo anzeigen')->default(true)->required(),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ViewColumn::make('logo')->label('Logo')->view('clubunio.columns.club-logo'),
            TextColumn::make('club_number')->label('Vereinsnummer')->searchable()->sortable(),
            TextColumn::make('name')->label('Verein')->searchable()->sortable(),
            IconColumn::make('logo_enabled')->label('Logo')->boolean(),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([TernaryFilter::make('active')->label('Aktiv')->default(true), TernaryFilter::make('logo_enabled')->label('Logo')])
            ->headerActions([static::createAction()])->recordActions([static::editAction()])
            ->defaultSort('name')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function searchClubs(string $search): array
    {
        abort_unless(static::canViewAny(), 403);

        return FootballClub::where('tenant_id', Filament::getTenant()->id)->where('active', true)
            ->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhereRaw('CAST(club_number AS TEXT) LIKE ?', [$search.'%']))
            ->orderBy('name')->limit(50)->get()->mapWithKeys(fn ($r) => [$r->club_number => $r->name.' ('.$r->club_number.')'])->all();
    }

    public static function clubLabel($number): ?string
    {
        abort_unless(static::canViewAny(), 403);
        if (! ctype_digit((string) $number) || (int) $number < 1 || (float) $number > 2147483647) {
            return null;
        }
        $club = FootballClub::where('tenant_id', Filament::getTenant()->id)->where('club_number', $number)->first();

        return $club ? $club->name.' ('.$number.')'.($club->active ? '' : ' - inaktiv') : 'Vereinsnummer '.$number.' (Altbestand)';
    }

    public static function getPages(): array
    {
        return ['index' => FootballClubResource\Pages\ManageClubs::route('/')];
    }
}
