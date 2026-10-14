<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\CreditNoteResource;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceCreditsRelationManager extends RelationManager
{
    protected static string $relationship = 'creditNotes';

    protected static ?string $title = 'Gutschriften';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->label('Nr.'), TextColumn::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('external_reference')->label('Bexio-Referenz')->placeholder('Noch nicht zugeordnet'), TextColumn::make('reason')->label('Begründung')->wrap()])
            ->recordActions([Action::make('view')->label('Anzeigen')->icon('heroicon-o-eye')->iconButton()->tooltip('Gutschrift anzeigen')->url(fn ($record) => CreditNoteResource::getUrl('view', ['record' => $record->id]))]);
    }
}
