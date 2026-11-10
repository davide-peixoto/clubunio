<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared('ALTER TABLE clubunio.bexio_balances ADD COLUMN cash_received_cents bigint CHECK(cash_received_cents>=0), ADD COLUMN cash_excess_cents bigint CHECK(cash_excess_cents>=0);');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
