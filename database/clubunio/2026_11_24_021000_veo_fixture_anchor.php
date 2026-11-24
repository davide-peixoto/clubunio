<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! App\ClubUnio\Services\StageMigrationTarget::allows($db)
            && ! (PHP_SAPI === 'cli' && config('app.url') === 'https://dev.clubunio.ch' && $db->getDatabaseName() === 'clubunio_dev')) {
            throw new LogicException('Reviewed ClubUnio target required');
        }
        $db->unprepared($this->sql());
    }

    public function sql(): string
    {
        return 'ALTER TABLE clubora.veo_recording_adjustments ADD COLUMN fixture_starts_at timestamptz;';
    }

    public function down(): void { throw new LogicException('Retain recording anchors; reviewed restore required'); }
};
