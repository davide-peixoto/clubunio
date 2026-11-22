<?php

// Operator-only provider validation. validate_only=true never delivers a message.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Explicit --verify required\n"); exit(1);
}
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://app.clubunio.com') throw new LogicException('Unexpected environment');
    $path = '/home/runcloud/firebase/prod/service-account.json';
    if (! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) > 32768
        || (fileperms($path) & 0777) !== 0600) throw new LogicException('Private file unavailable');
    $c = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (($c['type'] ?? '') !== 'service_account' || ($c['project_id'] ?? '') !== 'clubunio-e8d01'
        || ($c['client_email'] ?? '') !== 'clubunio-prod-push@clubunio-e8d01.iam.gserviceaccount.com'
        || ($c['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token'
        || ! openssl_pkey_get_private($c['private_key'] ?? '')) throw new LogicException('Unexpected credential');
    $auth = new Google\Auth\Credentials\ServiceAccountCredentials(['https://www.googleapis.com/auth/firebase.messaging'], $c);
    $token = $auth->fetchAuthToken(function (Psr\Http\Message\RequestInterface $request) {
        if ((string) $request->getUri() !== 'https://oauth2.googleapis.com/token' || $request->getMethod() !== 'POST') {
            throw new LogicException('Unexpected OAuth target');
        }
        $r = Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withBody((string) $request->getBody(), 'application/x-www-form-urlencoded')->post('https://oauth2.googleapis.com/token');
        return new GuzzleHttp\Psr7\Response($r->status(), ['Content-Type' => 'application/json'], $r->body());
    });
    if (! is_string($token['access_token'] ?? null) || ($token['expires_in'] ?? 0) < 1) throw new RuntimeException('OAuth unavailable');
    $r = Illuminate\Support\Facades\Http::withToken($token['access_token'])->connectTimeout(5)->timeout(20)->withoutRedirecting()
        ->post('https://fcm.googleapis.com/v1/projects/clubunio-e8d01/messages:send', [
            'validate_only' => true, 'message' => ['topic' => 'clubunio-configuration-validation',
                'data' => ['check' => 'configuration-validation-only']],
        ]);
    echo json_encode(['oauth_ready' => true, 'fcm_validation_status' => $r->status(),
        'validate_only' => true, 'messages_delivered' => 0], JSON_THROW_ON_ERROR), PHP_EOL;
    exit($r->successful() ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Push credential check failed: '.get_class($error)."\n"); exit(1);
}
