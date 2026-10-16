<?php

use App\ClubUnio\Http\UseActiveTenant;
use App\ClubUnio\Resources\AccessProfileResource;
use App\ClubUnio\Services\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new RuntimeException('Unexpected database');
}
if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')
    || ! Schema::connection('clubunio')->hasColumn('clubora.tenant_accounts', 'access_profile_id')) {
    throw new RuntimeException('Access profile schema missing');
}
$panel = Filament\Facades\Filament::getPanel('clubunio');
if (! in_array(AccessProfileResource::class, $panel->getResources(), true)) {
    throw new RuntimeException('Profile editor missing');
}
$route = app('router')->getRoutes()->getByName('filament.clubunio.resources.access-profiles.index');
if (! $route || ! in_array(UseActiveTenant::class, $route->gatherMiddleware(), true)) {
    throw new RuntimeException('Profile route tenant guard missing');
}
if (! $db->table('clubora.account_roles')->where('code', 'profile')->where('active', true)->exists()) {
    throw new RuntimeException('Profile role not active');
}
$invalid = $db->table('clubora.tenant_accounts as membership')
    ->leftJoin('clubora.access_profiles as profile', 'profile.id', '=', 'membership.access_profile_id')
    ->where('membership.role', 'profile')
    ->where(fn ($q) => $q->whereNull('profile.id')->orWhereColumn('profile.tenant_id', '<>', 'membership.tenant_id')->orWhere('membership.portal_enabled', true))->count();
if ($invalid !== 0) {
    throw new RuntimeException('Invalid profile assignment');
}
$seeded = $db->table('clubora.access_profiles')->where('name', 'Nur Lesen')->where('revision', 1)->get();
foreach ($seeded as $profile) {
    if (array_diff(json_decode($profile->permissions, true, flags: JSON_THROW_ON_ERROR), PermissionCatalog::readOnly())) {
        throw new RuntimeException('Read-only seed contains business actions');
    }
}
echo 'Profile schema, route, role and assignments verified; read-only profiles: '.$seeded->count()."\n";
