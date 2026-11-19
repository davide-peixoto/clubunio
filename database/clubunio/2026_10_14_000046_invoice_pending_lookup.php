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
        $db->statement("INSERT INTO clubunio.invoice_line_statuses (tenant_id,code,name) SELECT id,'P','Pendent' FROM clubunio.tenants ON CONFLICT (tenant_id,code) DO NOTHING");
    }

    public function down(): void
    {
        throw new LogicException('Referenced lookup must be retained');
    }
};
