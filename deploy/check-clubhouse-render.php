<?php

if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['stage', 'prod'], true)) { exit(1); }
$target = $argv[1];
$root = '/home/runcloud/webapps/ClubUnio'.ucfirst($target);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$step = 'bootstrap';
set_exception_handler(static function (Throwable $error) use (&$step): never {
    $status = $error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 0;
    fwrite(STDERR, 'Clubhouse render failed: '.$step.' '.$error::class.' HTTP '.$status.' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    exit(1);
});

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, View};

$db = DB::connection('clubunio');
$origin = $target === 'stage' ? 'https://stage.clubunio.com' : 'https://app.clubunio.com';
if (config('app.url') !== $origin || $db->getDatabaseName() !== 'clubunio_'.$target) { exit(1); }
Http::preventStrayRequests(); Mail::fake(); Queue::fake();
$session = $app['session']->driver('array');
$session->start();
View::share('errors', new Illuminate\Support\ViewErrorBag);
$checks = 0;
$db->beginTransaction();
try {
    $db->statement("SET LOCAL statement_timeout='30s'");
    $id = random_int(-2000000000, -1000000000);
    $db->table('clubunio.tenants')->insert(['id' => $id, 'name' => 'Synthetic render', 'slug' => 'clubhouse-render-'.abs($id)]);
    $db->table('clubunio.accounts')->insert(['id' => $id, 'username' => 'clubhouse-render-'.abs($id).'@example.invalid',
        'display_name' => 'Synthetic render', 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'active' => true, 'api_only' => false]);
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $id, 'account_id' => $id, 'role' => 'admin', 'portal_enabled' => true]);
    $tenant = Tenant::findOrFail($id);
    $actor = Account::findOrFail($id);
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    Filament::auth()->setUser($actor);
    Filament::setTenant($tenant, isQuiet: true);
    $request = Illuminate\Http\Request::create($origin.'/clubhouse');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $actor);
    $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/clubhouse', fn () => null))->name('filament.clubunio.pages.clubhouse'));
    $app->instance('request', $request);
    $step = 'tenant selection';
    app(ActiveTenant::class)->select($request, $tenant);
    $step = 'panel boot';
    Filament::bootCurrentPanel();
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['overview', 'hours', 'menus', 'access'] as $tab) {
            $step = $locale.'/'.$tab;
            $html = (string) Livewire\Livewire::mount('clubunio.clubhouse-workspace', ['tenantId' => $id, 'tab' => $tab]);
            if (strlen($html) < 500 || preg_match('/clubhouse\.[a-z_]+/', strip_tags($html))) {
                throw new LogicException('Clubhouse render failed: '.$locale.'/'.$tab);
            }
            $checks++;
        }
    }
} finally {
    $db->rollBack();
}
if ($db->table('clubunio.tenants')->where('id', $id)->exists()) { exit(1); }
echo "Clubhouse {$target} DE/EN render: {$checks} checks; synthetic records rolled back.\n";
