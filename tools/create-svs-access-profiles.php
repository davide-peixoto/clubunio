<?php

use App\ClubUnio\Models\{Account, AccessProfile, Tenant};
use App\ClubUnio\Services\{AccessProfileService, AccountAdministrationService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
$apply = ($argv[1] ?? '--preview') === '--apply';
if (! in_array($argv[1] ?? '--preview', ['--preview', '--apply'], true)) { throw new LogicException('Use --preview or --apply'); }
$definitions = require __DIR__.'/svs-access-profile-definitions.php';
$db->beginTransaction();
try {
    $tenant = Tenant::where('slug', 'sv-sissach')->lockForUpdate()->sole();
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    if (! app(AccountAdministrationService::class)->allowed($actor, $tenant)) { throw new LogicException('Authorized admin required'); }
    $ids = $db->table('clubora.access_profiles')->pluck('id')->all();
    $fingerprint = fn () => hash('sha256', json_encode([
        $db->table('clubora.accounts')->orderBy('id')->get(['id', 'username', 'active', 'revision']),
        $db->table('clubora.tenant_accounts')->orderBy('id')->get(),
        $db->table('clubora.account_roles')->orderBy('code')->get(),
        $db->table('clubora.access_profiles')->whereIn('id', $ids)->orderBy('id')->get(),
    ], JSON_THROW_ON_ERROR));
    $before = $fingerprint();
    foreach ($definitions as $name => $definition) {
        $profile = AccessProfile::where('tenant_id', $tenant->id)->where('name', $name)->first();
        if ($profile) {
            $actual = $profile->permissions; $expected = $definition['permissions'];
            sort($actual); sort($expected);
            if ($actual !== $expected || ! $profile->active) { throw new LogicException('Existing profile differs; refusing overwrite: '.$name); }
            $result = 'unchanged';
        } else {
            $profile = app(AccessProfileService::class)->save($actor, $tenant, null, $definition + [
                'name' => $name, 'active' => true,
                'reason' => 'Expliziter Auftrag: sieben SVS-Profile vorbereiten, keine Benutzer zuordnen (18.10.2026).',
            ]);
            $result = 'created';
        }
        $assigned = $db->table('clubora.tenant_accounts')->where('access_profile_id', $profile->id)->count();
        if ($assigned !== 0) { throw new LogicException('Expected unassigned profile: '.$name); }
        echo json_encode(['name' => $name, 'id' => $profile->id, 'result' => $result, 'permissions' => count($profile->permissions), 'assigned' => $assigned], JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
    if ($before !== $fingerprint()) { throw new LogicException('Existing rights changed; rollback'); }
    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    echo ($apply ? 'COMMITTED' : 'PREVIEW ROLLED BACK').'; existing rights unchanged; no assignments.'.PHP_EOL;
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
