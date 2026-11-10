<?php

use App\ClubUnio\Mobile\StageMobilePilot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli' || getcwd() !== '/home/runcloud/webapps/ClubUnioStage') {
    exit(1);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
$pushRegistration = ($argv[1] ?? '') === '--push-registration';
if (! StageMobilePilot::allowsAccount(StageMobilePilot::ACCOUNTS[0])
    || $db->getDatabaseName() !== 'clubunio_stage' || config('clubunio.outbound_enabled')
    || (bool) config('clubunio-mobile-push.enabled') !== $pushRegistration || config('clubunio-mobile-push.delivery_enabled')) {
    throw new LogicException('Expected isolated Stage without outbound delivery.');
}

$file = '/home/runcloud/.clubunio-stage-mobile/accounts.json';
if (($argv[1] ?? '') === '--seed') {
    umask(0077);
    if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700)) {
        exit(1);
    }
    $handle = fopen($file, 'x');
    if (! $handle) {
        exit(1);
    }
    $passwords = [];
    foreach (StageMobilePilot::ACCOUNTS as $account) {
        $passwords[$account] = bin2hex(random_bytes(24));
    }
    $db->transaction(function () use ($db, $passwords, $handle) {
        $tenant = $db->table('clubunio.tenants')->insertGetId(['slug' => StageMobilePilot::TENANT, 'name' => 'Mobile Stage Test']);
        foreach ($passwords as $username => $password) {
            $id = $db->table('clubunio.accounts')->insertGetId([
                'username' => $username, 'password' => Hash::make($password), 'display_name' => 'Mobile Stage Test',
                'active' => true, 'api_only' => false,
            ]);
            $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant, 'account_id' => $id, 'role' => 'office']);
        }
        if (! fwrite($handle, json_encode($passwords, JSON_THROW_ON_ERROR))) {
            throw new LogicException('Could not persist test credentials privately.');
        }
    });
    fclose($handle);
    echo "Isolated Stage test accounts provisioned.\n";
    exit;
}

$passwords = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
$tenant = $db->table('clubunio.tenants')->where('slug', StageMobilePilot::TENANT)->sole()->id;
$other = $db->table('clubunio.tenants')->where('id', '<>', $tenant)->value('id');
$base = 'https://stage.clubunio.com/api/mobile/v1';
$client = Http::acceptJson()->timeout(12);
$expect = function ($response, int $status, string $label): void {
    if ($response->status() !== $status) {
        throw new LogicException($label.' returned HTTP '.$response->status());
    }
};
$expect($client->post($base.'/session', ['username' => 'not-a-pilot@example.invalid', 'password' => 'wrong', 'device_name' => 'Stage audit']), 401, 'Non-pilot login');
foreach ($passwords as $username => $password) {
    $response = $client->post($base.'/session', compact('username', 'password') + ['device_name' => 'Stage HTTP audit']);
    $expect($response, 200, 'Pilot login');
    $token = $response->json('data.token');
    if (! is_string($token) || $token === '') {
        throw new LogicException('Expected Stage session token.');
    }
    $session = Http::acceptJson()->timeout(12)->withToken($token)
        ->withHeaders(['X-ClubUnio-Mobile-Features' => 'personal-push-v1']);
    try {
        $context = $session->get($base.'/context');
        $expect($context, 200, 'Context');
        if ($pushRegistration) {
            if ($context->json('data.capabilities.push') !== true) {
                throw new LogicException('Expected Stage push registration capability.');
            }
            $platform = StageMobilePilot::pushPlatform($username);
            $probe = ['app_id' => 'com.my.clubunio.core', 'platform' => $platform,
                'installation_id' => (string) Illuminate\Support\Str::uuid(),
                'installation_secret' => bin2hex(random_bytes(32))];
            $expect($session->post($base.'/push/devices/status', $probe), 200, 'Pilot device status');
            $probe['platform'] = $platform === 'ios' ? 'android' : 'ios';
            $expect($session->post($base.'/push/devices/status', $probe), 403, 'Wrong test platform');
        }
        foreach (['modules', 'planning/teams', 'my-teams', 'expenses'] as $path) {
            $expect($session->get($base.'/clubs/'.$tenant.'/'.$path), 200, $path);
        }
        $expect($session->get($base.'/clubs/'.$other.'/modules'), 404, 'Other tenant');
    } finally {
        $expect($session->delete($base.'/session'), 200, 'Logout');
    }
}
echo "Stage HTTP login, modules, tenant isolation and logout verified.\n";
