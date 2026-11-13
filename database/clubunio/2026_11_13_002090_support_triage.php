<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if (!StageMigrationTarget::allows($db)) throw new LogicException('Unreviewed database');
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.support_triage (
 ticket_id uuid PRIMARY KEY REFERENCES clubora.support_tickets(id) ON DELETE CASCADE,
 topic text NOT NULL CHECK (topic IN ('login','expense','manual_review')),
 draft text,
 provider_generated boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Support records require a reviewed retention decision.');
    }
};
