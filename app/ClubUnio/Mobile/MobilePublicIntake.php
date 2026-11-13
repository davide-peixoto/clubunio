<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{DocumentUploadService, PublicWebService, RegistrationDataService, RegistrationDocumentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, RateLimiter, Validator};
use Illuminate\Support\Str;

final class MobilePublicIntake
{
    private function table() { return DB::connection('clubunio')->table('clubora.mobile_public_intake_commands'); }
    private function canUpload(Tenant $tenant): bool
    {
        return config('clubunio-mobile.uploads_enabled', false) && app(\App\ClubUnio\Services\TenantDocumentStorage::class)->canUpload($tenant)
            && is_string(config('clubunio-mobile.attachments.clamd_socket')) && config('clubunio-mobile.attachments.clamd_socket') !== '';
    }
    private function choices(Tenant $tenant, bool $complete = false): array
    {
        $data = [];
        foreach (['Countries'=>'countries','Genders'=>'genders','BillingRecipients'=>'billing_recipients'] as $field=>$table) {
            $columns=['id','code','name']; if ($table==='countries') { $columns[]='iso3'; }
            if ($complete && $table==='billing_recipients') { $columns[]='email_field'; }
            $data[$field]=DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id',$tenant->id)->where('active',true)->orderBy('id')->get($columns)->map(fn ($row)=>(array)$row)->all();
        }
        return $data;
    }
    private function authorize(Tenant $tenant, bool $family): void
    {
        if ($family) {
            abort_unless(app(PublicWebService::class)->enabled($tenant, 'registration'), 503);
        } else {
            app(MobilePublicIdentityPolicy::class)->authorize($tenant, 'membership');
        }
    }

