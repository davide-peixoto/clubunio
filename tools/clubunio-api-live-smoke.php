<?php

use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(PHP_SAPI === 'cli' && getenv('CLUBUNIO_API_LIVE_SMOKE') === '1', 403);
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$integration = $admin = $profile = $tenant = null;
$checks = [];
try {
    [$tenant, $admin, $profile, $integration, $token] = $db->transaction(function () {
        $tenant = Tenant::create(['name' => 'QA API release smoke', 'slug' => 'qa-api-'.Str::lower(Str::random(16))]);
        $admin = new Account;
        $admin->forceFill(['username' => 'qa-api-admin-'.Str::uuid(), 'display_name' => 'QA API release smoke',
            'password' => Hash::make(Str::random(80)), 'active' => true])->save();
        DB::connection('clubunio')->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $admin->id, 'role' => 'admin']);
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $tenant->id, 'name' => 'QA website read-only', 'active' => true,
            'permissions' => ['teams.view', 'competitions.view', 'training_slots.view', 'field_availability.view']])->save();
        $service = app(ApiTokenService::class);
        $integration = $service->createIntegration($admin, $tenant, ['name' => 'QA website smoke', 'access_profile_id' => $profile->id,
            'scopes' => array_keys(ApiTokenService::WEBSITE), 'rate_limit' => 60]);
        $token = $service->issueToken($admin, $tenant, $integration->id, 'QA 5 minute release smoke', $integration->scopes, now()->addMinutes(5))['token'];

        return [$tenant, $admin, $profile, $integration, $token];
    });
    foreach (['context' => 200, 'website/teams' => 200, 'website/fixtures' => 200,
        'website/training-times' => 200, 'website/facility-status' => 200, 'members' => 403] as $path => $expected) {
        $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(30)->get('https://dev.clubunio.ch/api/v1/'.$path);
        abort_unless($response->status() === $expected, 409, 'Unexpected API smoke status for '.$path);
        abort_unless($response->header('X-Request-ID'), 409);
        if (str_starts_with($path, 'website/')) {
            abort_unless($response->json('data') === [], 409);
        }
        $checks[$path] = $response->status();
    }
    $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(30)
        ->post('https://dev.clubunio.ch/api/v1/teams', ['name' => 'Must never be written', 'active' => true]);
    abort_unless($response->status() === 403, 409);
    abort_if($db->table('clubora.teams')->where('tenant_id', $tenant->id)->exists(), 409);
    $checks['write_denied'] = 403;
    app(ApiTokenService::class)->disableIntegration($admin, $tenant, $integration->id);
    $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(30)->get('https://dev.clubunio.ch/api/v1/context');
    abort_unless($response->status() === 401, 409);
    $checks['revoked'] = 401;
} finally {
    if ($integration && $admin && $tenant) {
        app(ApiTokenService::class)->disableIntegration($admin, $tenant, $integration->id);
        Account::whereKey($integration->account_id)->update(['active' => false]);
    }
    if ($profile) {
        $profile->forceFill(['active' => false])->save();
    }
    if ($admin) {
        $admin->forceFill(['active' => false])->save();
    }
}
echo json_encode(['tenant_id' => $tenant->id, 'integration_id' => $integration->id,
    'checks' => $checks, 'tokens_revoked' => true, 'qa_accounts_inactive' => true,
    'existing_club_records_unchanged' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
