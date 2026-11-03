<?php
// Explicit synthetic transaction; no test account or session is committed.
if (! in_array('--synthetic-rollback', $argv, true)) { throw new RuntimeException('Explicit test mode required'); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new RuntimeException('Unexpected target'); }
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$call = function ($method, $path, $data = [], $token = null) use ($kernel) {
    $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'dev.clubunio.ch', 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [], $server, json_encode($data));
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
$expect = function ($actual, $expected, $stage) { if ($actual !== $expected) { throw new RuntimeException($stage.' unexpected status'); } };
$db->beginTransaction();
try {
    $password = bin2hex(random_bytes(16));
    $changed = bin2hex(random_bytes(16));
    $actor = new App\ClubUnio\Models\Account;
    $actor->forceFill(['username' => 'qa-mobile-shell-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic rollback check',
        'password' => Illuminate\Support\Facades\Hash::make($password), 'active' => true, 'api_only' => false])->save();
    $tenant = App\ClubUnio\Models\Tenant::query()->firstOrFail();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
    [$status, $login] = $call('POST', 'session', ['username' => $actor->username, 'password' => $password, 'device_name' => 'Synthetic rollback']);
    $expect($status, 200, 'login');
    $token = $login['data']['token'];
    [$status, $profile] = $call('GET', 'profile', [], $token);
    $expect($status, 200, 'profile');
    $expect($profile['data']['id'], (int) $actor->id, 'profile owner');
    [$status, $menu] = $call('GET', 'clubs/'.$tenant->id.'/modules', [], $token);
    $expect($status, 200, 'modules');
    $expect(count($menu['data']['modules']), 11, 'module inventory');
    [$status] = $call('POST', 'password', ['current_password' => $password, 'password' => $changed, 'password_confirmation' => $changed], $token);
    $expect($status, 200, 'password');
    [$status] = $call('GET', 'profile', [], $token);
    $expect($status, 401, 'old session revoked');
    [$status] = $call('POST', 'session', ['username' => $actor->username, 'password' => $changed, 'device_name' => 'Synthetic replacement']);
    $expect($status, 200, 'replacement login');
    echo "PG synthetic login/profile/11 modules/password/revocation/relogin passed; transaction rolled back.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Synthetic gate failed: '.get_class($e).': '.$e->getMessage()."\n");
    $failed = true;
} finally {
    $db->rollBack();
}
if ($failed ?? false) { exit(1); }
