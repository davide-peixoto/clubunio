<?php

if (! in_array('--synthetic-rollback', $argv, true)) { throw new LogicException('Explicit synthetic transaction required'); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected test database'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$stage = 'fixture';
$call = function ($method, $path, $body = [], $token = null) use ($kernel, &$stage) {
    $stage = $method.' '.$path;
    $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'dev.clubunio.ch', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    $r = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [], $server, json_encode($body));
    $response = $kernel->handle($r);
    $stage .= ' [HTTP '.$response->getStatusCode().']';
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
$expect = function ($actual, $expected) { if ($actual !== $expected) { throw new LogicException('Unexpected functional result ('.get_debug_type($actual).' versus '.get_debug_type($expected).')'); } };
$db->beginTransaction();
try {
    $tenant = App\ClubUnio\Models\Tenant::create(['slug' => 'qa-mobile-release-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic release fixture']);
    $password = bin2hex(random_bytes(16));
    $actor = new App\ClubUnio\Models\Account;
    $actor->forceFill(['username' => 'qa-team-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic fixture', 'password' => Illuminate\Support\Facades\Hash::make($password), 'active' => true, 'api_only' => false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
    $season = $db->table('clubora.seasons')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Fixture season',
        'starts_on' => now('Europe/Zurich')->subMonth()->toDateString(), 'ends_on' => now('Europe/Zurich')->addMonths(2)->toDateString()]);
    $db->table('clubora.tenant_settings')->insert(['tenant_id' => $tenant->id, 'area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season',
        'value_type' => 'integer', 'encrypted_value' => Illuminate\Support\Facades\Crypt::encryptString((string) $season), 'is_secret' => false]);
    $team = $db->table('clubora.teams')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Fixture team']);
    $team = $db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic fixture']);
    $member = $db->table('clubora.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Player', 'birth_date' => '2012-01-01']);
    [$status, $login] = $call('POST', 'session', ['username' => $actor->username, 'password' => $password, 'device_name' => 'Synthetic release']);
    $expect($status, 200); $token = $login['data']['token']; $base = 'clubs/'.$tenant->id.'/';
    [$status, $teams] = $call('GET', $base.'my-teams', [], $token); $expect($status, 200); $expect($teams['data']['totalRecordCount'], 1);
    [$status, $added] = $call('POST', $base.'my-teams/'.$team.'/players', ['MitgliederID' => $member], $token); $expect($status, 200);
    $assignment = $added['data']['Assignment'];
    [$status, $details] = $call('GET', $base.'my-teams/'.$team, [], $token); $expect($status, 200); $expect(count($details['data']['Players']), 1);
    [$status] = $call('DELETE', $base.'my-teams/'.$team.'/players/'.$assignment['TeamlistID'], ['_version' => str_repeat('0', 64)], $token); $expect($status, 422);
    [$status, $native] = $call('GET', $base.'planning/teams', [], $token);
    $enabled = (bool) config('clubunio-mobile.native_planning_enabled');
    $expect($status, $enabled ? 200 : 503);
    if ($enabled) {
        App\ClubUnio\Services\TeamPlanningService::seedOptions($tenant->id);
        $start = Carbon\CarbonImmutable::now('Europe/Zurich')->addDays(2)->setTime(18, 0);
        $input = ['TeamSaisonID' => $team, 'Titel' => 'Synthetic planning fixture', 'Terminart' => 'TRAINING', 'TeilnahmeModus' => 'ANMELDEN',
            'DatumVon' => $start->toIso8601String(), 'DatumBis' => $start->addHour()->toIso8601String()];
        [$status, $event] = $call('POST', $base.'planning/events', $input, $token); $expect($status, 200);
        $event = $event['data']['TeamTermin']; $eventUrl = $base.'planning/events/'.$event['TeamTerminID'];
        [$status, $absence] = $call('POST', $base.'planning/absences', ['MitgliederID' => $member, 'DatumVon' => $start->subMinutes(10)->toIso8601String(),
            'DatumBis' => $start->addMinutes(10)->toIso8601String(), 'Absenzart' => 'ARBEIT', 'Bemerkung' => 'Synthetic private fixture'], $token);
        $expect($status, 200); $expect($absence['data']['sync']['autoApplied'], 1);
        [$status, $restored] = $call('DELETE', $base.'planning/absences/'.$absence['data']['Absenz']['AbsenzID'], ['Revision' => 1], $token);
        $expect($status, 200); $expect($restored['data']['sync']['autoRestored'], 1);
        [$status, $guests] = $call('POST', $eventUrl.'/guests', ['Vorname' => 'Synthetic', 'Nachname' => 'Guest'], $token); $expect($status, 200);
        $guest = collect($guests['data']['TeamTermin']['Teilnahmen'])->first(fn ($row) => isset($row['GastID']));
        [$status] = $call('DELETE', $eventUrl.'/guests/'.$guest['GastID'], ['Revision' => 1], $token); $expect($status, 200);
        [$status, $detail] = $call('GET', $eventUrl, [], $token); $expect($status, 200);
        $person = collect($detail['data']['TeamTermin']['Teilnahmen'])->firstWhere('MitgliederID', $member);
        [$status, $participation] = $call('POST', $eventUrl.'/participation', ['MitgliederID' => $member, 'Revision' => $person['Revision'], 'TeilnahmeStatus' => 'DABEI'], $token); $expect($status, 200);
        $person = collect($participation['data']['TeamTermin']['Teilnahmen'])->firstWhere('MitgliederID', $member);
        // Only this transaction's synthetic event is moved into the past.
        $db->table('clubora.team_events')->where('tenant_id', $tenant->id)->where('id', $event['TeamTerminID'])->update([
            'starts_at' => Carbon\CarbonImmutable::now()->subHours(2)->toIso8601String(), 'ends_at' => Carbon\CarbonImmutable::now()->subHour()->toIso8601String()]);
        $suggestions = ['Revision' => 1, 'suggestions' => [['MitgliederID' => $member, 'TeilnahmeStatus' => 'DABEI', 'Revision' => $person['Revision']]]];
        [$status, $confirmed] = $call('POST', $eventUrl.'/attendance/confirm-suggestions', $suggestions, $token); $expect($status, 200); $expect($confirmed['data']['confirmedCount'], 1);
        [$status] = $call('POST', $eventUrl.'/attendance/confirm-suggestions', $suggestions, $token); $expect($status, 422);
        [$status, $stats] = $call('GET', $base.'planning/statistics?'.http_build_query(['from' => Carbon\CarbonImmutable::now('Europe/Zurich')->subDay()->toDateString(), 'to' => Carbon\CarbonImmutable::now('Europe/Zurich')->toDateString()]), [], $token);
        $expect($status, 200); $expect($stats['data']['totalRecordCount'], 1); $expect($stats['data']['SpielerStatistiken'][0]['Kategorien']['TRAINING']['Anwesend'], 1);
        $second = array_replace($input, ['DatumVon' => $start->addWeek()->toIso8601String(), 'DatumBis' => $start->addWeek()->addHour()->toIso8601String()]);
        $batch = ['command_key' => (string) Illuminate\Support\Str::uuid(), 'events' => [$input, $second]];
        [$status, $series] = $call('POST', $base.'planning/event-batches', $batch, $token); $expect($status, 200);
        [$status, $replay] = $call('POST', $base.'planning/event-batches', $batch, $token); $expect($status, 200); $expect($replay['data']['replayed'], true);
        $seriesUrl = $base.'planning/events/'.$series['data']['TeamTermine'][0]['TeamTerminID'];
        [$status, $snapshot] = $call('GET', $seriesUrl, [], $token); $expect($status, 200);
        [$status, $changed] = $call('PATCH', $seriesUrl, ['Revision' => 1, 'SeriesRevision' => $snapshot['data']['TeamTermin']['SeriesRevision'], 'scope' => 'future', 'Titel' => 'Synthetic updated'], $token);
        $expect($status, 200); $expect($changed['data']['series']['changed'], 2);
        [$status, $removed] = $call('DELETE', $seriesUrl, ['Revision' => 2, 'SeriesRevision' => $changed['data']['TeamTermin']['SeriesRevision'], 'scope' => 'all'], $token);
        $expect($status, 200); $expect($removed['data']['removed_count'], 2);
        [$status] = $call('GET', $seriesUrl, [], $token); $expect($status, 404);
        [$status] = $call('DELETE', $eventUrl, ['Revision' => 1, 'scope' => 'single'], $token); $expect($status, 200);
    }
    [$status, $removed] = $call('DELETE', $base.'my-teams/'.$team.'/players/'.$assignment['TeamlistID'], ['_version' => $assignment['_version']], $token); $expect($status, 200); $expect($removed['data']['removed'], true);
    $otherSeason = $db->table('clubora.seasons')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic other season']);
    $invoiceIds = [];
    foreach ([$season, $otherSeason] as $selected) {
        $invoice = $db->table('clubora.invoices')->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $member, 'season_id' => $selected]);
        $invoiceIds[] = $invoice;
        $db->table('clubora.invoice_lines')->insert(['tenant_id' => $tenant->id, 'invoice_id' => $invoice, 'net_amount' => '10.02']);
        $db->table('clubora.expenses')->insert(['tenant_id' => $tenant->id, 'member_id' => $member, 'season_id' => $selected]);
    }
    [$status, $list] = $call('GET', $base.'invoices?limit=1&all_seasons=1', [], $token); $expect($status, 200);
    $expect($list['data']['total'], 1); $expect($list['data']['rows'][0]['id'], $invoiceIds[0]);
    [$status, $options] = $call('GET', $base.'invoices/options', [], $token); $expect($status, 200);
    $expect($options['data']['seasons'][0]['id'], $season);
    [$status, $list] = $call('GET', $base.'expenses?limit=1', [], $token); $expect($status, 200); $expect($list['data']['total'], 1);
    [$status, $options] = $call('GET', $base.'expenses/options', [], $token); $expect($status, 200);
    $expect($options['data']['seasons'][0]['id'], $season);
    $db->table('clubora.tenant_settings')->where('tenant_id', $tenant->id)->where('area', 'general')->where('key', 'SaisonID')
        ->update(['encrypted_value' => Illuminate\Support\Facades\Crypt::encryptString((string) $otherSeason)]);
    [$status, $list] = $call('GET', $base.'invoices', [], $token); $expect($status, 200);
    $expect($list['data']['total'], 1); $expect($list['data']['rows'][0]['id'], $invoiceIds[1]);
    echo 'Personal My Teams and '.($enabled ? 'enabled native planning' : 'closed native planning foundation')." HTTP-kernel checks passed; synthetic transaction rolled back.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Functional gate failed at '.$stage.': '.get_class($e)." (record contents suppressed).\n");
    $failed = true;
} finally { $db->rollBack(); }
if ($failed ?? false) { exit(1); }
