<?php

// Short-lived private script, invoked only over the local FPM socket and removed.
if (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['SECURITY_REVIEW_PROBE'] ?? '') !== '20261125') exit;
$root = '__APP_ROOT__';
$environment = str_ends_with($root, 'Stage') ? 'stage' : 'prod';
$checks = [];
$phase = 'bootstrap';
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    // FPM intentionally disables process-inspection functions. The root socket
    // client independently checks the actual worker UID from process metadata.
    $phase = 'database';
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    $role = $db->selectOne('SELECT current_user AS name,rolsuper,rolcreatedb,rolcreaterole FROM pg_roles WHERE rolname=current_user');
    $checks['least_privilege_db'] = $role->name === 'clubunio_'.$environment.'_app' && !$role->rolsuper && !$role->rolcreatedb && !$role->rolcreaterole;
    $other = '/home/runcloud/webapps/'.($environment === 'stage' ? 'ClubUnioProd' : 'ClubUnioStage').'/.env.postgresql.local';
    $checks['other_environment_unreadable'] = !@is_readable($other);
    $checks['source_not_writable'] = !is_writable($root.'/app/ClubUnio/Services/AccountSecurity.php');
    $checks['runtime_writable'] = is_writable($root.'/storage') && is_writable($root.'/bootstrap/cache');
    $checks['state_enforced'] = config('clubunio-keycloak-backchannel.state_enforced') === true;
    $checks['backchannel_enabled'] = config('clubunio-keycloak-backchannel.enabled') === true;
    $checks['debug_disabled'] = config('app.debug') === false;
    $checks['apple_wallet_ready'] = !config('clubunio-wallet.enabled') || app(App\ClubUnio\Wallet\ApplePassBundle::class)->available();
    $checks['google_wallet_ready'] = !config('clubunio-google-wallet.enabled') || app(App\ClubUnio\Wallet\GoogleWalletClient::class)->available();
    $checks['push_private_files_readable'] = true;
    foreach ((array) config('clubunio-mobile-push.apps', []) as $push) {
        if (is_string($push['credentials_file'] ?? null)) {
            $checks['push_private_files_readable'] = $checks['push_private_files_readable'] && is_readable($push['credentials_file']);
        }
    }
    app(App\ClubUnio\Services\DocumentSafety::class)->assertClean("%PDF-1.4\nSynthetic security scanner probe\n%%EOF");
    $checks['scanner_clean'] = true;
    try {
        app(App\ClubUnio\Services\DocumentSafety::class)->assertClean('X5O!P%@AP[4\\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        $checks['scanner_eicar_rejected'] = false;
    } catch (Illuminate\Validation\ValidationException) {
        $checks['scanner_eicar_rejected'] = true;
    }
    foreach (['de','en'] as $locale) {
        app()->setLocale($locale);
        $checks['translation_'.$locale] = trans('document_safety.rejected') !== 'document_safety.rejected';
    }
    $ok = !in_array(false, $checks, true);
    http_response_code($ok ? 200 : 503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['environment' => $environment, 'passed' => $ok, 'checks' => $checks], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['environment' => $environment, 'passed' => false, 'checks' => $checks, 'phase' => $phase,
        'error_class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR);
}
