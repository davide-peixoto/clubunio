<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = in_array('--enabled', $argv, true);
if (! $enabled && ! in_array('--foundation', $argv, true)) { throw new LogicException('Explicit document release stage required'); }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected document database'); }
if ((bool) config('clubunio-mobile.native_documents_enabled', false) !== $enabled) { throw new LogicException('Document release stage mismatch'); }
$count = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileMemberDocumentController::class.'@')) {
        foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobileMemberDocumentReady::class] as $middleware) {
            if (! in_array($middleware, $route->gatherMiddleware(), true)) { throw new LogicException('Ungated personal document route'); }
        }
        $count++;
    }
}
if ($count !== 12) { throw new LogicException('Unexpected personal document route inventory: '.$count); }
if ($enabled) {
    $schema = $db->getSchemaBuilder();
    foreach (['original_sha256', 'original_pdf_encrypted', 'archive_receipt_id', 'archive_source_hash', 'signature_fingerprint', 'strokes_encrypted', 'signed_sha256', 'signed_pdf_encrypted', 'revision'] as $column) {
        if (! $schema->hasColumn('clubora.mobile_member_receipts', $column)) { throw new LogicException('Missing private receipt schema'); }
    }
    if (! $db->table('clubora.migrations')->where('migration', '2026_11_03_000089_mobile_member_receipts')->exists()) { throw new LogicException('Document migration ledger incomplete'); }
    foreach (['setasign/fpdi' => '2.6.9', 'setasign/fpdf' => '1.9.0', 'symfony/mime' => '7.4.22'] as $package => $minimum) {
        if (! Composer\InstalledVersions::isInstalled($package) || version_compare(ltrim(Composer\InstalledVersions::getPrettyVersion($package), 'v'), $minimum, '<')) {
            throw new LogicException('Reviewed PDF/MIME dependency unavailable');
        }
    }
    if (! class_exists(setasign\Fpdi\Fpdi::class) || ! class_exists(FPDF::class) || ! config('clubunio-mobile.uploads_enabled')) { throw new LogicException('Document runtime unavailable'); }
}
echo 'Personal documents '.($enabled ? 'enabled schema/runtime' : 'closed foundation')." and12 protected routes verified; no document read or signature performed.\n";
