<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\DocumentActions;
use App\ClubUnio\Resources\MemberDocumentActions;
use Filament\Tables\Table;

class DocumentsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'documents';

    protected static string $kind = 'documents';

    protected static ?string $title = 'Anhänge';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $member = $this->getOwnerRecord();

        return parent::table($table)->headerActions([
            MemberDocumentActions::upload($member->id)->visible(! $member->archived_at),
        ])->recordActions([...DocumentActions::make(), MemberDocumentActions::manage($member->id)->visible(! $member->archived_at)]);
    }
}
