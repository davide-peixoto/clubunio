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

Artisan::command('clubunio:keycloak-test-invite {tenant} {account} {--first-name=} {--last-name=}', function () {
    $db = DB::connection('clubunio');
    abort_unless($db->selectOne('SELECT current_database() AS name')->name === 'clubunio_dev', 403);
    $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
    $account = Account::findOrFail($this->argument('account'));
    $email = (string) $account->username;
    abort_unless(preg_match('/\Akeycloak-qa-[a-z0-9-]+@example\.invalid\z/', $email), 403);
    $code = app(\App\ClubUnio\Services\KeycloakAccountLinks::class)->invite(
        $tenant, $account, $email, $this->option('first-name'), $this->option('last-name'),
    );
    $this->line(rtrim(config('clubunio-verein-oidc.origin'), '/').'/vereinslogin/oidc/invitation');
    $this->line($code);
});

Artisan::command('clubunio:platform-bootstrap {username}', function () {
    app(\App\ClubUnio\Services\PlatformAdministrationService::class)->bootstrap($this->argument('username'));
    $this->info('Initial platform administrator verified. No club permissions changed.');
});

Artisan::command('clubunio:platform-grant {username} {--actor=} {--reason=}', function () {
    $service = app(\App\ClubUnio\Services\PlatformAdministrationService::class);
    $data = \Illuminate\Support\Facades\Validator::make([
        'username' => mb_strtolower(trim($this->argument('username'))),
        'actor' => mb_strtolower(trim((string) $this->option('actor'))),
        'reason' => $this->option('reason'),
    ], [
        'username' => 'required|email|max:255',
        'actor' => 'required|string|max:255',
        'reason' => 'required|string|min:10|max:500',
    ])->validate();

    $actor = Account::whereRaw('lower(username) = ?', [$data['actor']])->first();
    $service->authorize($actor);
    if (! Account::whereRaw('lower(username) = ?', [$data['username']])->exists()) {
        $service->createAccount($actor, [
            'username' => $data['username'],
            'display_name' => $data['username'],
            'password' => $this->secret('New account password'),
            'password_confirmation' => $this->secret('Confirm new account password'),
            'reason' => $data['reason'],
        ]);
    }

    $service->grantAdministrator($actor, $data['username'], $data['reason']);
    $this->info('Platform administrator verified.');
});

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
Artisan::command('clubunio:campaign-test-mail', function () {
    $processed = app(\App\ClubUnio\Services\CampaignMailQueue::class)->runOne();
    $this->line($processed ? 'One queue item processed.' : 'No queue item processed.');
});
if (config('clubunio-campaign-mail.enabled') && app()->environment('staging')) {
    Schedule::command('clubunio:campaign-test-mail')->everyTenSeconds()->withoutOverlapping();
}
Schedule::command('clubunio:expire-mobile-expense-receipts')->hourly()->withoutOverlapping();
Schedule::command('clubunio:expire-mobile-expense-ocr')->hourly()->withoutOverlapping();
Schedule::command('clubunio:assistant:purge --apply')->dailyAt('03:35')->withoutOverlapping();

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
        $state = DB::connection('clubunio')->table('clubunio.bexio_checks')->where('id', $check)->value('state');
        $cursor = $row->id;
        $failed += $state === 'failed' ? 1 : 0;
        $this->line('Check '.$check.': '.$state);
    }
    $this->info('Next --after='.$cursor.'; errors='.$failed.'; preview only, no status applied.');

    return $failed ? 1 : 0;
});
