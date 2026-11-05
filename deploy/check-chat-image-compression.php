<?php
// Synthetic in-memory codec smoke check. No real upload, database write or provider.
try {
    $root = dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || $root !== '/var/www/svs-laravel/releases/20261105-clubunio-chat-image-compression') throw new LogicException('Fixed release required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !extension_loaded('gd')) throw new LogicException('Unexpected runtime');
    $codec = app(App\ClubUnio\Mobile\ChatImageCompression::class);
    $image = imagecreatetruecolor(2400, 1800);
    imagefilledrectangle($image, 0, 0, 2399, 1799, imagecolorallocate($image, 25, 100, 185));
    ob_start(); imagejpeg($image, null, 100); $source = ob_get_clean(); imagedestroy($image);
    $result = $codec->normalize($source, 'image/jpeg');
    if (strlen($result['bytes']) > 524288 || max($result['width'], $result['height']) > 1600
        || $result['mime'] !== 'image/jpeg' || $result['bytes'] === $source) throw new LogicException('Image bounds failed');
    $alpha = imagecreatetruecolor(16, 16); imagealphablending($alpha, false); imagesavealpha($alpha, true);
    imagefill($alpha, 0, 0, imagecolorallocatealpha($alpha, 100, 50, 150, 127));
    ob_start(); imagepng($alpha); $sourceAlpha = ob_get_clean(); imagedestroy($alpha);
    $transparent = $codec->normalize($sourceAlpha, 'image/png');
    $decoded = imagecreatefromstring($transparent['bytes']);
    if (!$decoded || ((imagecolorat($decoded, 0, 0) >> 24) & 127) !== 127) throw new LogicException('Transparency failed');
    imagedestroy($decoded);
    echo json_encode(['synthetic_only' => true, 'long_edge_max' => 1600,
        'input_bytes' => strlen($source), 'stored_candidate_bytes' => strlen($result['bytes']),
        'output_limit' => 524288, 'alpha_preserved' => true, 'database_write' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Chat compression check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
