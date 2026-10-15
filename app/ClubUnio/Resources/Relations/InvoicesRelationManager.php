<?php

namespace App\ClubUnio\Resources\Relations;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'invoices';

    protected static string $kind = 'invoices';

    protected static ?string $title = 'Rechnungen';

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $money = fn ($name, $label) => TextColumn::make($name)->label($label)->placeholder('Nicht abgeglichen')
            ->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'"));

        return $table->modifyQueryUsing(fn ($query) => $query->with('bexioBalance')->withSum(['refunds' => fn ($q) => $q->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))], 'amount_cents'))
            ->columns([...array_values($table->getColumns()),
                $money('bexioBalance.credit_cents', 'Bexio-Gutschriften CHF'),
                $money('bexioBalance.remaining_cents', 'Bexio-Rest CHF'),
                $money('bexioBalance.cash_excess_cents', 'Bexio-Geldguthaben CHF'),
                $money('refunds_sum_amount_cents', 'Rückerstattungen reserviert/bestätigt CHF'),
                TextColumn::make('bexioBalance.observed_at')->label('Abgleich vom')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->placeholder('Nicht abgeglichen'),
            ]);
    }
}
