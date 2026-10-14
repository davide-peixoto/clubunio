<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\DocumentActions;
use Filament\Tables\Table;

class DocumentsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'documents';

    protected static string $kind = 'documents';

    protected static ?string $title = 'Anhänge';

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions(DocumentActions::make());
    }
}
