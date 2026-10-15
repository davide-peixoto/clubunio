<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\LegacyMaterialReceipt;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LegacyMaterialReceiptResource extends CatalogResource
{
    protected static ?string $model = LegacyMaterialReceipt::class;

    protected static ?string $modelLabel = 'Historische Quittung';

    protected static ?string $pluralModelLabel = 'Historische Quittungen';

    protected static ?string $tenantRelationshipName = 'legacyMaterialReceipts';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('member.display_name')->label('Mitglied'),
            TextColumn::make('title')->label('Titel')->searchable()->wrap(),
            TextColumn::make('source_state')->label('Historischer Status')->badge(),
            TextColumn::make('signed_name')->label('Unterzeichnet durch'),
            TextColumn::make('signed_at')->label('Historischer Zeitpunkt')->dateTime('d.m.Y H:i'),
            TextColumn::make('source_id')->label('Alte Quittungs-ID'),
        ])->recordActions([
            Action::make('signedPdf')->label('Nachweis')->icon('heroicon-o-document-check')->iconButton()->tooltip('Historischen Nachweis öffnen')
                ->url(fn ($record) => route('clubunio.material.history.pdf', [Filament::getTenant()->slug, $record->id]))->openUrlInNewTab(),
            Action::make('originalPdf')->label('Original')->icon('heroicon-o-document-text')->iconButton()->tooltip('Unsigniertes Original öffnen')
                ->url(fn ($record) => route('clubunio.material.history.pdf', [Filament::getTenant()->slug, $record->id, 'original' => 1]))->openUrlInNewTab(),
        ])->defaultSort('id', 'desc')->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => LegacyMaterialReceiptResource\Pages\ListLegacyMaterialReceipts::route('/')];
    }
}
