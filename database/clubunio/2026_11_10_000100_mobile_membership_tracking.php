<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.mobile_identity_challenges
 ADD registration_id bigint,
 ADD CONSTRAINT mobile_identity_registration_fk FOREIGN KEY (tenant_id, registration_id)
    REFERENCES clubunio.registrations(tenant_id, id),
 ADD CONSTRAINT mobile_identity_registration_purpose CHECK (registration_id IS NULL OR purpose = 'register');
CREATE INDEX mobile_identity_registration_owner ON clubunio.mobile_identity_challenges(account_id, tenant_id)
 WHERE registration_id IS NOT NULL AND state = 'completed';
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preserve ownership evidence; reviewed restore required');
    }
};
