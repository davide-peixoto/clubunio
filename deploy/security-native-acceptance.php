<?php

/**
 * Explicit operator-run Stage/PROD acceptance, not a deploy/migration command.
 * Usage: php security-native-acceptance.php --environment=stage
 * Optional --exercise-inactive-backchannel enables only process-local flags.
 * All synthetic SQL is enclosed by an outer transaction and rolled back.
 * PostgreSQL identity sequences can advance; no real account is used/changed.
 */

use App\ClubUnio\Api\{ApiContext, ApiTokenService};
use App\ClubUnio\Mobile\MobileSessions;
use App\ClubUnio\Models\{AccessProfile, Account, ApiAccessToken, Tenant};
use App\ClubUnio\Services\{AccountSecurity, DocumentSafety, KeycloakBackchannelLogout, KeycloakLogoutState, KeycloakMobileAssurance, KeycloakWebAssurance};
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, DB, Http, Mail, Queue};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$checks = [];
$phase = 'arguments';
$db = null;
$marker = null;
$cacheCleanup = [];
$rolledBack = false;
$failure = null;
$runtimeFlags = [];
$environment = null;

function securityNativeCheck(bool $condition, string $label): void
{
    global $checks, $phase;
    $phase = $label;
    if (! $condition) {
        throw new RuntimeException('Acceptance assertion failed');
    }
    $checks[] = $label;
}

function securityNativeReject(callable $operation, int $status, string $label): void
{
    try {
        $operation();
    } catch (HttpExceptionInterface $error) {
        securityNativeCheck($error->getStatusCode() === $status, $label);
        return;
    }
    securityNativeCheck(false, $label);
}

