<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ExpenseType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExpenseTypeResource extends CatalogResource
{
    protected static ?string $model = ExpenseType::class;

    protected static ?string $modelLabel = 'Spesentypen';

    protected static ?string $pluralModelLabel = 'Spesentypen';

    protected static ?string $tenantRelationshipName = 'expenseTypes';

    public static function getPages(): array
    {
        return ['index' => ExpenseTypeResource\Pages\ManageRecords::route('/')];
    }

    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->components(array_merge(parent::form($schema)->getComponents(), [
            Toggle::make('receipt_required')->label('Beleg erforderlich')->default(false),
            Select::make('amount_source')->label('Betragsvorschlag')->required()->default('manual')
                ->options(['manual' => 'Manuell', 'receipt_ai' => 'Beleganalyse', 'team_laundry' => 'Dressentschädigung der Mannschaft']),
        ]));
    }
}
