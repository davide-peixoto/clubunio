<?php

// Standalone exact-DEV HTTP-kernel proof. No PHPUnit dependency; no real accounts or providers.
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberChangeService, MobileModuleSettings};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{Crypt, DB, Hash, Http, Mail, Queue};

$stage = 'bootstrap';
try {
    if (PHP_SAPI !== 'cli' || array_slice($argv, 1) !== ['--synthetic-rollback']) throw new LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')
        || !config('clubunio-mobile.native_my_teams_enabled') || !config('clubunio-mobile.native_members_enabled')
        || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Exact enabled standalone DEV required');
    foreach (['clubunio-mobile.ai.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled',
        'clubunio-mobile.keybox.provider_enabled'] as $flag) if (config($flag, false)) throw new LogicException('External providers must stay closed');
    Http::preventStrayRequests(); Mail::fake(); Queue::fake();
    $kernel = $app->make(Kernel::class); $checks = 0;
    $expect = function (bool $condition, string $label) use (&$checks, &$stage): void {
        $stage = $label;
        if (!$condition) throw new LogicException('Synthetic team-member contract failed');
        $checks++;
    };
    $call = function (string $method, string $path, ?string $token, array $body = [], int $expected = 200) use ($kernel, $expect, &$stage): array {
        $stage = $method.' '.$path;
        $server = ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json','HTTP_X_CLUBUNIO_MOBILE_FEATURES'=>'my-teams-v1,members-v1,member-documents-v1,member-id-card-v1'];
        if ($token) $server['HTTP_AUTHORIZATION']='Bearer '.$token;
        $request = Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [], $server, json_encode($body, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $expect($response->getStatusCode() === $expected, $stage.' expected '.$expected.' actual '.$response->getStatusCode());
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    };
    $tenantId = null; $accounts = []; $tokens = []; $members = [];
    $db->beginTransaction();
    try {
        $db->statement("SET LOCAL application_name TO 'clubunio-team-member-access-gate'");
        $db->statement("SET LOCAL lock_timeout TO '2s'");
        $db->statement("SET LOCAL statement_timeout TO '15s'");
        $tenant = Tenant::create(['slug'=>'team-access-'.bin2hex(random_bytes(10)),'name'=>'Synthetic team-member access']); $tenantId=(int)$tenant->id;
        $insert = fn(string $table, array $row) => $db->table('clubora.'.$table)->insertGetId($row+['tenant_id'=>$tenantId]);
        foreach (MobileModuleSettings::MODULES as $key=>$name) $insert('mobile_module_options',['key'=>$key,'name'=>$name]);
        foreach (['admin'=>'admin','trainer'=>'trainer','parent'=>'member','outside'=>'member'] as $key=>$role) {
            $actor = new Account; $password=bin2hex(random_bytes(16));
            $actor->forceFill(['username'=>'team-access-'.bin2hex(random_bytes(12)),'display_name'=>'Synthetic '.$key,
                'password'=>Hash::make($password),'active'=>true,'api_only'=>false])->save();
            $accounts[$key]=(int)$actor->id;
            $insert('tenant_accounts',['account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);
            $members[$key]=$insert('members',['first_name'=>'Synthetic','last_name'=>$key,'birth_date'=>'1980-01-01','email'=>$key.'@example.invalid']);
            $insert('account_member_links',['account_id'=>$actor->id,'member_id'=>$members[$key],'kind'=>'self','state'=>'approved','source'=>'synthetic-team-access']);
            $login=$call('POST','session',null,['username'=>$actor->username,'password'=>$password,'device_name'=>'Synthetic team member gate']);
            $tokens[$key]=$login['data']['token'];
        }
        $season=$insert('seasons',['name'=>'Synthetic current','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic season','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$season)]);
        $team=$insert('team_seasons',['team_id'=>$insert('teams',['name'=>'Synthetic team']),'season_id'=>$season,'label'=>'Synthetic team']);
        $otherTeam=$insert('team_seasons',['team_id'=>$insert('teams',['name'=>'Other synthetic team']),'season_id'=>$season,'label'=>'Other synthetic team']);
        $trainerFunction=$insert('staff_functions',['code'=>'TRAINER','name'=>'Trainer']);
        $staff=$insert('team_staff',['team_season_id'=>$team,'member_id'=>$members['trainer'],'staff_function_id'=>$trainerFunction]);
        $child=$insert('members',['first_name'=>'Synthetic','last_name'=>'Own child','birth_date'=>now()->subYears(10)->toDateString(),'email'=>'private-child@example.invalid','mother_name'=>'PRIVATE-MOTHER']);
        $other=$insert('members',['first_name'=>'Synthetic','last_name'=>'Other player','email'=>'private-player@example.invalid']);
        $pending=$insert('members',['first_name'=>'Synthetic','last_name'=>'Pending child','birth_date'=>now()->subYears(8)->toDateString()]);
        $parentLink=$insert('account_member_links',['account_id'=>$accounts['parent'],'member_id'=>$child,'kind'=>'parent','state'=>'approved','source'=>'synthetic-team-access']);
        $insert('account_member_links',['account_id'=>$accounts['parent'],'member_id'=>$pending,'kind'=>'parent','state'=>'pending','source'=>'synthetic-team-access']);
        foreach ([$child,$other] as $member) $insert('team_players',['team_season_id'=>$team,'member_id'=>$member]);
        foreach (['pending','approved','rejected','withdrawn'] as $state) $insert('member_change_statuses',['code'=>$state,'name'=>$state,'color'=>'#164B8A']);
        $foreign=Tenant::create(['slug'=>'team-access-foreign-'.bin2hex(random_bytes(8)),'name'=>'Foreign synthetic team access']);
        $foreignId=(int)$foreign->id;
        $base='clubs/'.$tenantId.'/'; $url=$base.'members/'.$child; $context='?team_id='.$team;
        $sessions=$call('GET','sessions',$tokens['trainer']);
        $expect(count($sessions['data'])===1 && $sessions['data'][0]['current']===true,'Own current session only');
        $call('GET',$base.'my-teams/'.$team,$tokens['outside'],[],404);
        $call('GET','clubs/'.$foreignId.'/members/'.$child.'?team_id='.$team,$tokens['trainer'],[],403);
        $plain=$call('GET',$url,$tokens['trainer']); $expect($plain['data']['Member']['CanEdit']===false,'No implicit trainer request outside team context');
        $detail=$call('GET',$url.$context,$tokens['trainer'])['data']['Member'];
        $expect($detail['TeamSaisonID']===$team && !$detail['ContactOnly'] && $detail['CanRequestChanges'] && $detail['ChangeMode']==='request','Responsible trainer detail/request');
        $expect(!$detail['CanReadInvoices'] && !$detail['CanReadDocuments'] && !$detail['CanReadIdCard'] && !array_key_exists('RechnungAn',$detail),'No financial/document/card expansion');
        $db->table('clubora.staff_functions')->where('id',$trainerFunction)->update(['code'=>'legacy_2','name'=>'Assistenztrainer']);
        $assistant=$call('GET',$url.$context,$tokens['trainer'])['data']['Member']; $expect($assistant['CanRequestChanges'],'Explicit assistant trainer function');
        $db->table('clubora.staff_functions')->where('id',$trainerFunction)->update(['code'=>'TRAINER','name'=>'Trainer']);
        $options=$call('GET',$url.'/contact-options'.$context,$tokens['trainer']); $expect($options['data']['BillingRecipients']===[],'No trainer billing options');
        $call('GET',$url.'?team_id='.$otherTeam,$tokens['trainer'],[],404);
        $call('POST',$url.'/contact',$tokens['trainer'],['_version'=>$detail['_version'],'Mobile'=>'+41000000000'],403);
        $call('POST',$url.'/contact'.$context,$tokens['trainer'],['_version'=>$detail['_version'],'team_id'=>$team],422);
        $request=$call('POST',$url.'/contact'.$context,$tokens['trainer'],['_version'=>$detail['_version'],'Mobile'=>'+41000000000']);
        $expect($request['data']['action']==='member-contact-change-request','Request instead of direct update'); $change=(int)$request['data']['request_id'];
        $expect($db->table('clubora.members')->where('id',$child)->value('mobile')===null,'Member unchanged before office review');
        $record=App\ClubUnio\Models\MemberChangeRequest::withoutGlobalScopes()->findOrFail($change);
        $expect(app(MemberChangeService::class)->snapshot($record)['team_id']===$team,'Encrypted request context bound to team');
        $call('GET',$url.'/invoices',$tokens['trainer'],[],403);
        $call('POST',$url.'/change-requests/'.$change.'/decide',$tokens['trainer'],['Revision'=>1,'approve'=>true],403);
        $parentTeam=$call('GET',$base.'my-teams/'.$team,$tokens['parent'])['data'];
        $expect(count($parentTeam['Players'])===2,'Both own and other player names retained');
        foreach ($parentTeam['Players'] as $player) {
            $expect(!$player['CanOpenDossier'] && !$player['CanRequestChanges'],'Parent player has no action');
            foreach (['Email','Mobile','Ort','Geburtsdatum','Mutter'] as $field) $expect(!array_key_exists($field,$player),'Parent player lacks '.$field);
        }
        $expect($parentTeam['Staff'][0]['CanOpenDossier'] && $parentTeam['Staff'][0]['ContactOnly'],'Parent staff contact opens');
        $staffUrl=$base.'members/'.$members['trainer'];
        $contact=$call('GET',$staffUrl.$context,$tokens['parent'])['data']['Member'];
        $expect($contact['Email']==='trainer@example.invalid' && $contact['ContactOnly'] && !$contact['CanEdit'] && !$contact['CanReadInvoices'],'Fresh staff contact without edit/private rights');
        $call('GET',$staffUrl.'/contact-options'.$context,$tokens['parent'],[],403);
        $call('GET',$url.$context,$tokens['parent'],[],404);
        $call('GET',$base.'members/'.$other.$context,$tokens['parent'],[],404);
        $call('GET',$base.'members/'.$pending,$tokens['parent'],[],404);
        $personal=$call('GET',$url,$tokens['parent'])['data']['Member']; $expect($personal['CanEdit'] && $personal['CanReadInvoices'],'Personal own-child dossier unaffected');
        $db->table('clubora.tenant_accounts')->where('tenant_id',$tenantId)->where('account_id',$accounts['parent'])->update(['role'=>'trainer']);
        $insert('team_staff',['team_season_id'=>$otherTeam,'member_id'=>$members['parent'],'staff_function_id'=>$trainerFunction]);
        $helper=$insert('team_staff',['team_season_id'=>$team,'member_id'=>$members['parent'],'staff_function_id'=>$insert('staff_functions',['code'=>'HELPER','name'=>'Helper'])]);
        $dual=$call('GET',$base.'my-teams/'.$team,$tokens['parent'])['data']; $expect(!$dual['Players'][0]['CanOpenDossier'],'Unrelated trainer role remains parent in child team');
        $call('GET',$url.$context,$tokens['parent'],[],404);
        $call('GET',$base.'members/'.$other,$tokens['parent'],[],404);
        $db->table('clubora.team_staff')->where('id',$helper)->update(['active'=>false]);
        $db->table('clubora.account_member_links')->where('id',$parentLink)->update(['state'=>'pending']);
        $call('GET',$base.'my-teams/'.$team,$tokens['parent'],[],404);
        $db->table('clubora.team_staff')->where('id',$staff)->update(['active'=>false]);
        $call('GET',$url.$context,$tokens['trainer'],[],404);
        $call('POST',$url.'/change-requests/'.$change.'/decide',$tokens['admin'],['Revision'=>1,'approve'=>true],422);
        $call('POST',$url.'/change-requests/'.$change.'/decide',$tokens['admin'],['Revision'=>1,'approve'=>false,'Begruendung'=>'Synthetic revoked trainer scope']);
        $expect($db->table('clubora.members')->where('id',$child)->value('mobile')===null,'Rejected request leaves member unchanged');
        $expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty(),'No HTTP provider or mail');
    } finally { $db->rollBack(); }
    $expect($db->transactionLevel()===0 && !$db->table('clubora.tenants')->whereIn('id',[$tenantId,$foreignId])->exists()
        && !$db->table('clubora.accounts')->whereIn('id',array_values($accounts))->exists(),'Verified synthetic cleanup');
    echo "Personal team-member access verified: $checks checks, fixtures rolled back, no providers.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Team-member gate failed at '.$stage.': '.get_class($error).' (private details suppressed).'.PHP_EOL); exit(1);
}
