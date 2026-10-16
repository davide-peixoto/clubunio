<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\EmbeddedCreditNote;
use App\ClubUnio\Resources\FinancialDossierDialog;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceCreditsRelationManager extends RelationManager
{
    use RefreshFinancialDossier;

    protected static string $relationship = 'creditNotes';

    protected static ?string $title = 'Gutschriften';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->label('Nr.'), TextColumn::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('external_reference')->label('Bexio-Referenz')->placeholder('Noch nicht zugeordnet'), TextColumn::make('reason')->label('Begründung')->wrap()])
            ->recordActions([FinancialDossierDialog::make(EmbeddedCreditNote::class, fn () => $this->getOwnerRecord()->id)]);
    }
}
