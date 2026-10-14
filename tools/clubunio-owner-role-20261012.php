<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora' || ! in_array('--apply', $argv, true)) {
    throw new LogicException('Explicit ClubUnio-only apply required');
}
$db->transaction(function () use ($db) {
    $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $actors = Account::where('display_name', 'Davide Peixoto')->where('username', 'davide.peixoto@svsissach.ch')->where('active', true)
        ->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id))->get();
    if ($actors->count() !== 1) {
        throw new LogicException('Owner is not unique');
    }
    $actor = $actors->first();
    $link = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
    $role = (clone $link)->lockForUpdate()->value('role');
    if (! in_array($role, ['office', 'admin'], true)) {
        throw new LogicException('Unexpected previous owner role');
    }
    if ($role === 'office') {
        $link->update(['role' => 'admin']);
        $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'tenant_accounts', 'object_id' => $actor->id,
            'before_value' => json_encode(['role' => 'office']),
            'after_value' => json_encode(['role' => 'admin', 'reason' => 'Explicit owner approval in chat 2026-10-12'])]);
    }
    echo 'Owner account '.$actor->id.': admin; other accounts unchanged.'.PHP_EOL;
});
