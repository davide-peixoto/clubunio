<?php

namespace App\ClubUnio\Resources\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CreditNoteLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Gutschriftspositionen';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('invoice_line_id')->label('Originalposition'), TextColumn::make('description')->label('Bezeichnung')->wrap(),
            TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('included_tax')->label('Enthaltene Steuer CHF'), TextColumn::make('tax_snapshot.code')->label('Originalsteuer')->placeholder('Steuerbefreit')]);
    }
}
