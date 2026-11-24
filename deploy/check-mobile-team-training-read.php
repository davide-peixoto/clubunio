<?php

// Native PostgreSQL projection regression. Every synthetic row rolls back.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--synthetic-rollback'
    || !in_array($argv[2] ?? '', ['clubunio_dev', 'clubunio_stage'], true)) throw new LogicException('Explicit DEV/Stage target required');
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$origins = ['clubunio_dev'=>'https://dev.clubunio.ch', 'clubunio_stage'=>'https://stage.clubunio.com'];
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $argv[2]
    || $db->selectOne('select current_database() as name')->name !== $argv[2]
    || config('app.url') !== $origins[$argv[2]]) throw new LogicException('Unexpected target');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Queue::fake();
$checks = 0;
$expect = function ($ok) use (&$checks) { if (!$ok) throw new RuntimeException('Synthetic training assertion failed'); $checks++; };
$db->beginTransaction();
try {
    $db->statement("SET LOCAL application_name='mobile-team-training-rollback'");
    $db->statement("SET LOCAL lock_timeout='5s'");
    $suffix = bin2hex(random_bytes(7));
    $tenant = App\ClubUnio\Models\Tenant::create(['slug'=>'team-training-'.$suffix,'name'=>'Synthetic training']);
    $other = App\ClubUnio\Models\Tenant::create(['slug'=>'other-training-'.$suffix,'name'=>'Synthetic other']);
    $insert = fn($table,$values,$owner=null) => $db->table('clubora.'.$table)->insertGetId($values+['tenant_id'=>$owner??$tenant->id]);
    $season = $insert('seasons',['name'=>'Synthetic season','starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']);
    $team = $insert('teams',['name'=>'Synthetic team']);
    $ts = $insert('team_seasons',['team_id'=>$team,'season_id'=>$season,'label'=>'Synthetic team']);
    $otherTeam = $insert('teams',['name'=>'Synthetic other team']);
    $otherTs = $insert('team_seasons',['team_id'=>$otherTeam,'season_id'=>$season,'label'=>'Synthetic other team']);
    $winter = $insert('training_options',['category'=>'period','code'=>'W','name'=>'Synthetic winter','active'=>true]);
    $summer = $insert('training_options',['category'=>'period','code'=>'S','name'=>'Synthetic summer','active'=>true]);
    $setting = $insert('tenant_settings',['area'=>'general','key'=>App\ClubUnio\Services\ActiveTrainingPlan::KEY,
        'label'=>'Synthetic plan','value_type'=>'integer','enabled'=>true,
        'encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString((string)$winter)]);
    $rows = [];
    foreach ([[], ['hall'=>'Synthetic hall'], ['synthetic_only'=>true], ['all_fields'=>true],
        ['period_id'=>$summer], ['team_season_id'=>$otherTs], ['active'=>false]] as $override) {
        $rows[] = $insert('training_slots',$override+['team_season_id'=>$ts,'period_id'=>$winter,
            'starts_at'=>'18:00','ends_at'=>'19:30','active'=>true]);
    }
    $projection = new ReflectionMethod(App\ClubUnio\Mobile\MobileTeamsData::class,'trainingsForAuthorizedTeam');
    $service = app(App\ClubUnio\Mobile\MobileTeamsData::class);
    $actual = $projection->invoke($service,$tenant,$ts);
    $expect(array_column($actual,'TrainingID') === array_slice($rows,0,3));
    $expect($actual[1]['Halle'] === 'Synthetic hall');
    $expect(count(array_unique(array_column($actual,'SommerWinter'))) === 1);
    $expect($projection->invoke($service,$other,$ts) === []);
    $db->table('clubora.tenant_settings')->where('id',$setting)->update(['encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString('0')]);
    $expect($projection->invoke($service,$tenant,$ts) === []);
    echo 'Team training read checks passed: '.$checks."; synthetic rollback; no provider calls.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Training read check failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    $failed = true;
} finally { $db->rollBack(); }
if ($failed ?? false) exit(1);
