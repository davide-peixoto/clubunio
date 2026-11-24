<?php

// Only synthetic records inside a rolled-back DEV transaction; no outbound calls.
require __DIR__.'/verify-pi-dev-vps-database.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Clubhouse acceptance failed: '.$error::class.' at line '.$error->getLine()."\n");
    exit(1);
});
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseHours;
use App\ClubUnio\Services\ClubhouseMenus;
use App\ClubUnio\Services\ClubhouseService;
use Illuminate\Support\Facades\Http;

$checks = 0;
$assert = function (bool $ok) use (&$checks) {
    if (! $ok) {
        throw new LogicException('Clubhouse acceptance assertion '.($checks + 1));
    } $checks++;
};
$assert(config('app.url') === 'https://dev.clubunio.ch');
$assert($db->table('clubunio.migrations')->where('migration', '2026_11_24_030000_clubhouse')->exists());
$before = $db->table('clubunio.tenant_accounts')->count();
Http::preventStrayRequests();
$db->beginTransaction();
try {
    $id = random_int(-2000000000, -1000000000);
    $db->table('clubunio.tenants')->insert(['id' => $id, 'name' => 'Synthetic clubhouse review', 'slug' => 'clubhouse-review-'.abs($id)]);
    foreach ([$id => 'admin', $id + 1 => 'trainer'] as $accountId => $role) {
        $db->table('clubunio.accounts')->insert(['id' => $accountId, 'username' => 'clubhouse-'.abs($accountId).'@example.invalid',
            'display_name' => 'Synthetic clubhouse '.$role, 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'active' => true, 'api_only' => false]);
        $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $id, 'account_id' => $accountId, 'role' => $role, 'portal_enabled' => true]);
    }
    $tenant = Tenant::findOrFail($id);
    $admin = Account::findOrFail($id);
    $trainer = Account::findOrFail($id + 1);
    $access = app(ClubhouseAccess::class);
    $assert($access->administrator($admin, $tenant));
    $assert(! $access->allows($trainer, $tenant, 'view'));
    $access->grant($admin, $tenant, $trainer->id, ['hours_write' => true, 'menus_write' => true, 'menus_publish' => false,
        'active' => true, 'revision' => 0, 'reason' => 'Synthetic DEV acceptance']);
    $assert($access->allows($trainer, $tenant, 'hours.write'));
    $assert(! $access->allows($trainer, $tenant, 'menus.publish'));
    $hours = app(ClubhouseHours::class);
    $today = now('Europe/Zurich')->toDateString();
    $hours->save($trainer, $tenant, null, ['title' => 'Synthetic opening', 'starts_on' => $today,
        'weekdays' => [], 'active' => true, 'intervals' => [['start' => '18:00', 'end' => '22:00', 'overnight' => false]]]);
    $assert(count($hours->calendar($tenant, $today, $today)[0]['intervals']) === 1);
    $menus = app(ClubhouseMenus::class);
    $values = ['title_de' => 'Synthetisches Menü', 'title_en' => 'Synthetic menu', 'body_de' => 'Suppe', 'body_en' => 'Soup',
        'valid_from' => $today, 'valid_until' => $today, 'channels' => ['web', 'app']];
    $menu = $menus->save($trainer, $tenant, null, $values);
    $assert($menus->published($tenant, 'web') === []);
    $menus->transition($admin, $tenant, $menu, 1, 'publish');
    $assert(count($menus->published($tenant, 'app')) === 1);
    $menus->save($trainer, $tenant, $menu, array_replace($values, ['body_de' => 'Draft only', 'revision' => 2]));
    app()->setLocale('de');
    $assert($menus->published($tenant, 'web')[0]['body'] === 'Suppe');
    $service = app(ClubhouseService::class);
    $assert(! $service->publicEnabled($tenant));
    $service->settings($admin, $tenant, ['public_enabled' => true, 'timezone' => 'Europe/Zurich', 'revision' => 0]);
    $assert($service->publicEnabled($tenant));
    $assert(count($service->management($trainer, $tenant)['menus']) === 1);
    $assert(! $db->table('clubunio.clubhouse_events')->where('tenant_id', $id)->get()->isEmpty());
    $assert($db->table('clubunio.tenant_accounts')->where('account_id', $trainer->id)->value('role') === 'trainer');
} finally {
    $db->rollBack();
}
$assert($db->table('clubunio.tenant_accounts')->count() === $before);
$assert(! $db->table('clubunio.tenants')->where('id', $id)->exists());
echo "Clubhouse DEV acceptance: {$checks} checks; all synthetic records rolled back.\n";
