<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Hash, Mail, RateLimiter, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileIdentityChallenges
{
    private function table() { return DB::connection('clubunio')->table('clubunio.mobile_identity_challenges'); }
    private function emails() { return DB::connection('clubunio')->table('clubunio.mobile_account_emails'); }
    public function emailHash(string $email): string { return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key')); }
    private function rate(string $key, int $limit, int $seconds): void
    {
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429); RateLimiter::hit($key, $seconds);
    }
    private function password(array $input, bool $confirmation): string
    {
        $data = Validator::make($input, ['password' => 'required|string|min:12|max:72'.($confirmation ? '|confirmed' : ''),
            'password_confirmation' => $confirmation ? 'required|string|max:72' : 'prohibited'])->validate();
        if (strlen($data['password']) > 72) { throw ValidationException::withMessages(['password' => 'Bitte ein kürzeres Passwort wählen.']); }
        return $data['password'];
    }
    public function request(Request $request, string $purpose): array
    {
        abort_unless(in_array($purpose, ['register','reset'], true), 422);
        $tenant = $request->attributes->get('mobile.public.tenant'); $origin = $request->attributes->get('mobile.public.origin');
        app(MobilePublicIdentityPolicy::class)->authorize($tenant, $purpose === 'register' ? 'registration' : 'password_reset');
        $rules = ['email' => 'required|email:rfc|max:254', 'request_key' => 'required|uuid'];
        if ($purpose === 'register') { $rules += ['first_name' => 'required|string|min:2|max:255','last_name' => 'required|string|min:2|max:255','password' => 'required|string|min:12|max:72']; }
        abort_if(array_diff(array_keys($request->all()), array_keys($rules)), 422);
        $d = $request->validate($rules); $d['email'] = mb_strtolower(trim($d['email']));
        if ($purpose === 'register') { $this->password(['password' => $d['password']], false); $d['first_name'] = trim($d['first_name']); $d['last_name'] = trim($d['last_name']); }
        $identity = array_diff_key($d, ['request_key' => true]); ksort($identity);
        $fingerprint = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
        $this->rate('identity-ip:'.hash('sha256', $request->ip().'|'.$tenant->id), 10, 60);
        $password = $purpose === 'register' ? Hash::make($d['password']) : null;
        $created = DB::connection('clubunio')->transaction(function () use ($tenant, $origin, $purpose, $d, $fingerprint, $password) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(MobilePublicIdentityPolicy::class)->authorize($tenant, $purpose === 'register' ? 'registration' : 'password_reset');
            $old = $this->table()->where('tenant_id', $tenant->id)->where('purpose', $purpose)->where('request_key', $d['request_key'])->first();
            if ($old) { abort_unless(hash_equals($old->request_fingerprint, $fingerprint), 409); return null; }
            $this->rate('identity-email:'.$tenant->id.':'.$purpose.':'.$this->emailHash($d['email']), 3, 3600);
            if ($purpose === 'register' && Account::whereRaw('lower(username) = ?', [$d['email']])->exists()) { return null; }
            $candidates = $purpose === 'reset' ? $this->candidates($tenant,$this->emailHash($d['email'])) : [];
            if ($purpose === 'reset' && (!$candidates || count($candidates)>10)) { return null; }
            $payload = ['email' => $d['email'], 'origin' => $origin];
            if ($purpose === 'register') { $payload += ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'password_hash' => $password]; }
            else { $payload += ['candidates'=>$candidates]; }
            $id = (string) Str::uuid(); $secret = bin2hex(random_bytes(32));
            $delivery = ['email' => $d['email'], 'origin' => $origin, 'purpose' => $purpose, 'tenant_name' => $tenant->name, 'token' => $id.'.'.$secret];
            if ($purpose==='reset') { $delivery['account_names']=array_column($candidates,'username'); }
            $this->table()->insert(['id' => $id, 'tenant_id' => $tenant->id, 'purpose' => $purpose, 'request_key' => $d['request_key'],
                'request_fingerprint' => $fingerprint, 'email_hash' => $this->emailHash($d['email']), 'secret_hash' => hash('sha256', $secret),
                'account_id' => count($candidates)===1 ? $candidates[0]['id'] : null, 'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'delivery_encrypted' => Crypt::encryptString(MobileIdentityMail::canonical($delivery)),
                'expires_at' => ($purpose === 'register' ? now()->addDay() : now()->addMinutes(30))->toIso8601String()]);
            $expired = $this->table()->where('tenant_id', $tenant->id)->where('state', 'pending')->where('expires_at', '<', now()->toIso8601String())->limit(100)->pluck('id');
            $this->table()->whereIn('id', $expired)->update(['state' => 'invalidated','payload_encrypted' => null,'delivery_encrypted' => null,'delivery_state' => 'skipped']);
            return $id;
        });
        if ($created) {
            DB::connection('clubunio')->afterCommit(function () use ($created) {
                if (config('clubunio-mobile.public_identity.dispatch_after_response', true)) { app()->terminating(fn () => $this->dispatch($created)); }
            });
        }
        return ['accepted' => true, 'message' => 'Falls dieser Vorgang für deine Angaben möglich ist, erhältst du einen zeitlich begrenzten Link per E-Mail.'];
    }
    private function resetAllowed(Tenant $tenant, Account $account): bool
    {
        return $account->active && !$account->api_only && DB::connection('clubunio')->table('clubunio.tenant_accounts')->where('tenant_id', $tenant->id)
            ->where('account_id', $account->id)->where('role', '<>', 'profile')->exists();
    }
    private function candidates(Tenant $tenant,string $emailHash): array
    {
        $ids=$this->emails()->where('email_hash',$emailHash)->pluck('account_id'); $result=[];
        foreach (Account::whereIn('id',$ids)->orderBy('id')->limit(11)->get() as $account) {
            if (!$this->resetAllowed($tenant,$account)) { continue; }
            $email=$this->emails()->where('account_id',$account->id)->first();
            $result[]=['id'=>(int)$account->id,'username'=>$account->username,'account_revision'=>(int)$account->revision,
                'password_sha256'=>hash('sha256',$account->password),'email_revision'=>(int)$email->revision];
        }
        return $result;
    }
    private function candidate(Tenant $tenant,array $candidate,string $emailHash,bool $lock=false): ?Account
    {
        $query=Account::whereKey($candidate['id']); if ($lock) { $query->lockForUpdate(); } $account=$query->first();
        $query=$this->emails()->where('account_id',$candidate['id']); if ($lock) { $query->lockForUpdate(); } $email=$query->first();
        return $account && $email && $this->resetAllowed($tenant,$account) && (int)$account->revision===$candidate['account_revision']
            && hash_equals($candidate['password_sha256'],hash('sha256',$account->password)) && (int)$email->revision===$candidate['email_revision']
            && hash_equals($email->email_hash,$emailHash) ? $account : null;
    }
    private function bindEmail(Account $account,string $email,object $challenge,string $source): void
    {
        $old=$this->emails()->where('account_id',$account->id)->lockForUpdate()->first();
        $this->emails()->updateOrInsert(['account_id'=>$account->id],['email_hash'=>$this->emailHash($email),'email_encrypted'=>Crypt::encryptString($email),
            'revision'=>(int)($old?->revision??0)+1,'verified_at'=>now(),'source'=>$source,'proof_challenge_id'=>$challenge->id]);
    }
    public function emailSummary(Account $account,Tenant $tenant): array
    {
        $row=$this->emails()->where('account_id',$account->id)->first();
        return ['email'=>$row?Crypt::decryptString($row->email_encrypted):null,'revision'=>(int)($row?->revision??0),
            'verified'=>$row!==null,'can_enroll'=>app(MobilePublicIdentityPolicy::class)->enabled($tenant,'email_enrollment')];
    }
    public function enroll(Request $request,Account $actor,Tenant $tenant): array
    {
        abort_unless(DB::connection('clubunio')->transactionLevel()>0,500);
        app(MobilePublicIdentityPolicy::class)->authorize($tenant,'email_enrollment');
        abort_if(array_diff(array_keys($request->all()),['email','current_password','request_key','email_revision']),422);
        $d=$request->validate(['email'=>'required|email:rfc|max:254','current_password'=>'required|string|max:4096','request_key'=>'required|uuid','email_revision'=>'required|integer|min:0']);
        $d['email']=mb_strtolower(trim($d['email'])); $actor=$actor->fresh();
        abort_unless($actor?->active&&!$actor->api_only,403);
        $this->rate('identity-enroll:'.$actor->id,5,60);
        if (!Hash::check($d['current_password'],$actor->password)) { throw ValidationException::withMessages(['current_password'=>'Aktuelles Passwort ungültig.']); }
        $current=$this->emails()->where('account_id',$actor->id)->first();
        abort_unless((int)($current?->revision??0)===(int)$d['email_revision'],409);
        $payload=['email'=>$d['email'],'origin'=>$request->attributes->get('mobile.public.origin'),'account_revision'=>(int)$actor->revision,
            'password_sha256'=>hash('sha256',$actor->password),'email_revision'=>(int)$d['email_revision'],'session_id'=>$request->attributes->get('mobile.session')->id];
        $fingerprint=hash_hmac('sha256',json_encode($payload,JSON_THROW_ON_ERROR),(string)config('app.key'));
        $old=$this->table()->where('tenant_id',$tenant->id)->where('purpose','enroll')->where('request_key',$d['request_key'])->first();
        if ($old) { abort_unless((int)$old->account_id===(int)$actor->id&&hash_equals($old->request_fingerprint,$fingerprint),409); return ['accepted'=>true]; }
        $this->rate('identity-email:'.$tenant->id.':enroll:'.$this->emailHash($d['email']),3,3600);
        $id=(string)Str::uuid(); $secret=bin2hex(random_bytes(32));
        $delivery=['email'=>$d['email'],'origin'=>$payload['origin'],'purpose'=>'enroll','tenant_name'=>$tenant->name,'token'=>$id.'.'.$secret,'account_names'=>[$actor->username]];
        $this->table()->insert(['id'=>$id,'tenant_id'=>$tenant->id,'account_id'=>$actor->id,'purpose'=>'enroll','request_key'=>$d['request_key'],'request_fingerprint'=>$fingerprint,
            'email_hash'=>$this->emailHash($d['email']),'secret_hash'=>hash('sha256',$secret),'payload_encrypted'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),
            'delivery_encrypted'=>Crypt::encryptString(MobileIdentityMail::canonical($delivery)),'expires_at'=>now()->addMinutes(30)->toIso8601String()]);
        DB::connection('clubunio')->afterCommit(function () use ($id) {
            if (config('clubunio-mobile.public_identity.dispatch_after_response',true)) { app()->terminating(fn ()=>$this->dispatch($id)); }
        });
        return ['accepted'=>true];
    }
    private function enrollmentCurrent(Tenant $tenant,object $challenge,array $payload): bool
    {
        $account=Account::find($challenge->account_id); $email=$this->emails()->where('account_id',$challenge->account_id)->first();
        return $account && $account->active && !$account->api_only && (int)$account->revision===$payload['account_revision']
            && hash_equals($payload['password_sha256'],hash('sha256',$account->password)) && (int)($email?->revision??0)===$payload['email_revision']
            && app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($account,$tenant)
            && DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id',$payload['session_id'])->where('account_id',$account->id)
                ->whereNull('revoked_at')->where('expires_at','>',now()->toIso8601String())->exists();
    }
    public function confirmEmail(Request $request): array
    {
        abort_if(array_diff(array_keys($request->all()),['token']),422);
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant=$request->attributes->get('mobile.public.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            [$row,$payload]=$this->proof($request,'enroll');
            $account=Account::whereKey($row->account_id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id',$payload['session_id'])->sharedLock()->firstOrFail();
            $this->emails()->where('account_id',$account->id)->lockForUpdate()->first();
            abort_unless($this->enrollmentCurrent($tenant,$row,$payload),409);
            $this->bindEmail($account,$payload['email'],$row,'authenticated_enrollment'); $this->finish($row);
            return ['confirmed'=>true,'recovery_email_verified'=>true];
        });
    }
    public function accessPending(Account $account, ?Tenant $bound = null): bool
    {
        $fresh = $account->fresh();
        if (!config('clubunio-mobile.public_identity.enabled', false) || !$fresh?->active || $fresh->api_only) { return false; }
        $ids = $this->table()->join('clubunio.tenant_accounts as membership', fn ($join) => $join->on('membership.tenant_id','mobile_identity_challenges.tenant_id')->on('membership.account_id','mobile_identity_challenges.account_id'))
            ->where('mobile_identity_challenges.account_id',$account->id)->where('purpose','register')->where('state','completed')
            ->where('membership.role','member')->where('membership.portal_enabled',true)
            ->when($bound, fn ($q) => $q->where('mobile_identity_challenges.tenant_id',$bound->id))->distinct()->limit(10)->pluck('mobile_identity_challenges.tenant_id');
        foreach (Tenant::whereIn('id',$ids)->get() as $tenant) {
            if (!app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($fresh,$tenant)) { return true; }
        }
        return false;
    }
    public function validSecret(object $row, string $token): bool
    {
        if (!preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})$/D', $token, $parts)) { return false; }
        return hash_equals($row->id, $parts[1]) && hash_equals($row->secret_hash, hash('sha256', $parts[2]));
    }
    public function deliveryCurrent(object $row): bool
    {
        if (!$row->payload_encrypted) { return false; }
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, 32, JSON_THROW_ON_ERROR);
        $tenant = Tenant::findOrFail($row->tenant_id);
        if ($row->purpose === 'register') { return !Account::whereRaw('lower(username) = ?', [$payload['email']])->exists(); }
        if ($row->purpose==='enroll') { return $this->enrollmentCurrent($tenant,$row,$payload); }
        foreach ($payload['candidates'] as $candidate) { if ($this->candidate($tenant,$candidate,$row->email_hash)) { return true; } }
        return false;
    }
    public function dispatch(string $id): void
    {
        if (!app(MobileIdentityMailGuard::class)->configured()) { return; }
        $work = DB::connection('clubunio')->transaction(function () use ($id) {
            $initial = $this->table()->where('id', $id)->first(); if (!$initial) { return null; }
            Tenant::whereKey($initial->tenant_id)->lockForUpdate()->firstOrFail();
            $row = $this->table()->where('id', $id)->where('state','pending')->where('delivery_state','held')->where('expires_at','>',now()->toIso8601String())->lockForUpdate()->first();
            if (!$row || !$row->delivery_encrypted || !$this->deliveryCurrent($row)) { return null; }
            $this->table()->where('id', $id)->update(['delivery_state' => 'sending']);
            return $row;
        });
        if (!$work) { return; }
        $state = 'attention';
        try {
            $data = json_decode(Crypt::decryptString($work->delivery_encrypted), true, 32, JSON_THROW_ON_ERROR);
            $sent = Mail::mailer(config('clubunio-mobile.public_identity.mailer', 'clubunio_identity'))->send(new MobileIdentityMail($work->id, (int) $work->tenant_id, $data));
            if ($sent !== null) { $state = 'sent'; }
        } catch (\Throwable) { /* Never log SMTP exceptions, addresses, passwords or proof secrets. */ }
        $this->table()->where('id', $id)->where('delivery_state','sending')->update(['delivery_state' => $state,'delivery_encrypted' => null]);
    }
    private function proof(Request $request, string $purpose): array
    {
        $tenant = $request->attributes->get('mobile.public.tenant');
        app(MobilePublicIdentityPolicy::class)->authorize($tenant, match($purpose) {'register'=>'registration','enroll'=>'email_enrollment',default=>'password_reset'});
        $this->rate('identity-proof:'.hash('sha256', $request->ip().'|'.$tenant->id), 20, 60);
        $d = $request->validate(['token' => 'required|string|max:110']);
        $id = explode('.', $d['token'])[0];
        abort_unless(Str::isUuid($id), 404);
        $row = $this->table()->where('tenant_id', $tenant->id)->where('id',$id)->where('purpose',$purpose)->where('state','pending')
            ->whereIn('delivery_state',['sending','sent','attention'])->where('expires_at','>',now()->toIso8601String())->lockForUpdate()->first();
        abort_unless($row && $row->payload_encrypted && $this->validSecret($row,$d['token']), 404);
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, 32, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($payload['origin'], $request->attributes->get('mobile.public.origin')), 404);
        return [$row,$payload];
    }
    private function finish(object $row, ?int $account = null): void
    {
        $this->table()->where('id',$row->id)->update(['state'=>'completed','completed_at'=>now(),'account_id'=>$account ?? $row->account_id,
            'payload_encrypted'=>null,'delivery_encrypted'=>null]);
    }
    public function activate(Request $request): array
    {
        abort_if(array_diff(array_keys($request->all()), ['token']), 422);
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant=$request->attributes->get('mobile.public.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            [$row,$payload]=$this->proof($request,'register');
            // Existing/deactivated/global accounts are never overwritten, reactivated or attached by anonymous email equality.
            abort_if(Account::whereRaw('lower(username) = ?',[$payload['email']])->exists(),409);
            $account=new Account; $account->forceFill(['username'=>$payload['email'],'display_name'=>mb_substr(trim($payload['first_name'].' '.$payload['last_name']),0,255),
                'password'=>$payload['password_hash'],'active'=>true,'api_only'=>false])->save();
            DB::connection('clubunio')->table('clubunio.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$account->id,'role'=>'member','portal_enabled'=>true]);
            // Email proof grants no member/parent/trainer links. Existing account-link administration reviews these explicitly.
            DB::connection('clubunio')->table('clubunio.object_changes')->insert(['tenant_id'=>$tenant->id,'actor_id'=>$account->id,'object_type'=>'tenant_accounts',
                'object_id'=>DB::connection('clubunio')->table('clubunio.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$account->id)->value('id'),
                'after_value'=>json_encode(['event'=>'self_registration_email_verified','role'=>'member','access'=>'pending_member_link_review'],JSON_THROW_ON_ERROR)]);
            $this->finish($row,(int)$account->id);
            $this->bindEmail($account,$payload['email'],$row,'self_registration');
            return ['confirmed'=>true,'access_pending'=>true];
        });
    }
    public function reset(Request $request): array
    {
        abort_if(array_diff(array_keys($request->all()), ['token','password','password_confirmation','username']),422);
        $request->validate(['username'=>'sometimes|nullable|string|max:255']);
        $password=$this->password($request->only(['password','password_confirmation']),true);
        return DB::connection('clubunio')->transaction(function () use ($request,$password) {
            $tenant=$request->attributes->get('mobile.public.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            [$row,$payload]=$this->proof($request,'reset');
            $name=mb_strtolower(trim((string)$request->input('username','')));
            $matches=array_values(array_filter($payload['candidates'],fn ($candidate)=>$name===''||mb_strtolower($candidate['username'])===$name));
            if (count($matches)!==1) { throw ValidationException::withMessages(['username'=>'Bitte den Benutzernamen aus deiner E-Mail eingeben.']); }
            $account=$this->candidate($tenant,$matches[0],$row->email_hash,true); abort_unless($account,409);
            $account->forceFill(['password'=>Hash::make($password),'revision'=>$account->revision+1])->save();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('account_id',$account->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
            $this->finish($row,(int)$account->id);
            return ['changed'=>true,'reauthentication_required'=>true];
        });
    }
}
