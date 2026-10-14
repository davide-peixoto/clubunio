<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\InvoiceLineStatus;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class InvoiceLineStatusResource extends InvoiceStatusResource
{
    protected static ?string $model = InvoiceLineStatus::class;

    protected static ?string $modelLabel = 'Rechnungspositionsstatus';

    protected static ?string $pluralModelLabel = 'Rechnungspositionsstatus';

    protected static ?string $tenantRelationshipName = 'invoiceLineStatuses';

    public static function form(Schema $schema): Schema
    {
        $fields = parent::form($schema)->getComponents();
        $fields[1]->label('Code')->maxLength(80);
        unset($fields[2]);

        return $schema->components(array_values($fields));
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        $columns = $table->getColumns();
        unset($columns['position_code']);
        $columns['code']->label('Code');

        return $table->columns(array_values($columns));
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceLineStatusResource\Pages\ManageRecords::route('/')];
    }
}
