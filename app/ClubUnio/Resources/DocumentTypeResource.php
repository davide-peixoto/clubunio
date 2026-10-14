<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\DocumentType;

class DocumentTypeResource extends CatalogResource
{
    protected static ?string $model = DocumentType::class;

    protected static ?string $modelLabel = 'Dokumententyp';

    protected static ?string $pluralModelLabel = 'Dokumententypen';

    protected static ?string $tenantRelationshipName = 'documentTypes';

    public static function getPages(): array
    {
        return ['index' => DocumentTypeResource\Pages\ManageDocumentTypes::route('/')];
    }
}
