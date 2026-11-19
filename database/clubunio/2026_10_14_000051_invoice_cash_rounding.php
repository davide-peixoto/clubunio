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
        $db->statement('ALTER TABLE clubunio.invoices ADD COLUMN cash_rounding boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
