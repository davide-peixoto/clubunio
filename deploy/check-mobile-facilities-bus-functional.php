<?php

if (PHP_SAPI !== 'cli' || ! in_array('--synthetic-rollback', $argv, true)) { throw new LogicException('Explicit synthetic transaction required'); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV only'); }
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected document test database'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$stage = 'fixture'; $temp = null; $failed = false;
$call = function ($method, $path, $body = [], $token = null, $files = []) use ($kernel, &$stage) {
    $stage = $method.' '.$path;
    $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'dev.clubunio.ch', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CLUBUNIO_MOBILE_FEATURES' => 'facilities-v1,bus-v1,member-documents-v1'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    if (! $files) { $server['CONTENT_TYPE'] = 'application/json'; }
    $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, $files ? $body : [], [], $files, $server, $files ? null : json_encode($body));
    $response = $kernel->handle($request); $stage .= ' [HTTP '.$response->getStatusCode().']';
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response];
};
$expect = function ($actual, $expected) { if ($actual !== $expected) { throw new LogicException('Unexpected document functional result'); } };

if (! config('clubunio-mobile.native_facilities_enabled') || ! config('clubunio-mobile.native_bus_enabled') || ! config('clubunio-mobile.native_documents_enabled')) { throw new LogicException('Enabled facilities/bus release required'); }
$db->beginTransaction();
$db->statement("SET LOCAL statement_timeout TO '20s'");
$db->statement("SET LOCAL application_name TO 'clubunio-facilities-bus-release-check'");
try {
    $tenant = App\ClubUnio\Models\Tenant::create(['slug' => 'qa-fac-bus-release-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic release fixture']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    foreach (['Platzverfugbarkeit', 'BusReservationen', 'Dokumente'] as $key) { $db->table('clubora.mobile_module_options')->insert(['tenant_id' => $tenant->id, 'key' => $key, 'name' => $key]); }
    $password = bin2hex(random_bytes(16)); $actor = new App\ClubUnio\Models\Account;
    $actor->forceFill(['username' => 'qa-fac-bus-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic release admin', 'password' => Illuminate\Support\Facades\Hash::make($password), 'active' => true, 'api_only' => false])->save();
    $insert('tenant_accounts', ['account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
    $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Applicant']);
    $season = $insert('seasons', ['name' => 'Synthetic selected season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
    $insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season', 'value_type' => 'integer', 'encrypted_value' => Illuminate\Support\Facades\Crypt::encryptString((string) $season), 'is_secret' => false]);
    $team = $insert('teams', ['name' => 'Synthetic team']);
    $team = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic selected team']);
    $bus = $insert('buses', ['name' => 'Synthetic bus']);
    foreach (['ANGEFRAGT','GENEHMIGT','ABGELEHNT','STORNIERT'] as $status) { $insert('bus_statuses', ['code' => $status, 'name' => $status]); }
    $open = $insert('facility_statuses', ['code' => 'open', 'name' => 'Synthetic open', 'blocks_training' => false]);
    $closed = $insert('facility_statuses', ['code' => 'closed', 'name' => 'Synthetic closed', 'blocks_training' => true]);
    $field = $insert('training_options', ['category' => 'field', 'code' => 'qa-release-field', 'name' => 'Synthetic custom field']);
    $field = $insert('field_availabilities', ['field_id' => $field, 'status_id' => $open, 'note' => 'Synthetic retained note']);
    [$status, $login] = $call('POST', 'session', ['username' => $actor->username, 'password' => $password, 'device_name' => 'Synthetic facilities bus release']);
    $expect($status, 200); $token = $login['data']['token']; $base = 'clubs/'.$tenant->id;
    [$status, $menu] = $call('GET', $base.'/modules', [], $token); $expect($status, 200);
    foreach (['Platzverfugbarkeit','BusReservationen','Dokumente'] as $key) { $expect(collect($menu['data']['modules'])->firstWhere('key', $key)['native'], true); }
    [$status, $fields] = $call('GET', $base.'/facilities/fields', [], $token); $expect($status, 200); $expect(count($fields['data']['fields']), 1);
    [$status, $changed] = $call('PATCH', $base.'/facilities/fields/'.$field, ['revision' => 1, 'status_id' => $closed, 'status_revision' => 1], $token);
    $expect($status, 200); $expect($changed['data']['fields'][0]['revision'], 2); $expect($changed['data']['fields'][0]['note'], 'Synthetic retained note');
    [$status] = $call('PATCH', $base.'/facilities/fields/'.$field, ['revision' => 1, 'status_id' => $open, 'status_revision' => 1], $token); $expect($status, 422);
    $start = Carbon\CarbonImmutable::now('Europe/Zurich')->addDays(2)->setTime(18,0);
    $input = ['BusID' => $bus, 'TeamSaisonID' => $team, 'AntragstellerMitgliederID' => $member, 'ResVon' => $start->toIso8601String(), 'ResBis' => $start->addHours(2)->toIso8601String(), 'Ort' => 'Synthetic destination', 'Descr' => 'Synthetic description'];
    [$status, $created] = $call('POST', $base.'/bus/reservations', $input, $token); $expect($status, 200); $expect($created['data']['ReservierungsStatus'], 'ANGEFRAGT');
    $url = $base.'/bus/reservations/'.$created['data']['ResID'];
    [$status, $approved] = $call('POST', $url.'/approve', ['revision' => 1, 'note' => 'Synthetic approval'], $token); $expect($status, 200); $expect($approved['data']['Revision'], 2);
    [$status] = $call('POST', $url.'/reject', ['revision' => 1], $token); $expect($status, 422);
    [$status, $keybox] = $call('GET', $url.'/keybox', [], $token); $expect($status, 200); $expect($keybox['data']['available'], false); $expect($keybox['data']['issuanceEnabled'], false);
    [$status, $cancelled] = $call('POST', $url.'/cancel', ['revision' => 2], $token); $expect($status, 200); $expect($cancelled['data']['ReservierungsStatus'], 'STORNIERT');
    if (Illuminate\Support\Facades\Http::recorded()->isNotEmpty()) { throw new LogicException('Unexpected external request'); }
    echo "Personal menu/facility status/stale revision/bus request-approve-cancel and closed keybox passed; synthetic transaction rolled back.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Facilities/bus functional gate failed at '.$stage.': '.get_class($error)." (record contents suppressed).\n"); $failed = true;
} finally { $db->rollBack(); }
if ($failed) { exit(1); }
