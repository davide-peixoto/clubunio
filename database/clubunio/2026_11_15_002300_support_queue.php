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
CREATE TABLE clubora.support_jobs (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 queue varchar(255) NOT NULL,
 payload text NOT NULL,
 attempts smallint NOT NULL,
 reserved_at integer,
 available_at integer NOT NULL,
 created_at integer NOT NULL
);
CREATE INDEX support_jobs_queue ON clubora.support_jobs(queue);
SQL);
    }
};
