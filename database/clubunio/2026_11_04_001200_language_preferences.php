<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->statement('ALTER TABLE clubora.tenants ADD COLUMN default_locale varchar(12) NULL');
        $db->statement('ALTER TABLE clubora.accounts ADD COLUMN preferred_locale varchar(12) NULL');
    }

    public function down(): void
    {
        throw new LogicException('Back up language preferences before a reviewed rollback.');
    }
};
