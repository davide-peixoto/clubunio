<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ObjectChange;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ObjectChangeResource extends CatalogResource
{
    protected static ?string $model = ObjectChange::class;

    protected static ?string $modelLabel = 'Änderung';

    protected static ?string $pluralModelLabel = 'Änderungsprotokoll';

    protected static ?string $tenantRelationshipName = 'objectChanges';

    public static function getNavigationGroup(): ?string
    {
        return 'Verwaltung';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s')->timezone('Europe/Zurich'),
            TextEntry::make('actor.display_name')->label('Geändert von'), TextEntry::make('object_type')->label('Objekt'),
            TextEntry::make('object_id')->label('Datensatz-ID'),
            TextEntry::make('before_text')->label('Vorher')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap break-words']),
            TextEntry::make('after_text')->label('Nachher')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap break-words']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s')->timezone('Europe/Zurich')->sortable(),
            TextColumn::make('actor.display_name')->label('Geändert von')->searchable(),
            TextColumn::make('object_type')->label('Objekt')->searchable(), TextColumn::make('object_id')->label('Datensatz-ID')->searchable(),
        ])->filters([SelectFilter::make('object_type')->label('Objekt')->options(fn () => ObjectChange::where('tenant_id', Filament::getTenant()->id)->distinct()->orderBy('object_type')->pluck('object_type', 'object_type'))])
            ->recordActions([ViewAction::make()->slideOver()->iconButton()->tooltip('Änderungsdetails')])
            ->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => ObjectChangeResource\Pages\ListObjectChanges::route('/')];
    }
}
