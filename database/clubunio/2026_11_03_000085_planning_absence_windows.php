<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->statement('ALTER TABLE clubora.team_absences ADD COLUMN starts_at timestamptz, ADD COLUMN ends_at timestamptz, ADD CONSTRAINT team_absence_window CHECK ((starts_at IS NULL AND ends_at IS NULL) OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at))');
    }
    public function down(): void { throw new LogicException('Retain exact absence windows; reviewed restore required'); }
};
