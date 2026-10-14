<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\SignageNotice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SignageNoticeResource extends FacilityResource
{
    protected static ?string $model = SignageNotice::class;

    protected static ?string $modelLabel = 'Signage-Meldung';

    protected static ?string $pluralModelLabel = 'Signage-Meldungen';

    protected static ?string $tenantRelationshipName = 'signageNotices';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('revision'), DatePicker::make('shows_on')->label('Anzeigen am')->required(), TextInput::make('title')->label('Betreff')->required()->maxLength(255), Textarea::make('body')->label('Meldung')->required()->maxLength(10000)->columnSpanFull(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('shows_on')->label('Datum')->date('d.m.Y')->sortable(), TextColumn::make('title')->label('Betreff')->searchable(), TextColumn::make('body')->label('Meldung')->wrap()->limit(160)->searchable(), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([Filter::make('upcoming')->label('Ab heute')->default()->query(fn ($query) => $query->where('shows_on', '>=', now('Europe/Zurich')->toDateString())), TernaryFilter::make('active')->label('Aktiv')->default(true)])->headerActions([static::createAction()])->recordActions([static::editAction()])->defaultSort('shows_on')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => SignageNoticeResource\Pages\ManageNotices::route('/')];
    }
}
