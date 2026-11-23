<?php

/** Synthetic service assertions, shared with isolated SQLite tests and live PG rollback checks. */
function clubunioParentAccessChecks($app, $db, callable $expect): void
{
    config(['clubunio-parent-access.enabled' => true, 'clubunio-verein-oidc.invites_enabled' => false,
        'clubunio-verein-oidc.issuer' => 'https://identity.example.invalid/realms/synthetic']);
    $suffix = bin2hex(random_bytes(7));
    $tenant = App\ClubUnio\Models\Tenant::create(['slug' => 'parent-qa-'.$suffix, 'name' => 'Synthetic Parent Club']);
    $foreign = App\ClubUnio\Models\Tenant::create(['slug' => 'parent-other-'.$suffix, 'name' => 'Other Synthetic Parent Club']);
    $account = function (string $name) use ($db, $tenant, $suffix) {
        $row = new App\ClubUnio\Models\Account;
        $row->forceFill(['username' => $name.'-'.$suffix.'@example.invalid', 'display_name' => 'Synthetic '.$name,
            'password' => password_hash($suffix, PASSWORD_BCRYPT, ['cost' => 4]), 'active' => true, 'api_only' => false])->save();
        $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $row->id, 'role' => 'member', 'portal_enabled' => true]);
        return $row;
    };
    $parent = $account('guardian'); $owner = $account('adult'); $stranger = $account('stranger');
    $member = function (string $name, string $birth, ?string $mother = null, ?string $father = null) use ($tenant) {
        $row = new App\ClubUnio\Models\Member;
        $row->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => $name,
            'birth_date' => $birth, 'mother_email' => $mother, 'father_email' => $father])->save();
        return $row;
    };
    $today = now('Europe/Zurich')->startOfDay();
    $minor = $member('Minor', $today->copy()->subYears(10)->toDateString(), $parent->username);
    $father = $member('FatherMatch', $today->copy()->subYears(8)->toDateString(), null, strtoupper($parent->username));
    $adult = $member('Adult', $today->copy()->subYears(23)->toDateString(), $parent->username);
    $revoked = $member('Revoked', $today->copy()->subYears(9)->toDateString(), $parent->username);
    $held = $member('ManualPending', $today->copy()->subYears(9)->toDateString(), $parent->username);
    $link = function ($actor, $person, string $kind = 'parent', string $state = 'pending', ?int $reviewer = null) use ($db, $tenant) {
        return $db->table('clubora.account_member_links')->insertGetId(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,
            'member_id'=>$person->id,'kind'=>$kind,'state'=>$state,'source'=>'snapshot_parent_email','reviewed_by'=>$reviewer,'revision'=>1]);
    };
    $minorLink = $link($parent,$minor); $fatherLink = $link($parent,$father);
    $revokedLink = $link($parent,$revoked,'parent','revoked',$owner->id);
    $heldLink = $link($parent,$held,'parent','pending',$owner->id);
    $link($owner,$adult,'self','approved');
    $service = app(App\ClubUnio\Services\ParentAccessService::class);
    $policy = app(App\ClubUnio\Services\ParentAccessPolicy::class);
    $row = fn ($id) => $db->table('clubora.account_member_links')->where('id',$id)->first();
    $parentLink = fn ($person) => $db->table('clubora.account_member_links')->where('account_id',$parent->id)->where('member_id',$person->id)->where('kind','parent')->first();
    $service->synchronize($parent,$tenant);
    $expect($row($minorLink)->state === 'pending', 'username alone cannot approve');
    $claims = (object) ['email'=>$parent->username,'email_verified'=>false,'iss'=>config('clubunio-verein-oidc.issuer')];
    $service->verifiedLogin($parent,$claims,$tenant);
    $expect($row($minorLink)->state === 'pending', 'unverified login cannot approve');
    $claims->email_verified = true; $claims->iss = 'https://wrong-issuer.example.invalid';
    $service->verifiedLogin($parent,$claims,$tenant);
    $expect($row($minorLink)->state === 'pending', 'foreign issuer cannot approve');
    $claims->iss = config('clubunio-verein-oidc.issuer');
    $service->verifiedLogin($parent,$claims,$tenant);
    $newParent = $account('newguardian');
    $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$newParent->id)->delete();
    $newChild = $member('NewGuardianChild',$today->copy()->subYears(7)->toDateString(),$newParent->username);
    $newClaims = (object) ['email'=>$newParent->username,'email_verified'=>true,'iss'=>config('clubunio-verein-oidc.issuer')];
    $service->verifiedLogin($newParent,$newClaims,$tenant);
    $expect($db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$newParent->id)->exists(),
        'first verified minor parent receives tenant membership before link creation');
    $newLink = $db->table('clubora.account_member_links')->where('tenant_id',$tenant->id)->where('account_id',$newParent->id)->where('member_id',$newChild->id)->first();
    $expect($newLink !== null && $newLink->state==='approved' && $policy->effective($newLink,$newChild),'first minor parent receives effective representation');
    $expect($row($minorLink)->state === 'approved' && $policy->effective($row($minorLink),$minor), 'verified mother receives access');
    $expect($row($fatherLink)->state === 'approved', 'verified father receives access');
    $expect($row($revokedLink)->state === 'revoked' && $row($heldLink)->state === 'pending', 'manual decisions remain authoritative');
    $adultLink = $parentLink($adult);
    $expect($adultLink !== null && $adultLink->state === 'pending' && ! $policy->effective($adultLink,$adult), 'adult never auto approved');
    $service->verifiedLogin($parent,$claims,$tenant);
    $expect((int)$row($minorLink)->revision === 2, 'repeated verified login is idempotent');
    $claims->email_verified = false; $service->verifiedLogin($parent,$claims,$tenant);
    $expect(! $policy->effective($row($minorLink),$minor), 'loss of email verification removes automatic authority');
    $claims->email_verified = true; $service->verifiedLogin($parent,$claims,$tenant);
    $minor->forceFill(['birth_date'=>$today->copy()->subYears(18)->toDateString()])->save();
    $expect(! $policy->effective($row($minorLink),$minor), 'authority expires on eighteenth birthday');
    $minor->forceFill(['birth_date'=>$today->copy()->subYears(10)->toDateString(),'mother_email'=>'changed-'.$suffix.'@example.invalid'])->save();
    $expect(! $policy->effective($row($minorLink),$minor), 'changed stored parent address invalidates automatic access');
    $minor->forceFill(['mother_email'=>$parent->username])->save();
    $denied = function (callable $action, int $status) use ($expect) {
        try { $action(); $expect(false, 'expected denial'); }
        catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $expect($error->getStatusCode()===$status,'correct denial status'); }
        catch (Illuminate\Database\Eloquent\ModelNotFoundException|Illuminate\Database\RecordNotFoundException) { $expect($status===404,'foreign object hidden'); }
    };
    $denied(fn()=> $service->decide($stranger,$tenant,$adultLink->id,$adultLink->revision,true),403);
    $denied(fn()=> $service->decide($parent,$tenant,$adultLink->id,$adultLink->revision,true),403);
    $denied(fn()=> $service->decide($owner,$foreign,$adultLink->id,$adultLink->revision,true),404);
    $denied(fn()=> $service->decide($owner,$tenant,$adultLink->id,$adultLink->revision+99,true),409);
    if ($db->getDriverName() !== 'pgsql') $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$parent->id)->delete();
    $service->decide($owner,$tenant,$adultLink->id,$adultLink->revision,true);
    $expect($db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$parent->id)
        ->where('role','member')->where('portal_enabled',true)->exists(),'adult consent establishes missing parent tenant membership');
    $approved = $row($adultLink->id);
    $expect($policy->effective($approved,$adult) && $approved->adult_consent_source==='member' && (int)$approved->adult_consent_by===(int)$owner->id,'own member can consent');
    $profile = $service->profile($owner,$tenant);
    $expect(count($profile['Members'])===1 && $profile['Members'][0]['MemberID']===(int)$adult->id,'profile Members only own identity');
    $parentProfile = $service->profile($parent,$tenant);
    $expect($parentProfile['Members']===[] && count($parentProfile['Children'])>=3,'parent children are separate');
    foreach ($parentProfile['Children'] as $child) $expect(! array_key_exists('CanApprove',$child) && ! array_key_exists('LinkID',$child),'children do not expose consent write handles');
    $service->decide($owner,$tenant,$approved->id,$approved->revision,false);
    $expect(! $policy->effective($row($approved->id),$adult),'own member can revoke immediately');
    $service->grant($owner,$tenant,$adult->id,$parent->username);
    $expect($policy->effective($parentLink($adult),$adult),'own member grant uses verified account');
    $newAdultParent = $account('newadultguardian');
    $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$newAdultParent->id)->delete();
    $service->verifiedLogin($newAdultParent,(object)['email'=>$newAdultParent->username,'email_verified'=>true,'iss'=>config('clubunio-verein-oidc.issuer')],$tenant);
    $expect(! $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$newAdultParent->id)->exists(),
        'email verification without matching relationship grants no tenant');
    $service->grant($owner,$tenant,$adult->id,$newAdultParent->username);
    $expect($db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$newAdultParent->id)->exists(),
        'explicit adult grant establishes new parent tenant membership before link');
    $newAdultLink = $db->table('clubora.account_member_links')->where('tenant_id',$tenant->id)->where('account_id',$newAdultParent->id)->where('member_id',$adult->id)->first();
    $expect($newAdultLink !== null && $policy->effective($newAdultLink,$adult),'new parent adult consent effective');
    $denied(fn()=> $service->grant($owner,$tenant,$adult->id,'unknown-'.$suffix.'@example.invalid'),422);
    $denied(fn()=> $service->grant($stranger,$tenant,$adult->id,$parent->username),403);
    if ($db->getDriverName()==='pgsql') {
        $db->statement('SAVEPOINT parent_partial_consent');
        try {
            $db->table('clubora.account_member_links')->where('id',$adultLink->id)->update(['adult_consent_at'=>null]);
            $partialRejected=false;
        } catch (Illuminate\Database\QueryException) { $partialRejected=true; }
        finally { $db->statement('ROLLBACK TO SAVEPOINT parent_partial_consent'); $db->statement('RELEASE SAVEPOINT parent_partial_consent'); }
        $expect($partialRejected,'database rejects partial adult consent');
        $access = app(App\ClubUnio\Services\MemberAccessService::class);
        $expect($access->represented($parent,$tenant)->whereKey($adult->id)->exists(),'actual SQL includes explicit adult consent');
        $otherLink = $link($stranger,$adult,'parent','approved');
        $expect(! $access->represented($stranger,$tenant)->whereKey($adult->id)->exists(),'consent is not shared with another parent');
        $recipients = $db->table('clubora.account_member_links as l')->where('l.tenant_id',$tenant->id)->where('l.member_id',$adult->id);
        $policy->scopePersonalLinks($recipients,$tenant,'l');
        $ids = $recipients->pluck('l.account_id')->map(fn($id)=>(int)$id)->all();
        $expect(in_array((int)$parent->id,$ids,true) && ! in_array((int)$stranger->id,$ids,true),'notification SQL enforces per-link authority');
        $minor->forceFill(['birth_date'=>$today->copy()->subYears(18)->toDateString()])->save();
        $expect(! $access->represented($parent,$tenant)->whereKey($minor->id)->exists(),'actual SQL expires automatic birthday access');
    }
    $expect($db->table('clubora.object_changes')->where('tenant_id',$tenant->id)->where('object_type','account_member_links')->count()>=6,'changes audited');
}

