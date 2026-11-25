<?php

/** Explicit committed synthetic lifecycle, only for reviewed Stage/PROD E2E. */
use App\ClubUnio\Mobile\MobileSessions;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\{KeycloakCodeExchange, KeycloakMobileAssurance, KeycloakWebAssurance};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Http, Mail, Queue};

function acceptanceExpect(bool $condition, string $code): void
{
    if (!$condition) throw new RuntimeException($code);
}

function acceptancePrivatePath(string $path, string $privateRoot, bool $existing): string
{
    $parent = realpath(dirname($path));
    acceptanceExpect(is_string($parent) && str_starts_with($parent.'/', $privateRoot.'/')
        && !is_link($path) && (fileperms($parent) & 0077) === 0, 'private_parent_required');
    $absolute = $parent.'/'.basename($path);
    if ($existing) acceptanceExpect(is_file($absolute) && fileowner($absolute) === posix_geteuid()
        && (fileperms($absolute) & 0077) === 0, 'private_owned_input_required');
    return $absolute;
}

function acceptanceRead(string $path): array
{
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    acceptanceExpect(is_array($data), 'invalid_private_json');
    return $data;
}

function acceptanceWrite(string $path, #[SensitiveParameter] array $state): void
{
    $temporary = tempnam(dirname($path), '.security-idp-');
    acceptanceExpect(is_string($temporary), 'state_temp_failed');
    chmod($temporary, 0600);
    try {
        acceptanceExpect(file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) !== false, 'state_write_failed');
        acceptanceExpect(rename($temporary, $path), 'state_rename_failed');
        chmod($path, 0600);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function acceptanceClaims(array $options, string $privateRoot): stdClass
{
    acceptanceExpect(isset($options['tokens'], $options['flow']), 'tokens_and_flow_required');
    $tokens = acceptanceRead(acceptancePrivatePath($options['tokens'], $privateRoot, true));
    $flow = acceptanceRead(acceptancePrivatePath($options['flow'], $privateRoot, true));
    $issuer = config('clubunio-verein-oidc.issuer');
    $client = config('clubunio-verein-oidc.client');
    acceptanceExpect($issuer === 'https://auth.clubunio.com/realms/clubunio' && ($flow['client_id'] ?? null) === $client
        && is_string($flow['nonce'] ?? null) && strlen($flow['nonce']) >= 32
        && ($flow['redirect_uri'] ?? null) === config('clubunio-verein-oidc.origin').'/vereinslogin/oidc/callback'
        && is_string($tokens['id_token'] ?? null), 'unexpected_real_flow');
    // Real pinned JWKS, no token-controlled destination and no fake signatures.
    $keys = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)
        ->get($issuer.'/protocol/openid-connect/certs')->throw()->json();
    $claims = KeycloakCodeExchange::decodeSignedToken($tokens['id_token'], $keys);
    $audience = (array) ($claims->aud ?? []);
    acceptanceExpect(($claims->iss ?? null) === $issuer && in_array($client, $audience, true)
        && (count($audience) === 1 || isset($claims->azp)) && ($claims->azp ?? $client) === $client
        && is_string($claims->nonce ?? null) && hash_equals($flow['nonce'], $claims->nonce)
        && is_int($claims->exp ?? null) && $claims->exp > time()
        && is_int($claims->iat ?? null) && $claims->iat >= time() - 300 && $claims->iat <= time() + 10
        && is_string($claims->sub ?? null) && preg_match('/\A[A-Za-z0-9._:-]{1,255}\z/', $claims->sub)
        && ($claims->email_verified ?? false) === true && is_string($claims->email ?? null)
        && preg_match('/\Asecurity-review-[a-f0-9]{16}@example\.invalid\z/', $claims->email)
        && KeycloakMobileAssurance::accepts($claims, KeycloakWebAssurance::requiredLevel(), time())
        && KeycloakMobileAssurance::accepts($claims, KeycloakMobileAssurance::level() ?? '', time()), 'real_identity_claims_rejected');
    return $claims;
}

try {
    acceptanceExpect(PHP_SAPI === 'cli' && function_exists('posix_geteuid'), 'cli_linux_required');
    $options = getopt('', ['root:', 'action:', 'state:', 'tokens:', 'flow:', 'index:']);
    acceptanceExpect(isset($options['root'], $options['action'], $options['state']), 'required_arguments_missing');
    $targets = ['/home/runcloud/webapps/ClubUnioStage' => ['clubunio_stage', 'https://stage.clubunio.com'],
        '/home/runcloud/webapps/ClubUnioProd' => ['clubunio_prod', 'https://app.clubunio.com']];
    $root = realpath($options['root']);
    acceptanceExpect(is_string($root) && isset($targets[$root]) && $root === $options['root'], 'exact_target_required');
    acceptanceExpect(posix_geteuid() !== 0 && fileowner($root.'/storage') === posix_geteuid(), 'runtime_storage_uid_required');
    $privateRoot = realpath($root.'/storage/app/private');
    acceptanceExpect(is_string($privateRoot), 'private_root_missing');
    $action = $options['action'];
    acceptanceExpect(in_array($action, ['create', 'issue', 'verify', 'cleanup'], true), 'invalid_action');
    $statePath = acceptancePrivatePath($options['state'], $privateRoot, $action !== 'create');
    if ($action === 'create') acceptanceExpect(!file_exists($statePath), 'state_already_exists');
    require $root.'/vendor/autoload.php';
    $application = require $root.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    acceptanceExpect($db->getDriverName() === 'pgsql' && $db->getDatabaseName() === $targets[$root][0]
        && config('clubunio-verein-oidc.origin') === $targets[$root][1], 'database_origin_mismatch');
    acceptanceExpect(config('clubunio-keycloak-backchannel.enabled') === true
        && config('clubunio-keycloak-backchannel.state_enforced') === true, 'revocation_not_enabled');
    acceptanceExpect((new Account)->getTable() === 'clubunio.accounts', 'runtime_schema_mismatch');
    Mail::fake();
    Queue::fake();
    $origin = $targets[$root][1];
    $request = Request::create($origin.'/api/mobile/v1/context', 'GET');
    $application->instance('request', $request);
    if ($action === 'create') {
        $claims = acceptanceClaims($options, $privateRoot);
        acceptanceExpect(!$db->table('clubunio.accounts')->where('username', $claims->email)->exists()
            && !$db->table('clubunio.keycloak_account_links')->where('issuer', $claims->iss)->where('subject', $claims->sub)->exists()
            && !isset(((array) config('clubunio-verein-oidc.subjects'))[$claims->sub]), 'identity_already_mapped');
        $marker = 'security-review-'.bin2hex(random_bytes(12));
        $ids = [];
        foreach (['tenants', 'accounts', 'tenant_accounts'] as $table) {
            do { $id = random_int(1000000000000, 8000000000000); } while ($db->table('clubunio.'.$table)->where('id', $id)->exists());
            $ids[$table] = $id;
        }
        $state = ['version' => 1, 'phase' => 'prepared', 'root' => $root, 'marker' => $marker, 'ids' => $ids,
            'issuer' => $claims->iss, 'subject' => $claims->sub, 'username' => $claims->email, 'issues' => []];
        // Persist cleanup coordinates before committing anything, including on process interruption.
        acceptanceWrite($statePath, $state);
        $db->transaction(function () use ($db, $state) {
            $db->table('clubunio.tenants')->insert(['id' => $state['ids']['tenants'], 'slug' => $state['marker'], 'name' => $state['marker']]);
            $db->table('clubunio.accounts')->insert(['id' => $state['ids']['accounts'], 'username' => $state['username'],
                'display_name' => $state['marker'], 'password' => Hash::make(bin2hex(random_bytes(32))), 'active' => true, 'api_only' => false]);
            $db->table('clubunio.tenant_accounts')->insert(['id' => $state['ids']['tenant_accounts'], 'tenant_id' => $state['ids']['tenants'],
                'account_id' => $state['ids']['accounts'], 'role' => 'admin', 'portal_enabled' => true]);
            $db->table('clubunio.keycloak_account_links')->insert(['issuer' => $state['issuer'], 'subject' => $state['subject'], 'account_id' => $state['ids']['accounts']]);
        });
        $state['phase'] = 'created';
        acceptanceWrite($statePath, $state);
        echo json_encode(['action' => 'create', 'synthetic_ids' => $ids, 'subject' => $claims->sub, 'marker' => $marker], JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    $state = acceptanceRead($statePath);
    acceptanceExpect(($state['version'] ?? null) === 1 && ($state['root'] ?? null) === $root
        && is_string($state['marker'] ?? null) && preg_match('/\Asecurity-review-[a-f0-9]{24}\z/', $state['marker'])
        && ($state['phase'] ?? null) !== 'cleaned', 'invalid_or_finished_state');
    foreach (['tenants', 'accounts', 'tenant_accounts'] as $table) acceptanceExpect(is_int($state['ids'][$table] ?? null)
        && $state['ids'][$table] >= 1000000000000 && $state['ids'][$table] <= 8000000000000, 'invalid_owned_ids');
    $accountId = $state['ids']['accounts'];
    $tenantId = $state['ids']['tenants'];
    $account = $db->table('clubunio.accounts')->where('id', $accountId)->first();
    $tenant = $db->table('clubunio.tenants')->where('id', $tenantId)->first();
    acceptanceExpect(($account === null && $tenant === null && $state['phase'] === 'prepared')
        || ($account && $tenant && $account->username === $state['username'] && $account->display_name === $state['marker']
            && $tenant->slug === $state['marker'] && $tenant->name === $state['marker']), 'owned_fixture_marker_mismatch');
    if ($action === 'cleanup') {
        foreach (['clubunio-verein-oidc.subjects', 'clubunio-platform-oidc.subjects', 'clubunio-platform-oidc.migrated_subjects'] as $mapping) {
            acceptanceExpect(!in_array($accountId, (array) config($mapping), true), 'remove_temporary_subject_mapping_first');
        }
        $db->transaction(function () use ($db, $state, $accountId, $tenantId) {
            acceptanceExpect(!$db->table('clubunio.platform_admins')->where('account_id', $accountId)->exists(), 'unexpected_platform_admin_grant');
            acceptanceExpect(!$db->table('clubunio.tenant_accounts')->where('account_id', $accountId)->where('tenant_id', '<>', $tenantId)->exists()
                && !$db->table('clubunio.tenant_accounts')->where('tenant_id', $tenantId)->where('account_id', '<>', $accountId)->exists(), 'unexpected_fixture_membership');
            // Restrictive FKs cause rollback if any unplanned data was created; never cascade blindly.
            $db->table('clubunio.mobile_sessions')->where('account_id', $accountId)->delete();
            $db->table('clubunio.keycloak_logout_watermarks')->where('account_id', $accountId)->delete();
            $db->table('clubunio.keycloak_account_links')->where('account_id', $accountId)->where('issuer', $state['issuer'])->where('subject', $state['subject'])->delete();
            $db->table('clubunio.tenant_accounts')->where('id', $state['ids']['tenant_accounts'])->where('account_id', $accountId)->where('tenant_id', $tenantId)->delete();
            $db->table('clubunio.accounts')->where('id', $accountId)->where('username', $state['username'])->where('display_name', $state['marker'])->delete();
            $db->table('clubunio.tenants')->where('id', $tenantId)->where('slug', $state['marker'])->where('name', $state['marker'])->delete();
        });
        $state['issues'] = [];
        $state['phase'] = 'cleaned';
        acceptanceWrite($statePath, $state);
        echo json_encode(['action' => 'cleanup', 'synthetic_rows_removed' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    acceptanceExpect($account !== null && $tenant !== null, 'fixture_not_created');
    $actor = Account::findOrFail($accountId);
    if ($action === 'issue') {
        $claims = acceptanceClaims($options, $privateRoot);
        acceptanceExpect($claims->iss === $state['issuer'] && $claims->sub === $state['subject'] && $claims->email === $state['username']
            && (((array) config('clubunio-verein-oidc.subjects'))[$claims->sub] ?? null) === $accountId, 'temporary_exact_subject_map_required');
        $stamp = app(KeycloakMobileAssurance::class)->stamp($actor, $claims);
        acceptanceExpect(is_string($stamp), 'real_mfa_stamp_rejected');
        $result = app(MobileSessions::class)->issue($request, $actor, $state['marker'], strongStamp: $stamp);
        $proof = KeycloakWebAssurance::proof($actor, $claims);
        $tokenRequest = Request::create($origin.'/api/mobile/v1/context', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$result['token']]);
        acceptanceExpect((int) app(MobileSessions::class)->authenticate($tokenRequest)->id === $accountId
            && KeycloakWebAssurance::acceptsSession($actor, $proof), 'issued_credentials_not_accepted');
        $state['issues'][] = ['token' => $result['token'], 'proof' => $proof, 'session_id' => explode('.', $result['token'])[0],
            'auth_time' => $claims->auth_time, 'iat' => $claims->iat, 'issued_at' => time()];
        $state['phase'] = 'issued';
        acceptanceWrite($statePath, $state);
        echo json_encode(['action' => 'issue', 'issue_index' => count($state['issues']) - 1,
            'real_idp_signature_verified' => true, 'original_mobile_session_accepted' => true, 'original_web_proof_accepted' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    $index = $options['index'] ?? '0';
    acceptanceExpect(is_string($index) && ctype_digit($index) && isset($state['issues'][(int) $index]), 'issue_index_missing');
    $issue = $state['issues'][(int) $index];
    $tokenRequest = Request::create($origin.'/api/mobile/v1/context', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$issue['token']]);
    $status = 200;
    try { app(MobileSessions::class)->authenticate($tokenRequest); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $status = $error->getStatusCode(); }
    $cutoff = $db->table('clubunio.keycloak_logout_watermarks')->where('account_id', $accountId)->value('cutoff');
    acceptanceExpect($status === 401 && !KeycloakWebAssurance::acceptsSession($actor, $issue['proof'])
        && $cutoff !== null && (int) $cutoff >= $issue['auth_time'], 'real_logout_not_observed');
    echo json_encode(['action' => 'verify', 'issue_index' => (int) $index, 'mobile_status' => $status,
        'old_web_proof_rejected' => true, 'persistent_logout_observed' => true, 'cutoff' => (int) $cutoff], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException && preg_match('/\A[a-z_]{3,100}\z/', $error->getMessage())
        ? $error->getMessage() : get_class($error);
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $message]).PHP_EOL);
    exit(1);
}
