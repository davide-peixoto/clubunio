<?php

// Explicit DEV-only synthetic proof. Never imports, refreshes or commits business data.
if (PHP_SAPI !== 'cli' || ! in_array('--synthetic-rollback', $argv, true)) {
    fwrite(STDERR, "CLI --synthetic-rollback is required.\n"); exit(1);
}
if (array_diff(array_slice($argv, 1), ['--synthetic-rollback', '--foundation', '--enabled'])
    || (in_array('--foundation', $argv, true) && in_array('--enabled', $argv, true))) {
    fwrite(STDERR, "Unexpected or conflicting expense gate arguments.\n"); exit(1);
}
$db = null; $failed = false; $stage = 'bootstrap'; $originalNative = null;
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (rtrim((string) config('app.url'), '/') !== 'https://dev.clubunio.ch' || ! config('clubunio-mobile.enabled')) {
        throw new LogicException('Exact DEV origin and personal API required');
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) {
        throw new LogicException('Exact standalone DEV PostgreSQL connection required');
    }
    $originalNative = (bool) config('clubunio-mobile.native_expenses_enabled', false);
    if ((in_array('--enabled', $argv, true) && ! $originalNative) || (in_array('--foundation', $argv, true) && $originalNative)) {
        throw new LogicException('Requested expense release stage does not match configuration');
    }
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Mail::fake(); Illuminate\Support\Facades\Queue::fake();
    foreach ([App\ClubUnio\Services\RegistrationBlobStore::class, App\ClubUnio\Services\AzureDocumentReader::class,
        App\ClubUnio\Mobile\AzureExpenseOcrProvider::class, App\ClubUnio\Services\BexioOidcClient::class,
        App\ClubUnio\Services\BexioDocumentClient::class, App\ClubUnio\Services\SponsorRunBexioClient::class,
        App\ClubUnio\Services\BexioSubmissionClient::class] as $provider) {
        $app->bind($provider, fn () => throw new LogicException('External provider forbidden in synthetic gate'));
    }
    config(['clubunio-mobile.uploads_enabled' => false, 'clubunio-expense-ocr.enabled' => false, 'cache.default' => 'array']);
    foreach (['mobile_expense_receipts', 'mobile_expense_commands'] as $table) {
        if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table)) throw new LogicException('Reviewed expense88 schema required');
    }
    if (! $db->table('clubora.migrations')->where('migration', '2026_11_03_000088_mobile_expense_receipts')->exists()) {
        throw new LogicException('Reviewed expense88 migration ledger required');
    }
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $checks = 0;
    $expect = function ($actual, $expected) use (&$checks) {
        if ($actual !== $expected) throw new LogicException('Unexpected expense functional result');
        $checks++;
    };
    $call = function (string $method, string $path, array $body = [], ?string $token = null) use ($kernel, &$stage): array {
        $stage = $method.' '.$path;
        $server = ['HTTPS'=>'on', 'HTTP_HOST'=>'dev.clubunio.ch', 'REMOTE_ADDR'=>'127.0.0.1', 'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json', 'HTTP_X_CLUBUNIO_MOBILE_FEATURES'=>'expenses-v1'];
        if ($token) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [], $server, json_encode($body, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request); $stage .= ' [HTTP '.$response->getStatusCode().']';
        return [$response->getStatusCode(), json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR)];
    };
    $db->beginTransaction(); $db->statement("SET LOCAL application_name TO 'clubunio-expenses-functional-release-gate'");
    $tenant = App\ClubUnio\Models\Tenant::create(['slug'=>'qa-expense-'.bin2hex(random_bytes(6)), 'name'=>'Synthetic expense release']);
    $insert = fn (string $table, array $row) => $db->table('clubora.'.$table)->insertGetId($row + ['tenant_id'=>$tenant->id]);
    $accounts = []; $members = []; $tokens = []; $password = bin2hex(random_bytes(16));
    foreach (['trainer','technical_lead','member'] as $role) {
        $actor = new App\ClubUnio\Models\Account;
        $actor->forceFill(['username'=>'qa-expense-'.bin2hex(random_bytes(8)), 'display_name'=>'Synthetic '.$role,
            'password'=>Illuminate\Support\Facades\Hash::make($password), 'active'=>true, 'api_only'=>false])->save();
        $insert('tenant_accounts', ['account_id'=>$actor->id, 'role'=>$role, 'portal_enabled'=>true]);
        $member = $insert('members', ['first_name'=>'Synthetic', 'last_name'=>$role, 'birth_date'=>'1980-01-01']);
        $insert('account_member_links', ['account_id'=>$actor->id, 'member_id'=>$member, 'kind'=>'self', 'state'=>'approved', 'source'=>'synthetic-release']);
        [$status, $login] = $call('POST', 'session', ['username'=>$actor->username, 'password'=>$password, 'device_name'=>'Synthetic expense release']);
        $expect($status, 200); $accounts[$role]=$actor; $members[$role]=$member; $tokens[$role]=$login['data']['token'];
    }
    $season = $insert('seasons', ['name'=>'Synthetic release season']);
    $insert('tenant_settings', ['area'=>'general','key'=>'SaisonID','label'=>'Synthetic season','value_type'=>'integer',
        'encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString((string)$season)]);
    $team = $insert('team_seasons', ['team_id'=>$insert('teams', ['name'=>'Synthetic release team']), 'season_id'=>$season, 'label'=>'Synthetic release team']);
    $insert('team_staff', ['team_season_id'=>$team, 'member_id'=>$members['trainer']]);
    $insert('technical_leads', ['team_season_id'=>$team, 'member_id'=>$members['technical_lead']]);
    $institution = $insert('banks', ['name'=>'Synthetic release bank']);
    $round = $insert('expense_rounds', ['name'=>'Synthetic round', 'code'=>'V']);
    $type = $insert('expense_types', ['name'=>'Synthetic manual position', 'receipt_required'=>false]);
    foreach (['O','F','Z','R','B'] as $code) $insert('expense_statuses', ['name'=>$code, 'code'=>$code]);
    $base = 'clubs/'.$tenant->id.'/expenses'; $trainer = $tokens['trainer'];
    foreach (['expenses'=>App\ClubUnio\Mobile\MobileExpenseIndex::class, 'expenses/options'=>App\ClubUnio\Mobile\MobileExpenseOptions::class] as $suffix=>$controller) {
        $route = Illuminate\Support\Facades\Route::getRoutes()->match(Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/'.$suffix, 'GET'));
        $expect($route->getActionName(), $controller.'@index');
        $expect(in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true), true);
    }
    [$status, $options] = $call('GET', $base.'/options', [], $trainer); $expect($status, 200);
    $expect($options['data']['teams'][0]['id'], $team);
    [$status] = $call('GET', $base, [], $trainer); $expect($status, 200);
    if (! $originalNative) {
        [$status] = $call('POST', $base.'/banks', ['member_id'=>$members['trainer'], 'bank_id'=>$institution, 'iban'=>'CH9300762011623852957'], $trainer);
        $expect($status, 503);
        // Exercise dormant code only inside this rollback process. This does not certify live activation.
        config(['clubunio-mobile.native_expenses_enabled'=>true]);
    }
    [$status, $options] = $call('GET', $base.'/options', [], $trainer); $expect($status, 200);
    $expect(array_key_exists('clearing_number', $options['data']['institutions'][0]), true);
    $expect($options['data']['capabilities']['upload'], false); $expect($options['data']['capabilities']['ai'], false);
    [$status, $bank] = $call('POST', $base.'/banks', ['member_id'=>$members['trainer'], 'bank_id'=>$institution, 'iban'=>'CH9300762011623852957'], $trainer);
    $expect($status, 200); $bankId = $bank['data']['bank']['BankkontoStaffID'];
    [$status] = $call('PATCH', $base.'/banks/'.$bankId, ['revision'=>999, 'bank_id'=>$institution, 'iban'=>'CH9300762011623852957'], $trainer); $expect($status, 422);
    $input = ['command_key'=>(string)Illuminate\Support\Str::uuid(), 'member_id'=>$members['trainer'], 'team_season_id'=>$team,
        'bank_account_id'=>$bankId, 'round_id'=>$round, 'notes'=>'Synthetic release draft'];
    [$status, $created] = $call('POST', $base, $input, $trainer); $expect($status, 200); $id = $created['data']['receipt']['SpesenID'];
    [$status, $replayed] = $call('POST', $base, $input, $trainer); $expect($status, 200); $expect($replayed['data']['receipt']['SpesenID'], $id);
    [$status] = $call('POST', $base, array_replace($input,['notes'=>'Different synthetic operation']), $trainer); $expect($status, 409);
    [$status, $list] = $call('GET', $base, [], $trainer); $expect($status, 200); $expect($list['data']['rows'][0]['SpesenID'], $id);
    $expect($list['data']['rows'][0]['CanAddPosition'], true);
    [$status, $line] = $call('POST', $base.'/'.$id.'/lines', ['command_key'=>(string)Illuminate\Support\Str::uuid(), 'expense_revision'=>1,
        'expense_type_id'=>$type, 'amount'=>'12.50', 'notes'=>'Synthetic manual line'], $trainer);
    $expect($status, 200); $lineId=$line['data']['line_id']; $expect($line['data']['receipt']['Revision'], 2);
    [$status] = $call('PATCH', $base.'/'.$id.'/lines/'.$lineId, ['expense_revision'=>1, 'revision'=>1,'expense_type_id'=>$type,'amount'=>'20.00'], $trainer); $expect($status, 422);
    [$status] = $call('GET', $base.'/'.$id, [], $tokens['member']); $expect($status, 404);
    [$status, $lead] = $call('GET', $base.'/'.$id, [], $tokens['technical_lead']); $expect($status, 200); $expect($lead['data']['receipt']['_BankLabel'], '');
    [$status, $leadBanks] = $call('GET', $base.'/banks', [], $tokens['technical_lead']); $expect($status, 200); $expect($leadBanks['data']['rows'], []);
    [$status, $released] = $call('POST', $base.'/'.$id.'/transition', ['revision'=>2,'status'=>'F','reason'=>'Synthetic release validation'], $trainer);
    $expect($status, 200); $expect($released['data']['receipt']['Revision'], 3);
    [$status, $stale] = $call('POST', $base.'/'.$id.'/transition', ['revision'=>2,'status'=>'F','reason'=>'Synthetic stale revision'], $trainer); $expect($status, 422);
    $expect($stale['error']['code'] ?? null, 'validation_failed');
    $expect(array_key_exists('revision', $stale['error']['fields'] ?? []), true);
    $expect((int) $db->table('clubora.expenses')->where('tenant_id', $tenant->id)->where('id', $id)->value('revision'), 3);
    [$status] = $call('POST', $base.'/'.$id.'/transition', ['revision'=>3,'status'=>'Z','reason'=>'Synthetic forbidden approval'], $trainer); $expect($status, 403);
    [$status, $approved] = $call('POST', $base.'/'.$id.'/transition', ['revision'=>3,'status'=>'Z','reason'=>'Synthetic lead approval'], $tokens['technical_lead']);
    $expect($status, 200); $expect($approved['data']['receipt']['Revision'], 4); $expect($approved['data']['receipt']['Status'], 'Z');
    [$status] = $call('POST', $base.'/'.$id.'/transition', ['revision'=>4,'status'=>'B','reason'=>'Synthetic paid status must fail'], $tokens['technical_lead']); $expect($status, 422);
    $expect($db->table('clubora.expenses')->where('tenant_id',$tenant->id)->where('id',$id)->value('paid_on'), null);
    // CLI release hosts need no PHPUnit/dev dependency for these checks.
    $expect(Illuminate\Support\Facades\Http::recorded()->count(), 0);
    $expect(Illuminate\Support\Facades\Mail::sent(Illuminate\Mail\Mailable::class)->count(), 0);
    $expect(Illuminate\Support\Facades\Mail::queued(Illuminate\Mail\Mailable::class)->count(), 0);
    $stage = 'rollback';
    while ($db->transactionLevel()) $db->rollBack();
    $expect($db->table('clubora.tenants')->where('id',$tenant->id)->exists(), false);
    echo 'Expense HTTP-kernel gate: '.$checks.' checks, '.($originalNative?'configured enabled native workflow':'closed foundation plus process-local native workflow')." passed; all synthetic records rolled back.\n";
    echo "Scanner/upload/blob/real provider and device acceptance remain explicitly open; no payment, mail or external request performed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Expense functional gate failed after '.($checks ?? 0).' checks at '.$stage.': '.get_class($error)." (record contents suppressed).\n"); $failed=true;
} finally {
    if ($db) while ($db->transactionLevel()) $db->rollBack();
    if ($originalNative !== null) config(['clubunio-mobile.native_expenses_enabled'=>$originalNative]);
}
if ($failed) exit(1);
