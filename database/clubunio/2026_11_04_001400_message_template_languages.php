<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MessageTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        DB::connection('clubunio')->statement("SET LOCAL lock_timeout = '15s'");
        // Existing locale identity/unique constraint also identifies revision language.
        // Seed only missing variants; never replace tenant text or revision history.
        Tenant::withoutGlobalScopes()->orderBy('id')->each(fn ($tenant) => app(MessageTemplateService::class)->seed($tenant));
    }

    public function down(): void
    {
        throw new LogicException('Retain translated templates and their revision history during rollback.');
    }
};
