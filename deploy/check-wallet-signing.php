<?php

// Explicit real-certificate smoke test: synthetic pass only, no DB or APNs send.
if (($argv[1] ?? '') !== '--synthetic-signing') {
    fwrite(STDERR, "Explicit synthetic-signing flag required\n"); exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bundle = app(App\ClubUnio\Wallet\ApplePassBundle::class);
$keys = $bundle->credentials();
$pass = [
    'formatVersion' => 1, 'passTypeIdentifier' => config('clubunio-wallet.pass_type_identifier'),
    'teamIdentifier' => config('clubunio-wallet.team_identifier'),
    'serialNumber' => 'synthetic-signing-only', 'organizationName' => 'Synthetic validation',
    'description' => 'wallet.member_card', 'webServiceURL' => $bundle->origin().'/wallet',
    'authenticationToken' => bin2hex(random_bytes(32)),
    'generic' => ['primaryFields' => [['key' => 'test', 'value' => 'Synthetic validation']]],
];
$im = imagecreatetruecolor(29, 29);
ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
$bytes = $bundle->build($pass, array_fill_keys(['icon.png','icon@2x.png','logo.png','logo@2x.png'], $png));
$dir = sys_get_temp_dir().'/wallet-check-'.bin2hex(random_bytes(12));
if (! mkdir($dir, 0700)) throw new RuntimeException('Temporary directory unavailable');
try {
    file_put_contents($dir.'/test.pkpass', $bytes);
    $zip = new ZipArchive;
    if ($zip->open($dir.'/test.pkpass') !== true) throw new RuntimeException('Invalid ZIP');
    $manifest = $zip->getFromName('manifest.json');
    foreach (json_decode($manifest, true, flags: JSON_THROW_ON_ERROR) as $file => $hash) {
        $value = $zip->getFromName($file);
        if (! is_string($value) || ! hash_equals($hash, sha1($value))) throw new RuntimeException('Manifest mismatch');
    }
    file_put_contents($dir.'/manifest.json', $manifest);
    file_put_contents($dir.'/signature', $zip->getFromName('signature'));
    $zip->close();
    // Trust is checked separately against Apple's root before installation.
    // Here verify the actual CMS signature and detached manifest bytes.
    $ok = openssl_cms_verify($dir.'/manifest.json', OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY,
        null, [], $keys['wwdr'], null, null, $dir.'/signature', OPENSSL_ENCODING_DER);
    if (! $ok) throw new RuntimeException('CMS verification failed');
    echo json_encode(['configured' => true, 'signature_verified' => true,
        'pass_type' => $pass['passTypeIdentifier'], 'origin' => $bundle->origin(),
        'bytes' => strlen($bytes), 'synthetic' => true], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
