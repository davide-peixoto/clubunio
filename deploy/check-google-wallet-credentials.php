<?php

// Explicit operator check. No pass creation, device message, key or token output.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify' || ! in_array($argv[2] ?? '', ['dev', 'stage', 'prod'], true)) {
    fwrite(STDERR, "Explicit --verify dev|stage|prod required\n"); exit(1);
}
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $environment = $argv[2];
    $origins = ['dev' => 'https://dev.clubunio.ch', 'stage' => 'https://stage.clubunio.com', 'prod' => 'https://app.clubunio.com'];
    if (config('app.url') !== $origins[$environment]) throw new LogicException('Unexpected environment');
    $path = $environment === 'dev' ? '/var/lib/clubunio-google-wallet/dev/service-account.json'
        : '/home/runcloud/google-wallet/'.$environment.'/service-account.json';
    if (! is_file($path) || ! is_readable($path) || is_link($path) || filesize($path) > 32768
        || (fileperms($path) & 0777) !== 0600) throw new LogicException('Private file unavailable');
    $credential = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (($credential['type'] ?? '') !== 'service_account' || ($credential['project_id'] ?? '') !== 'clubunio-e8d01'
        || ($credential['client_email'] ?? '') !== 'clubunio-wallet-'.$environment.'@clubunio-e8d01.iam.gserviceaccount.com'
        || ($credential['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token'
        || ! openssl_pkey_get_private($credential['private_key'] ?? '')) throw new LogicException('Unexpected credential');
    $auth = new Google\Auth\Credentials\ServiceAccountCredentials(['https://www.googleapis.com/auth/wallet_object.issuer'], $credential);
    $token = $auth->fetchAuthToken(function (Psr\Http\Message\RequestInterface $request) {
        if ((string) $request->getUri() !== 'https://oauth2.googleapis.com/token' || $request->getMethod() !== 'POST') {
            throw new LogicException('Unexpected OAuth target');
        }
        $r = Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withBody((string) $request->getBody(), 'application/x-www-form-urlencoded')->post('https://oauth2.googleapis.com/token');
        return new GuzzleHttp\Psr7\Response($r->status(), ['Content-Type' => 'application/json'], $r->body());
    });
    if (! is_string($token['access_token'] ?? null) || ($token['expires_in'] ?? 0) < 1) throw new RuntimeException('OAuth unavailable');
    $response = Illuminate\Support\Facades\Http::withToken($token['access_token'])->connectTimeout(5)->timeout(20)->withoutRedirecting()
        ->get('https://walletobjects.googleapis.com/walletobjects/v1/genericClass', ['issuerId' => '3388000000023233410', 'maxResults' => 1]);
    echo json_encode(['environment' => $environment, 'oauth_ready' => true, 'wallet_read_status' => $response->status(),
        'wallet_readable' => $response->status() === 200, 'writes' => 0], JSON_THROW_ON_ERROR), PHP_EOL;
    exit($response->status() === 200 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Credential check failed: '.get_class($error)."\n"); exit(1);
}
