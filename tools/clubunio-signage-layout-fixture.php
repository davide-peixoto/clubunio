<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Services\SignageBrandService;
use App\ClubUnio\Services\SignageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('local') && DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$brand = app(SignageBrandService::class)->read($tenant);
$periodId = $brand['period_id'];
$periods = TrainingOption::where('tenant_id', $tenant->id)->where('category', 'period')->where('active', true)->get();
$data = app(SignageService::class)->publicDisplay($tenant, ['period_id' => $periodId]);
$data['competitions'] = collect(range(1, 40))->map(fn ($n) => (object) [
    'starts_at' => '18:00', 'host_name' => 'Testmannschaft '.$n, 'host_category' => 'Junioren', 'host_club_number' => null,
    'guest_name' => 'Synthetischer Gastverein '.$n, 'guest_category' => null, 'guest_club_number' => null,
    'hostRoom' => (object) ['name' => 'Garderobe 1'], 'guestRoom' => (object) ['name' => 'Garderobe 2'],
    'hostSide' => null, 'guestSide' => null, 'field' => (object) ['name' => 'Hauptplatz'], 'remarks' => 'LAYOUT TEST - kein echtes Spiel',
]);
$data['notices'] = collect([(object) ['title' => 'Layout-Abnahme', 'body' => 'Synthetischer Spielplan mit 40 Zeilen. Keine Datenbankaenderung.']]);
URL::forceRootUrl('http://localhost:8093');
URL::forceScheme('http');
$target = dirname(__DIR__).'/public/clubunio-signage-layout-check.html';
$html = view('clubunio.public.signage', $data + compact('tenant', 'brand', 'periodId', 'periods'))->render();
// A static fixture must not post CLI-generated Livewire snapshots into a browser session.
$dom = new DOMDocument;
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
foreach (iterator_to_array($dom->getElementsByTagName('script')) as $script) {
    if (str_contains($script->getAttribute('src'), 'livewire')) {
        $script->parentNode->removeChild($script);
    }
}
file_put_contents($target, $dom->saveHTML());
echo 'Generated synthetic local-only layout fixture; remove after visual check.'.PHP_EOL;
