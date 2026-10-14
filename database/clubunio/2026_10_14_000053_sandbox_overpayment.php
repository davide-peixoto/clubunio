<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared("ALTER TABLE clubora.bexio_financial_actions DROP CONSTRAINT bexio_financial_actions_operation_check;
            ALTER TABLE clubora.bexio_financial_actions ADD CONSTRAINT bexio_financial_actions_operation_check
            CHECK(operation IN ('payment_partial','payment_full','payment_overpaid','bill_book','bill_pay'));");
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
