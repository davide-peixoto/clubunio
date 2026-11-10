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
        $db->statement('ALTER TABLE clubora.team_events ADD COLUMN deleted_at timestamptz');
    }
    public function down(): void { throw new LogicException('Retain removal history; reviewed restore required'); }
};
