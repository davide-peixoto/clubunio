<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!StageMigrationTarget::allows($db)) throw new LogicException('Unreviewed database');
        $db->unprepared('CREATE TABLE clubora.support_public_requests (
            id uuid PRIMARY KEY, tenant_id bigint REFERENCES clubora.tenants(id),
            payload text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
        )');
    }
};