try {
    if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
        throw new RuntimeException('Dedicated unprivileged CLI user required');
    }
    $options = getopt('', ['environment:', 'exercise-inactive-backchannel']);
    $environment = $options['environment'] ?? null;
    if (! in_array($environment, ['stage', 'prod'], true)) {
        throw new RuntimeException('Exact target required');
    }
    $root = '/home/runcloud/webapps/'.($environment === 'stage' ? 'ClubUnioStage' : 'ClubUnioProd');
    $origin = $environment === 'stage' ? 'https://stage.clubunio.com' : 'https://app.clubunio.com';
    if (realpath($root) !== $root || ! is_readable($root.'/vendor/autoload.php')) {
        throw new RuntimeException('Reviewed canonical root required');
    }
    $osUser = posix_getpwuid(posix_geteuid())['name'] ?? '';
    if ($osUser === '' || in_array($osUser, ['root', 'runcloud', 'postgres'], true)) {
        throw new RuntimeException('Dedicated application UID required');
    }
    chdir($root);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $app->instance('request', Request::create($origin.'/'));
    $phase = 'runtime_guards';
    securityNativeCheck(rtrim((string) config('app.url'), '/') === $origin, 'canonical_origin');
    $db = DB::connection('clubunio');
    securityNativeCheck($db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'clubunio_'.$environment, 'exact_pg_database');
    $role = $db->selectOne('SELECT current_user AS name, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname=current_user');
    securityNativeCheck($role && $role->name === 'clubunio_'.$environment.'_app'
        && ! $role->rolsuper && ! $role->rolcreatedb && ! $role->rolcreaterole, 'least_privilege_runtime_role');
    securityNativeCheck((new Tenant)->getTable() === 'clubunio.tenants'
        && (new Account)->getTable() === 'clubunio.accounts', 'native_schema');
    securityNativeCheck($db->transactionLevel() === 0, 'no_existing_transaction');

    // No network provider, mail or queued side effect is permitted in this process.
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $backchannelStore = config('clubunio-keycloak-backchannel.cache_store');
    securityNativeCheck(is_string($backchannelStore)
        && in_array(config('cache.stores.'.$backchannelStore.'.driver'), ['redis', 'database'], true), 'shared_backchannel_store_configured');
    $runtimeFlags = ['backchannel_enabled' => config('clubunio-keycloak-backchannel.enabled') === true,
        'logout_state_enforced' => config('clubunio-keycloak-backchannel.state_enforced') === true];
    if (! $runtimeFlags['backchannel_enabled'] || ! $runtimeFlags['logout_state_enforced']) {
        securityNativeCheck(array_key_exists('exercise-inactive-backchannel', $options), 'inactive_backchannel_explicit_process_opt_in');
        config(['clubunio-keycloak-backchannel.enabled' => true, 'clubunio-keycloak-backchannel.state_enforced' => true]);
    }
    // API admission counters are intentionally process-local; actual shared replay
    // storage remains configured under its original explicit store name.
    config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array'],
        'session.driver' => 'array', 'logging.default' => 'stderr']);
    Cache::setDefaultDriver('array');
    $app->forgetInstance(\Illuminate\Cache\RateLimiter::class);

    $marker = 'security-native-'.bin2hex(random_bytes(10));
    securityNativeCheck(! $db->table('clubunio.tenants')->where('slug', 'like', $marker.'%')->exists()
        && ! $db->table('clubunio.accounts')->where('username', 'like', $marker.'%')->exists(), 'unique_synthetic_namespace');
    $db->beginTransaction();
    $db->statement("SET LOCAL statement_timeout='8000ms'");
    $db->statement("SET LOCAL lock_timeout='1500ms'");
    $db->statement("SET LOCAL idle_in_transaction_session_timeout='120s'");
    $transactionId = (string) $db->selectOne('SELECT txid_current() AS id')->id;

    $one = Tenant::create(['name' => $marker.' one', 'slug' => $marker.'-a']);
    $two = Tenant::create(['name' => $marker.' two', 'slug' => $marker.'-b']);
    $account = function (string $suffix) use ($marker): Account {
        $actor = new Account;
        $actor->forceFill(['username' => $marker.'-'.$suffix.'@example.invalid', 'display_name' => 'Synthetic security acceptance',
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'active' => true, 'api_only' => false])->save();
        return $actor;
    };
    $admin = $account('admin');
    $otherAdmin = $account('foreign');
    $admin->tenants()->attach($one, ['role' => 'admin', 'portal_enabled' => true]);
    $otherAdmin->tenants()->attach($two, ['role' => 'admin', 'portal_enabled' => true]);
    $ownTeam = $db->table('clubunio.teams')->insertGetId(['tenant_id' => $one->id, 'name' => $marker.' own']);
    $foreignTeam = $db->table('clubunio.teams')->insertGetId(['tenant_id' => $two->id, 'name' => $marker.' foreign']);
    $profile = new AccessProfile;
    $profile->forceFill(['tenant_id' => $one->id, 'name' => $marker, 'active' => true, 'permissions' => ['teams.view']])->save();
    $tokens = app(ApiTokenService::class);
    $integration = $tokens->createIntegration($admin, $one, ['name' => $marker, 'access_profile_id' => $profile->id,
        'scopes' => ['teams:read'], 'rate_limit' => 60]);
    $issued = $tokens->issueToken($admin, $one, $integration->id, $marker, ['teams:read'], now()->addMinutes(10));
    $context = new ApiContext($integration->account, $one, $integration, ApiAccessToken::findOrFail($issued['id']));
    securityNativeCheck($tokens->isAdmin($admin, $one) && ! $tokens->isAdmin($admin, $two), 'admin_tenant_boundary');
    $context->authorize('teams:read', 'teams', 'view');
    securityNativeCheck($integration->account->api_only === true, 'technical_principal_only');

    // Operator-only CLI process exception; public FPM requests remain in maintenance.
    \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::except(['api/v1/*']);
    $kernel = $app->make(HttpKernel::class);
    $request = function (string $path, ?string $token = null, ?string $hostOrigin = null) use ($kernel, $origin, $db, $transactionId): array {
        Auth::forgetGuards();
        $headers = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $incoming = Request::create(($hostOrigin ?? $origin).$path, 'GET', [], [], [], $headers);
        $response = $kernel->handle($incoming);
        $data = json_decode($response->getContent(), true);
        $result = ['status' => $response->getStatusCode(), 'data' => $data,
            'no_store' => str_contains((string) $response->headers->get('Cache-Control'), 'no-store'),
            'nosniff' => $response->headers->get('X-Content-Type-Options') === 'nosniff'];
        $kernel->terminate($incoming, $response);
        if ($db->transactionLevel() !== 1 || (string) $db->selectOne('SELECT txid_current() AS id')->id !== $transactionId) {
            throw new RuntimeException('Outer transaction lost');
        }
        return $result;
    };
    $own = $request('/api/v1/teams/'.$ownTeam, $issued['token']);
    securityNativeCheck($own['status'] === 200 && (int) ($own['data']['data']['id'] ?? 0) === (int) $ownTeam, 'http_own_team_200');
    securityNativeCheck($own['no_store'] && $own['nosniff'], 'http_private_security_headers');
    $foreign = $request('/api/v1/teams/'.$foreignTeam, $issued['token']);
    securityNativeCheck($foreign['status'] === 404 && ! isset($foreign['data']['data']), 'http_cross_tenant_team_404');
    $list = $request('/api/v1/teams', $issued['token']);
    securityNativeCheck($list['status'] === 200 && count($list['data']['data'] ?? []) === 1
        && (int) $list['data']['data'][0]['id'] === (int) $ownTeam, 'http_list_only_own_tenant');
    $search = $request('/api/v1/teams?search='.rawurlencode($marker.' foreign'), $issued['token']);
    securityNativeCheck($search['status'] === 200 && ($search['data']['data'] ?? null) === [], 'http_search_no_foreign_results');
    securityNativeCheck($request('/api/v1/teams?tenant_id='.$two->id, $issued['token'])['status'] === 422, 'http_tenant_override_422');
    securityNativeCheck($request('/api/v1/teams')['status'] === 401, 'http_missing_bearer_401');
    securityNativeCheck($request('/api/v1/teams', 'synthetic-invalid')['status'] === 401, 'http_malformed_bearer_401');
    securityNativeCheck($request('/api/v1/members', $issued['token'])['status'] === 403, 'http_missing_scope_403');
    $contextResponse = $request('/api/v1/context', $issued['token']);
    securityNativeCheck($contextResponse['status'] === 200 && (int) ($contextResponse['data']['data']['tenant']['id'] ?? 0) === (int) $one->id,
        'http_context_bound_to_token');
    config(['clubunio-domains.hosts' => ['a.security.invalid' => $one->slug, 'b.security.invalid' => $two->slug]]);
    securityNativeCheck($request('/api/v1/teams/'.$ownTeam, $issued['token'], 'https://a.security.invalid')['status'] === 200, 'http_own_domain_200');
    securityNativeCheck($request('/api/v1/teams/'.$ownTeam, $issued['token'], 'https://b.security.invalid')['status'] === 404, 'http_foreign_domain_404');
    securityNativeCheck($request('/api/v1/teams/'.$ownTeam, $issued['token'], 'https://unknown.security.invalid')['status'] === 404, 'http_unknown_domain_404');
    $tokens->revokeToken($admin, $one, $issued['id']);
    securityNativeReject(fn () => $context->authorize('teams:read', 'teams', 'view'), 403, 'loaded_api_context_revocation_403');
    securityNativeCheck($request('/api/v1/teams/'.$ownTeam, $issued['token'])['status'] === 401, 'http_revoked_token_401');

    $phase = 'real_clamav_scan';
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(100, 10, 'Synthetic security acceptance. No real member data.');
    app(DocumentSafety::class)->assertClean($pdf->Output('S'));
    securityNativeCheck(true, 'live_scanner_clean_pdf_accepted');
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    try {
        app(DocumentSafety::class)->assertClean($eicar);
        securityNativeCheck(false, 'live_scanner_eicar_rejected');
    } catch (ValidationException $error) {
        securityNativeCheck(isset($error->errors()['file']), 'live_scanner_eicar_rejected');
    }
    unset($pdf, $eicar);

    $phase = 'synthetic_backchannel_revocation';
    $issuer = config('clubunio-verein-oidc.issuer');
    $mobileLevel = KeycloakMobileAssurance::level();
    $webLevel = KeycloakWebAssurance::requiredLevel();
    securityNativeCheck(is_string($issuer) && str_starts_with($issuer, 'https://') && $mobileLevel !== null, 'configured_mfa_and_issuer');
    $subject = $marker.'-subject';
    $db->table('clubunio.keycloak_account_links')->insert(['issuer' => $issuer, 'subject' => $subject, 'account_id' => $admin->id]);
    // Native instances may intentionally use operator-approved subject maps with
    // invitations disabled. Extend that map only inside this synthetic CLI process.
    config(['clubunio-verein-oidc.subjects' => (array) config('clubunio-verein-oidc.subjects') + [$subject => (int) $admin->id]]);
    $oldClaims = (object) ['iss' => $issuer, 'sub' => $subject, 'iat' => time() - 30, 'auth_time' => time() - 30, 'acr' => $mobileLevel];
    $stamp = app(KeycloakMobileAssurance::class)->stamp($admin, $oldClaims);
    securityNativeCheck(is_string($stamp), 'synthetic_strong_mobile_stamp');
    $webClaims = clone $oldClaims;
    $webClaims->acr = $webLevel;
    $proof = KeycloakWebAssurance::proof($admin, $webClaims);
    securityNativeCheck(KeycloakWebAssurance::acceptsSession($admin, $proof), 'web_assurance_before_logout');
    $sessionId = (string) Str::uuid();
    $secret = bin2hex(random_bytes(32));
    $db->table('clubunio.mobile_sessions')->insert(['id' => $sessionId, 'account_id' => $admin->id,
        'token_hash' => hash('sha256', $secret), 'device_name' => $marker, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        'created_at' => now()->subMinute()->toIso8601String(), 'security_stamp' => $stamp]);
    $appRequest = Request::create($origin.'/api/mobile/v1/context', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$sessionId.'.'.$secret]);
    $app->instance('request', $appRequest);
    securityNativeCheck((int) app(MobileSessions::class)->authenticate($appRequest)->id === (int) $admin->id, 'app_token_before_logout');
    $event = (object) ['iss' => $issuer, 'sub' => $subject, 'aud' => config('clubunio-verein-oidc.client'),
        'iat' => time() - 10, 'exp' => time() + 120, 'jti' => $marker.'-event',
        'events' => (object) [KeycloakBackchannelLogout::EVENT => (object) []]];
    securityNativeCheck(KeycloakBackchannelLogout::validClaims($event, $issuer, [$event->aud], time()), 'synthetic_logout_claims_valid');
    $replayKey = 'keycloak-backchannel:'.hash('sha256', $issuer.'|'.$event->jti);
    $cacheCleanup[] = [$backchannelStore, $replayKey];
    $logout = app(KeycloakBackchannelLogout::class);
    $logout->revoke($event);
    securityNativeCheck(KeycloakLogoutState::cutoff($admin) === $event->iat, 'persistent_logout_floor');
    securityNativeCheck($db->table('clubunio.mobile_sessions')->where('id', $sessionId)->value('revoked_at') !== null, 'sql_app_token_revoked');
    securityNativeCheck(! hash_equals($stamp, app(KeycloakMobileAssurance::class)->stamp($admin)), 'old_pair_stamp_invalidated');
    securityNativeCheck(! KeycloakWebAssurance::acceptsSession($admin, $proof), 'web_assurance_after_logout_denied');
    securityNativeReject(fn () => app(MobileSessions::class)->authenticate($appRequest), 401, 'app_token_after_logout_401');
    securityNativeCheck(! KeycloakLogoutState::accepts($admin, $oldClaims), 'late_old_claims_denied');
    securityNativeReject(fn () => KeycloakWebAssurance::proof($admin, $webClaims), 403, 'late_old_web_proof_denied');
    $logout->revoke($event);
    securityNativeCheck(KeycloakLogoutState::cutoff($admin) === $event->iat, 'logout_replay_idempotent');
    securityNativeCheck(KeycloakLogoutState::cutoff($otherAdmin) === 0, 'logout_other_account_unchanged');
    $freshClaims = clone $oldClaims;
    $freshClaims->iat = time();
    $freshClaims->auth_time = time();
    securityNativeCheck(KeycloakLogoutState::accepts($admin, $freshClaims), 'fresh_claims_after_logout_accepted');
    securityNativeCheck(Http::recorded()->count() === 0
        && Mail::sent(\Illuminate\Contracts\Mail\Mailable::class)->isEmpty()
        && Mail::queued(\Illuminate\Contracts\Mail\Mailable::class)->isEmpty()
        && Queue::pushedJobs() === [], 'no_external_http_mail_or_jobs');
    securityNativeCheck($db->transactionLevel() === 1 && (string) $db->selectOne('SELECT txid_current() AS id')->id === $transactionId,
        'outer_transaction_preserved');
} catch (Throwable $error) {
    $failure = ['phase' => $phase, 'class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine()];
} finally {
    try {
        foreach ($cacheCleanup as [$store, $key]) {
            try {
                Cache::store($store)->forget($key);
            } catch (Throwable $cacheError) {
                // Cache failure must never prevent the SQL rollback below.
                $failure = ['phase' => 'synthetic_cache_cleanup', 'class' => $cacheError::class];
            }
        }
        if ($db !== null) {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($marker !== null) {
                $rolledBack = ! $db->table('clubunio.tenants')->where('slug', 'like', $marker.'%')->exists()
                    && ! $db->table('clubunio.accounts')->where('username', 'like', $marker.'%')->exists()
                    && ! $db->table('clubunio.teams')->where('name', 'like', $marker.'%')->exists()
                    && ! $db->table('clubunio.keycloak_account_links')->where('subject', $marker.'-subject')->exists();
                if (! $rolledBack && $failure === null) {
                    $failure = ['phase' => 'rollback_verification', 'class' => RuntimeException::class];
                }
            }
        }
    } catch (Throwable $cleanupError) {
        $failure = ['phase' => 'cleanup_or_rollback', 'class' => $cleanupError::class,
            'file' => basename($cleanupError->getFile()), 'line' => $cleanupError->getLine()];
    }
}

echo json_encode(['status' => $failure === null && $rolledBack ? 'passed' : 'failed', 'environment' => $environment,
    'checks_passed' => count($checks), 'checks' => $checks, 'rolled_back' => $rolledBack,
    'initial_runtime_flags' => $runtimeFlags, 'sequence_advances_possible' => true,
    'limits' => ['in_process_http_not_external_network', 'no_idp_end_to_end', 'no_multi_process_race'],
    'failure' => $failure], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failure === null && $rolledBack ? 0 : 1);
