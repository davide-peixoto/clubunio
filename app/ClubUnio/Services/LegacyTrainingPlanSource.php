<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class LegacyTrainingPlanSource
{
    public function rows(Tenant $tenant)
    {
        abort_unless($tenant->slug === 'sv-sissach', 403, 'Kein Altbestand für diesen Verein hinterlegt.');

        return DB::connection('clubunio')->table('legacy_prod.TrainingPlannerAuditLog')->orderBy('TrainingPlannerAuditLogID')->get();
    }

    public function hash(object $row): string
    {
        return hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }
}
