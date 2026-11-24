<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    if (! $actor->hasUnrestrictedBackofficeAccess($tenant)) { throw new LogicException('Administrator required'); }
    Illuminate\Support\Facades\DB::connection('clubunio')->transaction(function () use ($tenant, $actor) {
        App\ClubUnio\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $row = App\ClubUnio\Models\Setting::where('tenant_id', $tenant->id)->where('area', 'general')->where('key', App\ClubUnio\Services\ReactUi::KEY)->first();
        if ($row) { echo "Existing React parameter preserved.\n"; return; }
        $row = new App\ClubUnio\Models\Setting;
        $row->forceFill(['tenant_id' => $tenant->id, 'area' => 'general', 'key' => App\ClubUnio\Services\ReactUi::KEY,
            'label' => 'React', 'value_type' => 'boolean', 'encrypted_value' => Illuminate\Support\Facades\Crypt::encryptString('1'),
            'enabled' => true, 'is_secret' => false])->save();
        Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.setting_changes')->insert([
            'tenant_id' => $tenant->id, 'setting_id' => $row->id, 'actor_id' => $actor->id,
        ]);
        echo "Created DEV React parameter: Yes.\n";
    });
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
