<?php

// Operator-only setup. Never enables delivery, collects an outbox or prints credentials.
try {
    $root = '/var/www/svs-laravel/releases/20261105-clubunio-pitch-usability-v3';
    $private = '/var/www/svs-laravel/shared/firebase-setup-20261105';
    $mode = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 2
        || !in_array($mode, ['--configure', '--verify', '--oauth-check'], true)
        || realpath('/var/www/svs-laravel/current') !== $root) throw new LogicException('Fixed ClubUnio release required');
    require $root.'/vendor/autoload.php';
    $application = require $root.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch'
        || config('clubunio-mobile-push.enabled') || config('clubunio-mobile-push.delivery_enabled')) {
        throw new LogicException('Push must remain closed');
    }
    $path = '/etc/clubunio/secrets/firebase-service-account.json';
    if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > 32768
        || (fileperms($path) & 0777) !== 0640 || fileowner($path) !== 0) throw new LogicException('Private credential permissions required');
    $credential = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (($credential['type'] ?? '') !== 'service_account' || ($credential['project_id'] ?? '') !== 'clubunio-e8d01'
        || ($credential['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token'
        || !str_ends_with($credential['client_email'] ?? '', '@clubunio-e8d01.iam.gserviceaccount.com')
        || !openssl_pkey_get_private($credential['private_key'] ?? '')) throw new LogicException('Unexpected service account');
    $apps = ['com.my.clubunio.core' => ['platform' => 'ios', 'origin' => 'https://dev.clubunio.ch',
        'fixed_tenant_id' => null, 'project_id' => $credential['project_id'], 'credentials_file' => $path]];

    if ($mode === '--configure') {
        if (config('clubunio-mobile-push.apps') !== []) throw new LogicException('Registry already configured; do not replace');
        $file = $root.'/.env';
        $backup = $private.'/before.env';
        if (is_link($file) || realpath($private) !== $private || file_exists($backup)) throw new LogicException('Unsafe setup or already applied');
        $before = file_get_contents($file);
        $key = 'CLUBUNIO_MOBILE_PUSH_APPS';
        if (preg_match_all('/^'.preg_quote($key, '/').'=/m', $before) > 1) throw new LogicException('Duplicate registry setting');
        $line = $key."='".json_encode($apps, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."'";
        $after = preg_match('/^'.$key.'=/m', $before)
            ? preg_replace('/^'.$key.'=[^\r\n]*/m', $line, $before)
            : rtrim($before, "\r\n")."\n".$line."\n";
        $oldMask = umask(0077);
        if (file_put_contents($backup, $before, LOCK_EX) !== strlen($before)) throw new LogicException('Backup incomplete');
        $next = $file.'.firebase-next';
        if (file_exists($next) || is_link($next)) throw new LogicException('Temporary config already exists');
        if (file_put_contents($next, $after, LOCK_EX) !== strlen($after)) throw new LogicException('Config write incomplete');
        chmod($next, fileperms($file) & 0777);
        chgrp($next, filegroup($file));
        if (file_get_contents($file) !== $before || !rename($next, $file)) throw new LogicException('Config changed concurrently');
        umask($oldMask);
        echo "Private credential valid; iOS registry prepared. Rebuild release config cache; delivery flags unchanged.\n";
        exit;
    }
    if (config('clubunio-mobile-push.apps') !== $apps) throw new LogicException('Registry mismatch');
    $beforeConfig = require $private.'/before.config.php';
    // Compare the cached source arrays; runtime bootstrapping may add unrelated transient values.
    $afterConfig = require $root.'/bootstrap/cache/config.php';
    $afterConfig['clubunio-mobile-push']['apps'] = $beforeConfig['clubunio-mobile-push']['apps'];
    if ($beforeConfig !== $afterConfig) throw new LogicException('Unexpected cached configuration change');
    if ($mode === '--oauth-check') {
        $auth = new Google\Auth\Credentials\ServiceAccountCredentials(['https://www.googleapis.com/auth/firebase.messaging'], $credential);
        $token = $auth->fetchAuthToken(function (Psr\Http\Message\RequestInterface $request) {
            if ((string)$request->getUri() !== 'https://oauth2.googleapis.com/token' || $request->getMethod() !== 'POST') {
                throw new LogicException('Unexpected OAuth destination');
            }
            $response = Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->withBody((string)$request->getBody(), 'application/x-www-form-urlencoded')
                ->post('https://oauth2.googleapis.com/token');
            return new GuzzleHttp\Psr7\Response($response->status(), ['Content-Type' => 'application/json'], $response->body());
        });
        if (!is_string($token['access_token'] ?? null) || ($token['expires_in'] ?? 0) <= 0) throw new LogicException('OAuth token unavailable');
        unset($token, $credential);
        echo "Google OAuth authentication successful. No FCM message submitted; device delivery not tested.\n";
        exit;
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $account = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
        $count = $db->table('clubora.mobile_push_bindings as b')->join('clubora.mobile_push_devices as d', 'd.id', '=', 'b.device_id')
            ->where('b.account_id', $account->id)->whereNull('b.revoked_at')->whereNull('d.revoked_at')
            ->where('d.app_id', 'com.my.clubunio.core')->where('d.platform', 'ios')->where('d.origin', 'https://dev.clubunio.ch')->count();
        echo json_encode(['credential_readable' => true, 'ios_registry_configured' => true,
            'push_enabled' => false, 'delivery_enabled' => false, 'own_non_revoked_ios_bindings' => $count,
            'database_read_only' => true, 'device_delivery_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    // Provider exception messages may contain tokens; never emit the original message or trace.
    fwrite(STDERR, 'Firebase setup stopped: '.get_class($error).". No credentials emitted.\n");
    exit(1);
}