if (defined('CLUBUNIO_PARENT_ACCESS_TEST_LOADER')) return;
if (($argv[1]??'')!=='--synthetic-rollback' || !in_array($argv[2]??'', ['clubunio_dev','clubunio_stage','clubunio_prod'],true)) throw new RuntimeException('Explicit synthetic target required');
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$origins=['clubunio_dev'=>'https://dev.clubunio.ch','clubunio_stage'=>'https://stage.clubunio.com','clubunio_prod'=>'https://app.clubunio.com'];
if ($db->getDriverName()!=='pgsql' || $db->getDatabaseName()!==$argv[2] || $db->selectOne('select current_database() as name')->name!==$argv[2] || config('app.url')!==$origins[$argv[2]]) throw new RuntimeException('Unexpected database');
Illuminate\Support\Facades\Http::preventStrayRequests();
$db->beginTransaction();
try {
    $db->statement("SET LOCAL application_name='parent-access-synthetic-rollback'");
    $db->table('clubora.account_roles')->insertOrIgnore(['code'=>'member','name'=>'Synthetic member','description'=>'Rollback only','sort_order'=>0,'active'=>true]);
    $checks=0;
    clubunioParentAccessChecks($app,$db,function(bool $ok,string $case) use (&$checks) { if(!$ok) throw new RuntimeException('Synthetic assertion failed: '.$case); $checks++; });
    echo 'Parent access synthetic PostgreSQL checks passed: '.$checks."; rollback; no external delivery.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Synthetic check failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().(str_starts_with($error->getMessage(),'Synthetic assertion failed:')?' '.$error->getMessage():'')."\n"); $failed=true;
} finally { $db->rollBack(); }
if($failed??false) exit(1);
