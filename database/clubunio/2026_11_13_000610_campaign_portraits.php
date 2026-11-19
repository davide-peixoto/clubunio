<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! StageMigrationTarget::allows($db)) throw new LogicException('Unexpected migration target');
        $db->statement('ALTER TABLE clubunio.member_change_requests ADD COLUMN portrait_encrypted text');
    }

    public function down(): void
    {
        throw new LogicException('Pending portraits require a reviewed restore');
    }
};
