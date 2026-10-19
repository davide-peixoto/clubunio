<?php

use App\ClubUnio\Models\{Account, AccessProfile, Tenant};
use App\ClubUnio\Services\AccessProfileService;
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
if (! in_array($argv[1] ?? '--preview', ['--preview', '--apply'], true)) { throw new LogicException('Invalid mode'); }
$db->beginTransaction();
try {
    $tenant = Tenant::where('slug', 'sv-sissach')->lockForUpdate()->sole();
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $profile = AccessProfile::where('tenant_id', $tenant->id)->where('name', 'Platzwart')->lockForUpdate()->sole();
    $fingerprint = fn () => hash('sha256', json_encode([
        $db->table('clubora.accounts')->orderBy('id')->get(['id', 'active', 'revision']),
        $db->table('clubora.tenant_accounts')->orderBy('id')->get(),
        $db->table('clubora.account_roles')->orderBy('code')->get(),
        $db->table('clubora.access_profiles')->where('id', '<>', $profile->id)->orderBy('id')->get(),
    ], JSON_THROW_ON_ERROR));
    $before = $fingerprint();
    $definition = (static fn () => require __DIR__.'/svs-access-profile-definitions.php')()['Platzwart'];
    if ($profile->permissions !== $definition['permissions']) {
        if ($profile->permissions !== ['facilities.view', 'facilities.update']) { throw new LogicException('Unexpected current grants; refusing overwrite'); }
        $profile = app(AccessProfileService::class)->save($actor, $tenant, $profile->id, $definition + [
            'name' => 'Platzwart', 'active' => $profile->active, 'revision' => $profile->revision,
            'reason' => 'Auftrag 19.10.2026: Platzwart nur Verfügbarkeit/Bemerkungen, keine Status-Wertetabelle.',
        ]);
    }
    if ($before !== $fingerprint()) { throw new LogicException('Unrelated rights changed'); }
    echo json_encode(['id' => $profile->id, 'permissions' => $profile->permissions, 'assignments' => $db->table('clubora.tenant_accounts')->where('access_profile_id', $profile->id)->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    echo ($apply ? 'COMMITTED' : 'PREVIEW ROLLED BACK').'; other profiles and assignments unchanged.'.PHP_EOL;
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
