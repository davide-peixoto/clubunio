<?php

namespace App\ClubUnio\Resources\Relations;

use Filament\Tables\Table;

class ExpensesRelationManager extends DossierRelationManager
{
    use SeasonFinanceActions;

    public function table(Table $table): Table
    {
        return $this->withSeasonFinanceActions(parent::table($table));
    }

    protected static string $relationship = 'expenses';

    protected static string $kind = 'expenses';

    protected static ?string $title = 'Spesen';
}
