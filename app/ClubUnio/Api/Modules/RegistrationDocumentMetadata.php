<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Models\CatalogModel;

/** Internal read projection; no API mutation adapter is registered. */
final class RegistrationDocumentMetadata extends CatalogModel
{
    protected $table = 'clubora.registration_documents';
}
