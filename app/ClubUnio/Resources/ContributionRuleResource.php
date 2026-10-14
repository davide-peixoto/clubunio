<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\Season;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContributionRuleResource extends ContributionTrainingResource
{
    protected static ?string $model = ContributionRule::class;

    protected static ?string $modelLabel = 'Beitragsregel';

    protected static ?string $pluralModelLabel = 'Beitragsregeln';

    protected static ?string $tenantRelationshipName = 'contributionRules';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'), Select::make('season_id')->label('Saison')->options(fn () => static::options(Season::class))->required(),
            Select::make('price_id')->label('Beitragspreis')->options(fn () => static::options(ContributionPrice::class))->required()->searchable(),
            Select::make('gender_id')->label('Geschlecht')->options(fn () => static::options(Gender::class))->required(),
            TextInput::make('year_from')->label('Jahrgang von')->numeric()->required(), TextInput::make('year_to')->label('Jahrgang bis')->numeric()->required(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('season.name')->label('Saison')->sortable(), TextColumn::make('price.name')->label('Beitrag')->searchable(), TextColumn::make('price.amount')->label('Betrag')->money('CHF'), TextColumn::make('gender.name')->label('Geschlecht'), TextColumn::make('year_from')->label('Jahrgang von'), TextColumn::make('year_to')->label('Jahrgang bis'), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([SelectFilter::make('season_id')->label('Saison')->options(fn () => static::options(Season::class)), TernaryFilter::make('active')->label('Aktiv')])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => ContributionRuleResource\Pages\ManageRules::route('/')];
    }
}
