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
CREATE TABLE clubunio.support_zammad_exports (
 ticket_id uuid PRIMARY KEY REFERENCES clubunio.support_tickets(id),
 origin text NOT NULL,
 group_id bigint NOT NULL,
 customer_id bigint NOT NULL,
 status text NOT NULL CHECK (status IN ('sending','attention','linked')),
 remote_id bigint,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (origin, remote_id)
);
SQL);
    }
};
