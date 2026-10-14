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
        foreach (['banks', 'expense_types'] as $table) {
            $db->statement("ALTER TABLE clubora.$table ADD code varchar(80)");
            $db->statement("UPDATE clubora.$table SET code=COALESCE(legacy_id,id)::text");
            $db->statement("CREATE UNIQUE INDEX {$table}_tenant_code ON clubora.$table(tenant_id,code)");
        }
        foreach (['banks', 'expense_types', 'expense_rounds', 'expense_statuses', 'game_types'] as $table) {
            $db->statement("ALTER TABLE clubora.$table ADD sort_order integer NOT NULL DEFAULT 0");
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
