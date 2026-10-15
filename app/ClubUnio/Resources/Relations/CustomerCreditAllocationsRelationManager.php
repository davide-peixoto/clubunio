<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\CustomerCreditResource;
use App\ClubUnio\Resources\InvoiceResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerCreditAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'Nachgewiesene Verrechnungen';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id)->with('customerCredit'))
            ->columns([TextColumn::make('customerCredit.external_reference')->label('Bexio-Gutschrift')->url(fn ($record) => CustomerCreditResource::getUrl('view', ['record' => $record->customer_credit_id])),
                TextColumn::make('invoice_id')->label('Rechnung')->url(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2)),
                TextColumn::make('evidence.allocation.payment_ids')->label('Bexio-Einträge'),
                TextColumn::make('created_at')->label('Abgeglichen')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')])->defaultSort('id');
    }
}
