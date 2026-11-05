<?php

// Read-only acceptance against the deployed ClubUnio schema. No notifications or decisions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
    || config('app.url') !== 'https://dev.clubunio.ch') throw new LogicException('ClubUnio target required');
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $db->statement("SET LOCAL statement_timeout = '15s'");
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $groups = app(App\ClubUnio\Services\DashboardReservationRequests::class)->pending($actor, $tenant);
    foreach (['bus' => ['bus_reservations', 'ANGEFRAGT'], 'pitch' => ['pitch_requests', 'pending']] as $kind => [$table, $status]) {
        $query = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('status', $status);
        if ($kind === 'bus') $query->where('active', true);
        if ($groups[$kind]['count'] !== $query->count() || $groups[$kind]['rows']->count() > 5) throw new LogicException('Count/bound mismatch');
    }
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor);
    Filament\Facades\Filament::setTenant($tenant);
    if (! in_array(App\ClubUnio\Widgets\PendingReservationRequests::class, Filament\Facades\Filament::getPanel('clubunio')->getWidgets(), true)) throw new LogicException('Missing dashboard widget');
    foreach (['de' => 'Offene Reservierungsanfragen', 'en' => 'Pending reservation requests'] as $locale => $label) {
        app()->setLocale($locale);
        if (__('Offene Reservierungsanfragen') !== $label) throw new LogicException('Translation missing');
    }
    $url = App\ClubUnio\Pages\PitchPlanning::getUrl(['date' => '2026-11-05', 'booking' => 1]);
    if (! str_contains($url, 'booking=1') || ! str_contains($url, 'date=2026-11-05')) throw new LogicException('Review link missing');
    $trainerId = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('role', 'trainer')->value('account_id');
    if ($trainerId) {
        try {
            app(App\ClubUnio\Services\DashboardReservationRequests::class)->pending(App\ClubUnio\Models\Account::findOrFail($trainerId), $tenant);
            throw new LogicException('Trainer dashboard access unexpected');
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 403) throw $error;
        }
    }
    echo json_encode(['read_only' => true, 'widget_registered' => true, 'counts_match' => true,
        'bounded_lists' => true, 'de_en' => true, 'review_link' => true, 'trainer_denied' => (bool) $trainerId], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->rollBack();
}
} catch (Throwable $error) {
    fwrite(STDERR, 'Dashboard gate failed: '.get_class($error).PHP_EOL);
    exit(1);
}