    public function options(Request $request, Tenant $tenant, bool $family = false): array
    {
        $this->authorize($tenant, $family);
        $upload=(bool)$this->canUpload($tenant);
        $createAccount=!$family && app(MobilePublicIdentityPolicy::class)->membershipAccount($tenant);
        $complete = in_array('complete-intake-v1', explode(',', substr((string) $request->header('X-ClubUnio-Mobile-Features'), 0, 512)), true);
        $choices = $this->choices($tenant, $complete);
        $claim=['v'=>1,'tenant'=>(int)$tenant->id,'origin'=>$family ? $request->getSchemeAndHttpHost() : $request->attributes->get('mobile.public.origin'),'key'=>(string)Str::uuid(),
            'complete'=>$complete,
            'actor'=>$family ? (int) $request->attributes->get('mobile.actor')->id : null,
            'at'=>time(),'expires'=>time()+3600,'choices'=>hash('sha256',json_encode($choices,JSON_THROW_ON_ERROR)),'upload'=>$upload,'account'=>$createAccount];
        return $choices+['claim'=>Crypt::encryptString(json_encode($claim,JSON_THROW_ON_ERROR)),'CanUpload'=>$upload,'CreateAccount'=>$createAccount,'CompleteIntake'=>$complete,
            'Limits'=>['portrait_count'=>1,'portrait_bytes'=>5242880,'identity_count'=>3,'identity_bytes'=>10485760,'total_file_bytes'=>36700160]];
    }
    private function claim(Request $request, Tenant $tenant, bool $family): array
    {
        $d=$request->validate(['claim'=>'required|string|max:8192','consent'=>'required|accepted']);
        try { $claim=json_decode(Crypt::decryptString($d['claim']),true,16,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { abort(409,'Formular bitte neu öffnen.'); }
        $origin = $family ? $request->getSchemeAndHttpHost() : $request->attributes->get('mobile.public.origin');
        abort_unless(($claim['v']??null)===1 && ($claim['tenant']??null)===(int)$tenant->id && ($claim['origin']??null)===$origin
            && ($claim['actor']??null) === ($family ? (int) $request->attributes->get('mobile.actor')->id : null)
            && is_string($claim['key']??null) && Str::isUuid($claim['key']) && ($claim['expires']??0)>=time() && ($claim['at']??PHP_INT_MAX)<=time()-3,409,'Formular bitte prüfen oder neu öffnen.');
        return $claim;
    }
    public function submit(Request $request, Tenant $tenant, bool $family = false): array
    {
        $this->authorize($tenant, $family);
        abort_if(array_diff(array_keys($request->all()),['claim','values','consent','portrait','identity','account_password']),422);
        $claim=$this->claim($request,$tenant,$family);
        $password=$request->validate(['account_password'=>'sometimes|required|string|min:12|max:72'])['account_password'] ?? null;
        if ($password !== null) {
            abort_unless(!$family && ($claim['account'] ?? false) && app(MobilePublicIdentityPolicy::class)->membershipAccount($tenant), 503);
            abort_if(strlen($password) > 72, 422);
        }
        $raw=$request->input('values');
        if (is_string($raw)) { abort_if(strlen($raw)>65536,413); try { $raw=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (\Throwable) { abort(422); } }
        abort_unless(is_array($raw) && strlen(json_encode($raw,JSON_THROW_ON_ERROR))<=65536,422);
        $complete = ($claim['complete'] ?? false) === true;
        abort_if(array_diff(array_keys($raw), array_keys(MobileRegistrationData::FIELDS + ($complete ? ['AHVNummer' => 'ahv'] : []))),422);
        foreach ($raw as $value) { abort_unless(is_null($value)||is_scalar($value),422); } ksort($raw);
        $request->validate(['portrait'=>'nullable|file|max:5120|mimetypes:image/jpeg,image/png,image/webp',
            'identity'=>'nullable|array|list|max:3','identity.*'=>'file|max:10240|mimetypes:application/pdf,image/jpeg,image/png,image/webp']);
        if ($complete) {
            $birth = Validator::make($raw, ['Geburtsdatum' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate()['Geburtsdatum'];
            $minor = \Carbon\CarbonImmutable::parse($birth, 'Europe/Zurich')->addYearsNoOverflow(18)->gt(now('Europe/Zurich')->startOfDay());
            $required = array_fill_keys(['HausNr','Geburtsort','Mobile'], 'required|string');
            if ($minor) {
                $required += array_fill_keys(['Vater','Mutter','MobileVater','MobileMutter','EmailVater','EmailMutter'], 'required|string');
            }
            Validator::make($raw, $required + [
                'AHVNummer'=>['required','string','regex:/^756(?:[. ]?\d){10}$/D'],
                'HatVorverein'=>'required|in:J,N',
                'VorvereinLandTyp'=>'required_if:HatVorverein,J|nullable|in:I,A',
                'VorvereinName'=>'required_if:HatVorverein,J|nullable|string|max:255',
            ])->validate();
            $request->validate(['portrait'=>'required', 'identity'=>'required|array|min:1']);
        }
        $files=[];
        if ($request->hasFile('portrait')) { $files[]=['kind'=>'portrait','file'=>$request->file('portrait')]; }
        foreach ($request->file('identity',[]) as $file) { $files[]=['kind'=>'identity','file'=>$file]; }
        abort_unless(count($files)<=4 && array_sum(array_map(fn ($file)=>$file['file']->getSize(),$files))<=36700160,413);
        abort_if($files && (!($claim['upload']??false)||!$this->canUpload($tenant)),422,'Anhänge sind derzeit nicht freigeschaltet.');
        $digests=[];
        foreach ($files as &$entry) {
            $bytes=$entry['file']->get(); $entry['sha256']=hash('sha256',$bytes);
            $digests[]=['kind'=>$entry['kind'],'sha256'=>$entry['sha256'],'name'=>$entry['file']->getClientOriginalName()];
        } unset($entry);
        $hash=hash_hmac('sha256',json_encode($password === null ? [$raw,$digests] : [$raw,$digests,$password],JSON_THROW_ON_ERROR),(string)config('app.key'));
        $old=$this->table()->where('tenant_id',$tenant->id)->where('request_key',$claim['key'])->first();
        if ($old) { abort_unless(hash_equals($old->payload_sha256,$hash),409,'Dieser Formularstand wurde bereits anders eingereicht.'); return ['Submitted'=>true,'PendingAttachments'=>(int)$old->pending_attachments]; }
        $rate='public-intake:'.$tenant->id.':'.hash('sha256',$request->ip());
        abort_if(RateLimiter::tooManyAttempts($rate,10),429); RateLimiter::hit($rate,3600);
        $evidence=[];
        foreach ($files as $entry) {
            $bytes=$entry['file']->get();
            abort_unless(hash_equals($entry['sha256'],hash('sha256',$bytes)),409);
            $scan=app(MalwareScanner::class)->scan($bytes);
            abort_unless(($scan['clean']??false)===true && is_string($scan['version']??null) && strlen($scan['version'])>=1 && strlen($scan['version'])<=160,422,'Anhang konnte nicht sicher geprüft werden.');
            $evidence[]=['kind'=>$entry['kind'],'sha256'=>$entry['sha256'],'scanner'=>$scan['version'],'scanned_at'=>now()->toIso8601String()];
        }
        $reply=DB::connection('clubunio')->transaction(function () use ($request,$tenant,$claim,$raw,$files,$evidence,$hash,$password,$family,$complete) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $family);
            if ($family) {
                DB::connection('clubunio')->table('clubora.mobile_sessions')
                    ->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
                $actor = app(MobileSessions::class)->authenticate($request);
                abort_unless((int) $actor->id === $claim['actor'], 403);
                $request->attributes->remove(MobileReadContext::class);
                app(MobileAccess::class)->enter($actor, $tenant);
            }
            $old=$this->table()->where('tenant_id',$tenant->id)->where('request_key',$claim['key'])->first();
            if ($old) { abort_unless(hash_equals($old->payload_sha256,$hash),409); return ['Submitted'=>true,'PendingAttachments'=>(int)$old->pending_attachments]; }
            abort_unless(hash_equals($claim['choices'],hash('sha256',json_encode($this->choices($tenant, $complete),JSON_THROW_ON_ERROR))),409,'Auswahl wurde geändert. Formular bitte neu öffnen.');
            abort_if($files&&!$this->canUpload($tenant),422);
            $fields = $raw;
            unset($fields['AHVNummer']);
            if (($fields['HatVorverein'] ?? null) === 'N') {
                unset($fields['VorvereinLandTyp'], $fields['VorvereinName']);
            }
            $values=app(MobileRegistrationCommands::class)->values($tenant,$fields);
            $ahv = $complete ? preg_replace('/\D/', '', $raw['AHVNummer']) : null;
            $registration=app(RegistrationDataService::class)->submitPublic($tenant,$values,$claim['key'],$ahv);
            if ($family) {
                DB::connection('clubunio')->table('clubora.mobile_registration_requests')->insert([
                    'tenant_id'=>$tenant->id,'account_id'=>$claim['actor'],'request_key'=>$claim['key'],
                    'payload_sha256'=>$hash,'registration_id'=>$registration->id,
                ]);
            }
            foreach ($files as $entry) {
                app(RegistrationDocumentService::class)->addPublic($tenant,$registration,$claim['key'],$entry['kind'],$entry['file']);
                // Tie the staged bytes to the actual scanned digest before committing anything.
                abort_unless(app(DocumentUploadService::class)->table()->where('tenant_id',$tenant->id)->where('kind','registration')->where('object_id',$registration->id)
                    ->where('public_request_id',$claim['key'])->where('sha256',$entry['sha256'])->exists(),409);
            }
            $pending=app(DocumentUploadService::class)->table()->where('tenant_id',$tenant->id)->where('kind','registration')->where('object_id',$registration->id)
                ->where('public_request_id',$claim['key'])->where('state','pending')->count();
            $this->table()->insert(['tenant_id'=>$tenant->id,'request_key'=>$claim['key'],'payload_sha256'=>$hash,'registration_id'=>$registration->id,
                'pending_attachments'=>$pending,'scan_evidence_encrypted'=>$evidence?Crypt::encryptString(json_encode($evidence,JSON_THROW_ON_ERROR)):null]);
            if ($password !== null) {
                abort_unless(app(MobilePublicIdentityPolicy::class)->membershipAccount($tenant), 503);
                $identity = $request->duplicate();
                $identity->setJson(new \Symfony\Component\HttpFoundation\InputBag());
                $accountEmail = $registration->email;
                if ($complete && ! $accountEmail) {
                    $emailField = DB::connection('clubunio')->table('clubora.billing_recipients')
                        ->where('tenant_id', $tenant->id)->where('id', $values['billing_recipient_id'])->value('email_field');
                    $accountEmail = $values[$emailField] ?? null;
                }
                $identity->replace(['email' => $accountEmail, 'first_name' => $registration->first_name,
                    'last_name' => $registration->last_name, 'password' => $password, 'request_key' => $claim['key']]);
                $identity->files->replace([]);
                app(MobileIdentityChallenges::class)->request($identity, 'register', (int) $registration->id);
            }
            return ['Submitted'=>true,'PendingAttachments'=>$pending];
        });
        if ($files) {
            DB::connection('clubunio')->afterCommit(function () use ($tenant,$claim,$family) {
                if (config('clubunio-mobile.public_identity.dispatch_after_response',true)) { app()->terminating(fn ()=>$this->publish((int)$tenant->id,$claim['key'],$family)); }
            });
        }
        return $reply;
    }
    public function publish(int $tenantId,string $key,bool $family = false): void
    {
        try {
            $tenant=Tenant::findOrFail($tenantId); $this->authorize($tenant,$family);
            abort_unless($this->canUpload($tenant),503);
            $command=$this->table()->where('tenant_id',$tenantId)->where('request_key',$key)->firstOrFail();
            $uploads=app(DocumentUploadService::class);
            foreach ($uploads->table()->where('tenant_id',$tenantId)->where('kind','registration')->where('object_id',$command->registration_id)
                ->where('public_request_id',$key)->where('state','pending')->orderBy('id')->pluck('id') as $id) { $uploads->attempt(null,$tenant,(int)$id); }
            $pending=$uploads->table()->where('tenant_id',$tenantId)->where('kind','registration')->where('object_id',$command->registration_id)->where('public_request_id',$key)->where('state','pending')->count();
            $this->table()->where('tenant_id',$tenantId)->where('request_key',$key)->update(['pending_attachments'=>$pending]);
        } catch (\Throwable) { /* Existing protected upload/synchronization records retain failures; no private payload logging. */ }
    }
}
