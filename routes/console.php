<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\TeamPlanningImporter;
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

require __DIR__.'/clubunio-console.php';

Artisan::command('clubunio:planning-import', function () {
    app(TeamPlanningImporter::class)->run();
    $this->info('Planning snapshot imported (idempotent).');
});
Artisan::command('clubunio:planning-reminders', function () {
    foreach (Tenant::all() as $tenant) {
        $count = app(TeamPlanningService::class)->reminders($tenant);
        $this->info($tenant->slug.': '.$count.' reminder events (internal only).');
    }
});
Schedule::command('clubunio:planning-reminders')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('clubunio:bexio-preview {tenant} {actor} {--type=invoice} {--after=0} {--limit=10}', function () {
    $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
    $actor = Account::findOrFail((int) $this->argument('actor'));
    $service = app(BexioReconciliationService::class);
    $service->authorize($actor, $tenant);
    $type = $this->option('type');
    if (! in_array($type, ['invoice', 'expense'], true)) {
        $this->error('Invalid type');

        return 1;
    }
    $model = $type === 'invoice' ? Invoice::class : Expense::class;
    $field = $type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';
    $rows = $model::where('tenant_id', $tenant->id)->where('active', true)->where('id', '>', max(0, (int) $this->option('after')))
        ->whereNotNull($field)->where($field, '<>', '')->where($field, '<>', '-')->orderBy('id')->limit(max(1, min(25, (int) $this->option('limit'))))->get();
    $deadline = microtime(true) + 45;
    $cursor = max(0, (int) $this->option('after'));
    $failed = 0;
    foreach ($rows as $row) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $check = $service->preview($actor, $tenant, $type, $row->id);
        $state = DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $check)->value('state');
        $cursor = $row->id;
        $failed += $state === 'failed' ? 1 : 0;
        $this->line('Check '.$check.': '.$state);
    }
    $this->info('Next --after='.$cursor.'; errors='.$failed.'; preview only, no status applied.');

    return $failed ? 1 : 0;
});
