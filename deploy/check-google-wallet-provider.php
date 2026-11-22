<?php

// Explicit real-provider synthetic object. No user/card data, save link or token output.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--synthetic-provider'
    || ! in_array($argv[2] ?? '', ['dev', 'stage', 'prod'], true)) exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $client = app(App\ClubUnio\Wallet\GoogleWalletClient::class);
    if (! $client->available() || $client->environment() !== $argv[2]) throw new RuntimeException('configuration');
    // Stable reserved synthetic ID: repeated operator checks do not create extra passes.
    $id = $client->objectId('00000000-0000-4000-8000-000000000001');
    $payload = app(App\ClubUnio\Wallet\GoogleMemberWalletService::class)->payload(
        (object) ['id' => '00000000-0000-4000-8000-000000000001', 'object_id' => $id],
        ['Name' => 'Synthetic test - not a membership', 'TenantName' => 'ClubUnio integration test', 'Valid' => false,
            'PrimaryColor' => '#194e84', 'VerificationUrl' => $client->origin().'/member-card/'.str_repeat('0', 64)], str_repeat('0', 64));
    // Public existing test branding only; no persistent member/artwork fixture needed.
    $payload['logo']['sourceUri']['uri'] = $client->origin().'/images/svs-logo.png';
    $client->upsert($payload); $client->upsert($payload);
    $url = $client->saveUrl($id);
    if (! str_starts_with($url, 'https://pay.google.com/gp/v/save/') || strlen($url) > 1800) throw new RuntimeException('save_link');
    echo json_encode(['environment' => $client->environment(), 'provider_insert_or_reconcile' => true,
        'provider_repeat_patch' => true, 'save_reference_signed' => true, 'synthetic_state' => 'INACTIVE', 'member_data' => false]), PHP_EOL;
} catch (Throwable $error) {
    $code = preg_match('/\Agoogle_wallet_[a-z0-9_]+\z/D', $error->getMessage()) ? $error->getMessage() : get_class($error);
    fwrite(STDERR, 'Synthetic provider check failed: '.$code."\n"); exit(1);
}
