<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ContributionPrice;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContributionPriceResource extends ContributionTrainingResource
{
    protected static ?string $model = ContributionPrice::class;

    protected static ?string $modelLabel = 'Beitragspreis';

    protected static ?string $pluralModelLabel = 'Beitragspreise';

    protected static ?string $tenantRelationshipName = 'contributionPrices';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'), TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160),
            TextInput::make('amount')->label('Betrag CHF')->numeric()->minValue(0)->required(),
            TextInput::make('bexio_product_id')->label('Bexio-Produktreferenz')->maxLength(100), TextInput::make('bexio_article_id')->label('Bexio-Artikel-ID')->numeric()->minValue(1), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(), TextColumn::make('amount')->label('Betrag')->money('CHF'), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])->headerActions([static::createAction()])->recordActions([static::editAction(), static::copyAction(), static::deactivateAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => ContributionPriceResource\Pages\ManagePrices::route('/')];
    }
}
