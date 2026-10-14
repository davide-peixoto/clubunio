<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\RefundResource;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceRefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Rückerstattungen';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->label('Nr.'), TextColumn::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('proof_reference')->label('Manueller Nachweis')->placeholder('Noch nicht bestätigt'), TextColumn::make('reason')->label('Begründung')->wrap()])
            ->recordActions([Action::make('view')->label('Anzeigen')->icon('heroicon-o-eye')->iconButton()->tooltip('Rückerstattung anzeigen')->url(fn ($record) => RefundResource::getUrl('view', ['record' => $record->id]))]);
    }
}
