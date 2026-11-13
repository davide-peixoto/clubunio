<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileIdentityChallenges, MobileIdentityMail, MobileIdentityMailGuard};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Hash, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePublicIdentityTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $owner;
    private string $origin = 'https://identity-fixture.clubunio.test';
    private $grammar;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Synthetic rollback fixtures only'); }
        $this->db=DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name='clubunio-mobile-identity-test'");
        config(['cache.default'=>'array','hashing.bcrypt.rounds'=>4,'clubunio-mobile.enabled'=>true,
            'clubunio-mobile.public_identity'=>['enabled'=>true,'registration_enabled'=>true,'password_reset_enabled'=>true,'email_enrollment_enabled'=>true,
                'membership_enabled'=>false,'mail_enabled'=>true,'mailer'=>'clubunio_identity','from'=>'no-reply@identity-fixture.test',
                'dispatch_after_response'=>false,'origins'=>[$this->origin]],
            'mail.mailers.clubunio_identity'=>['transport'=>'smtp','host'=>'smtp.fixture.invalid','port'=>465,'timeout'=>5],
            'clubunio-domains.central_hosts'=>['identity-fixture.clubunio.test']]);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant=Tenant::create(['slug'=>'qa-identity-'.bin2hex(random_bytes(6)),'name'=>'Synthetic identity club']);
        config(['clubunio-mobile.public_identity.tenant_slugs'=>[$this->tenant->slug]]);
        $this->owner=$this->account('qa-identity-owner-'.bin2hex(random_bytes(6)));
        $this->temporarySchema();
        Account::created(function ($account) {
            if (str_starts_with($account->username,'qa-new-')) {
                // TEMP FK parents mirror only the synthetic newly created account.
                $this->db->table('pg_temp.identity_fixture_accounts')->insert(['id'=>$account->id]);
                $this->db->table('pg_temp.identity_fixture_tenant_accounts')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$account->id]);
            }
        });
        \App\ClubUnio\Models\Registration::created(function ($registration) {
            if ((int)$registration->tenant_id===(int)$this->tenant->id) {
                $this->db->table('pg_temp.identity_fixture_registrations')->insert(['tenant_id'=>$this->tenant->id,'id'=>$registration->id]);
            }
        });
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) { if ($this->db->transactionLevel()) { $this->db->rollBack(); } if ($this->grammar) { $this->db->setQueryGrammar($this->grammar); } }
        parent::tearDown();
    }
    private function temporarySchema(): void
    {
        $this->db->statement('CREATE TEMP TABLE identity_fixture_tenants(id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.identity_fixture_tenants')->insert(['id'=>$this->tenant->id]);
        $this->db->statement('CREATE TEMP TABLE identity_fixture_accounts(id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.identity_fixture_accounts')->insert(['id'=>$this->owner->id]);
        $this->db->statement('CREATE TEMP TABLE identity_fixture_tenant_accounts(tenant_id bigint,account_id bigint,UNIQUE(tenant_id,account_id)) ON COMMIT DROP');
        $this->db->table('pg_temp.identity_fixture_tenant_accounts')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->owner->id]);
        $this->db->statement('CREATE TEMP TABLE identity_fixture_registrations(tenant_id bigint,id bigint,UNIQUE(tenant_id,id)) ON COMMIT DROP');
        $ddl=$this->db->pretend(fn ()=>(require database_path('clubunio/2026_11_03_000400_mobile_public_identity.php'))->up());
        $this->assertCount(1,$ddl); $sql=$ddl[0]['query'];
        foreach (['tenants','accounts','tenant_accounts','registrations'] as $table) { $sql=str_replace('REFERENCES clubora.'.$table.'(','REFERENCES pg_temp.identity_fixture_'.$table.'(',$sql); }
        foreach (['mobile_identity_challenges','mobile_account_emails','mobile_public_intake_commands'] as $table) {
            $sql=str_replace('CREATE TABLE clubora.'.$table,'CREATE TEMP TABLE '.$table,$sql);
            $sql=str_replace('clubora.'.$table,'pg_temp.'.$table,$sql);
        }
        $sql=preg_replace('/\n\);/',"\n) ON COMMIT DROP;",$sql);
        $this->assertStringNotContainsString('REFERENCES clubora.',$sql); $this->assertStringNotContainsString('ON clubora.',$sql);
        $this->db->unprepared($sql);
        $tracking=$this->db->pretend(fn ()=>(require database_path('clubunio/2026_11_10_000100_mobile_membership_tracking.php'))->up());
        $this->db->unprepared(str_replace(['clubora.mobile_identity_challenges','clubora.registrations'],
            ['pg_temp.mobile_identity_challenges','pg_temp.identity_fixture_registrations'],$tracking[0]['query']));
        $this->grammar=$this->db->getQueryGrammar();
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table,$prefix=null) {
                if (is_string($table)) { foreach (['mobile_identity_challenges','mobile_account_emails','mobile_public_intake_commands'] as $own) { $table=str_replace('clubora.'.$own,'pg_temp.'.$own,$table); } }
                return parent::wrapTable($table,$prefix);
            }
        });
    }
    private function account(string $username): Account
    {
        $account=new Account; $account->forceFill(['username'=>$username,'display_name'=>'Synthetic identity owner','password'=>bcrypt('synthetic-password'),
            'active'=>true,'api_only'=>false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$account->id,'role'=>'admin','portal_enabled'=>true]);
        return $account;
    }
    private function base(): string { return $this->origin.'/api/mobile/v1/public/identity/clubs/'.$this->tenant->id; }
    private function personal(): string { return $this->origin.'/api/mobile/v1/clubs/'.$this->tenant->id.'/identity/email'; }
    private function challenges() { return $this->db->table('clubora.mobile_identity_challenges'); }
    private function insert(string $table,array $row): int { return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id'=>$this->tenant->id]+$row); }
    private function requestRegister(string $email): object
    {
        $this->postJson($this->base().'/register',['email'=>$email,'first_name'=>'Synthetic','last_name'=>'Applicant','password'=>'synthetic-password','request_key'=>(string)Str::uuid()])
            ->assertOk()->assertJsonPath('data.accepted',true);
        return $this->challenges()->where('email_hash',app(MobileIdentityChallenges::class)->emailHash($email))->first();
    }
    private function delivered(object $row): array
    {
        $this->assertNotNull($row->delivery_encrypted,'Expected held proof mail: '.$row->purpose.'/'.$row->state.'/'.$row->delivery_state);
        $delivery=json_decode(Crypt::decryptString($row->delivery_encrypted),true,32,JSON_THROW_ON_ERROR);
        $this->challenges()->where('id',$row->id)->update(['delivery_state'=>'sent']); return $delivery;
    }
    private function login(Account $account): string
    {
        return $this->postJson($this->origin.'/api/mobile/v1/session',['username'=>$account->username,'password'=>'synthetic-password','device_name'=>'Synthetic identity'])
            ->assertOk()->json('data.token');
    }

    public function test_signed_in_parent_can_submit_a_second_child_without_creating_an_account(): void
    {
        $this->insert('tenant_settings', ['area'=>'public', 'key'=>'REGISTRATION_PUBLIC_ENABLED',
            'label'=>'Synthetic family intake', 'value_type'=>'boolean', 'encrypted_value'=>Crypt::encryptString('1'),
            'enabled'=>true, 'is_secret'=>false]);
        $this->insert('countries', ['code'=>'CH', 'iso3'=>'CHE', 'name'=>'Schweiz']);
        $gender = $this->insert('genders', ['code'=>'X', 'name'=>'Synthetic gender']);
        $billing = $this->insert('billing_recipients', ['code'=>'SELF', 'name'=>'Synthetic self', 'email_field'=>'email']);
        $this->insert('registration_statuses', ['name'=>'Submitted', 'code'=>'submitted', 'workflow_key'=>'submitted']);
        $member = $this->insert('members', ['legacy_id'=>-1, 'first_name'=>'First', 'last_name'=>'Child', 'birth_date'=>now()->subYears(8)->toDateString()]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->owner->id)->update(['role'=>'member']);
        $this->insert('account_member_links', ['account_id'=>$this->owner->id, 'member_id'=>$member, 'kind'=>'parent', 'state'=>'approved', 'source'=>'synthetic-test']);
        $url = $this->origin.'/api/mobile/v1/clubs/'.$this->tenant->id.'/membership-intake';
        $this->getJson($url)->assertUnauthorized();
        $token = $this->login($this->owner);
        $other = $this->account('qa-family-other-'.bin2hex(random_bytes(6)));
        $otherToken = $this->login($other);
        $options = $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.CreateAccount', false)->json('data');
        $claim = json_decode(Crypt::decryptString($options['claim']), true, flags:JSON_THROW_ON_ERROR);
        $claim['at'] = time() - 4;
        $input = ['claim'=>Crypt::encryptString(json_encode($claim, JSON_THROW_ON_ERROR)), 'consent'=>true, 'values'=>[
            'Vorname'=>'Second', 'Nachname'=>'Child', 'Email'=>'family@example.test', 'Ort'=>'Teststadt',
            'Geburtsdatum'=>now()->subYears(6)->toDateString(), 'Strasse'=>'Teststrasse', 'PLZ'=>'4450',
            'Land'=>'CH', 'Nationalitaet'=>'CHE', 'Geschlecht'=>$gender, 'RechnungAn'=>$billing,
        ]];
        $this->withToken($otherToken)->postJson($url, $input)->assertConflict();
        $this->withToken($token)->postJson($url, $input)->assertOk()->assertJsonPath('data.Submitted', true);
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(1, $this->db->table('clubora.mobile_registration_requests')->where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->owner->id)->where('request_key', $claim['key'])->count());
        $this->assertSame(1, $this->db->table('clubora.registrations')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubora.account_member_links')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, $this->challenges()->count());
        Mail::assertNothingSent();
    }
    public function test_new_email_proof_creates_pending_member_without_private_links_and_never_reuses_accounts(): void
    {
        $email='qa-new-'.bin2hex(random_bytes(6)).'@example.test'; $row=$this->requestRegister($email);
        $this->assertNotNull($row); $this->assertFalse(Account::where('username',$email)->exists());
        $delivery=$this->delivered($row);
        $this->get($this->base().'/activate')->assertOk()->assertSee('location.hash',false)->assertDontSee($delivery['token'],false);
        $this->get($this->base().'/activate?token=redacted')->assertBadRequest();
        $this->postJson($this->base().'/activate',['token'=>$delivery['token']])->assertOk()->assertJsonPath('data.access_pending',true);
        $new=Account::where('username',$email)->firstOrFail();
        $membership=$this->db->table('clubora.tenant_accounts')->where('account_id',$new->id)->first();
        $this->assertSame('member',$membership->role); $this->assertTrue($membership->portal_enabled);
        $this->assertSame(0,$this->db->table('clubora.account_member_links')->where('account_id',$new->id)->count());
        $this->assertSame('self_registration',$this->db->table('clubora.mobile_account_emails')->where('account_id',$new->id)->value('source'));
        $this->postJson($this->base().'/activate',['token'=>$delivery['token']])->assertNotFound();
        $this->postJson($this->origin.'/api/mobile/v1/session',['username'=>$email,'password'=>'wrong','device_name'=>'Synthetic'])->assertUnauthorized();
        $this->postJson($this->origin.'/api/mobile/v1/session',['username'=>$email,'password'=>'synthetic-password','device_name'=>'Synthetic'])
            ->assertForbidden()->assertJsonPath('error.code','access_pending');
        $this->assertSame(0,$this->db->table('clubora.mobile_sessions')->where('account_id',$new->id)->count());
        $finished=$this->challenges()->where('id',$row->id)->first(); $this->assertNull($finished->payload_encrypted); $this->assertNull($finished->delivery_encrypted);
        $count=$this->challenges()->count(); $this->requestRegister($email); $this->assertSame($count,$this->challenges()->count());
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_existing_account_requires_password_and_verified_recovery_binding_then_reset_revokes_all_sessions(): void
    {
        $email='qa-recovery-'.bin2hex(random_bytes(5)).'@example.test';
        // Even an email-shaped username is not a verified mailbox binding.
        $this->owner->forceFill(['username'=>$email])->save();
        $this->postJson($this->base().'/forgot-password',['email'=>$email,'request_key'=>(string)Str::uuid()])->assertOk();
        $this->assertSame(0,$this->challenges()->count());
        $token=$this->login($this->owner); $second=$this->login($this->owner);
        $input=['email'=>$email,'current_password'=>'wrong','email_revision'=>0,'request_key'=>(string)Str::uuid()];
        $this->withToken($token)->getJson($this->personal())->assertOk()->assertJsonPath('data.verified',false);
        $this->postJson($this->personal(),$input)->assertUnprocessable(); $this->assertSame(0,$this->challenges()->count());
        $input['current_password']='synthetic-password';
        $this->postJson($this->personal(),$input)->assertOk()->assertJsonPath('data.accepted',true);
        $row=$this->challenges()->where('purpose','enroll')->first(); $delivery=$this->delivered($row);
        $this->postJson($this->base().'/confirm-email',['token'=>$delivery['token']])->assertOk()->assertJsonPath('data.recovery_email_verified',true);
        $this->getJson($this->personal())->assertOk()->assertJsonPath('data.email',$email)->assertJsonPath('data.revision',1);
        $this->postJson($this->base().'/forgot-password',['email'=>$email,'request_key'=>(string)Str::uuid()])->assertOk();
        $reset=$this->challenges()->where('purpose','reset')->first(); $proof=$this->delivered($reset);
        $payload=['token'=>$proof['token'],'password'=>'changed-synthetic-password','password_confirmation'=>'changed-synthetic-password'];
        $this->postJson($this->base().'/reset-password',$payload)->assertOk()->assertJsonPath('data.reauthentication_required',true);
        $this->assertTrue(Hash::check('changed-synthetic-password',$this->owner->fresh()->password));
        $this->assertSame(0,$this->db->table('clubora.mobile_sessions')->where('account_id',$this->owner->id)->whereNull('revoked_at')->count());
        $this->withToken($second)->getJson($this->personal())->assertUnauthorized();
        $this->postJson($this->base().'/reset-password',$payload)->assertNotFound();
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_identity_mail_guard_is_ledger_bound_exact_and_never_redirects_secrets(): void
    {
        $row=$this->requestRegister('qa-new-'.bin2hex(random_bytes(6)).'@example.test');
        $delivery=json_decode(Crypt::decryptString($row->delivery_encrypted),true,32,JSON_THROW_ON_ERROR);
        $mail=(new MobileIdentityMail($row->id,(int)$this->tenant->id,$delivery))->mailer('clubunio_identity');
        $message=(new \Symfony\Component\Mime\Email)->from(config('clubunio-mobile.public_identity.from'))->to($delivery['email'])
            ->subject($mail->subjectLine())->text($mail->textBody())->html($mail->htmlBody());
        $message->getHeaders()->addTextHeader('X-ClubUnio-Identity',$row->id);
        $message->getHeaders()->addTextHeader('X-ClubUnio-Identity-Proof',$mail->signature());
        $event=fn ($message,$data=null)=>new \Illuminate\Mail\Events\MessageSending($message,$data??[
            'clubunio_identity_mail'=>$mail,'__laravel_mailable'=>MobileIdentityMail::class]);
        $guard=app(MobileIdentityMailGuard::class);
        $this->assertTrue($guard->configured()); $this->assertFalse($guard->handle($event(clone $message))); // held is not sending
        $this->challenges()->where('id',$row->id)->update(['delivery_state'=>'sending']);
        $this->assertNull($guard->handle($event(clone $message)));
        foreach (['recipient','cc','bcc','from','subject','html','text','proof','type'] as $change) {
            $copy=clone $message; $data=null;
            match($change) {
                'recipient'=>$copy->to('another@example.test'), 'cc'=>$copy->cc('another@example.test'), 'bcc'=>$copy->bcc('another@example.test'),
                'from'=>$copy->from('another@example.test'), 'subject'=>$copy->subject('Changed'), 'html'=>$copy->html('Changed'), 'text'=>$copy->text('Changed'),
                'proof'=>$copy->getHeaders()->remove('X-ClubUnio-Identity-Proof'),
                'type'=>$data=['clubunio_identity_mail'=>new \stdClass,'__laravel_mailable'=>MobileIdentityMail::class],
            };
            $this->assertFalse($guard->handle($event($copy,$data)),$change);
        }
        config(['clubunio-mobile.public_identity.mail_enabled'=>false]);
        $this->assertFalse($guard->handle($event(clone $message)));
        // General test mail cannot rewrite this denied identity message to admin.
        $this->assertFalse(app(\App\ClubUnio\Services\TestMailGuard::class)->handle($event($message)));
        $this->assertSame($delivery['email'],$message->getTo()[0]->getAddress());
        config(['clubunio-mobile.public_identity.mail_enabled'=>true]);
        $this->challenges()->where('id',$row->id)->update(['expires_at'=>now()->subSecond()->toIso8601String()]);
        $this->assertFalse($guard->handle($event(clone $message)));
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_membership_submission_creates_verified_status_only_access(): void
    {
        config(['clubunio-mobile.public_identity.membership_enabled'=>true,
            'clubunio-mobile.public_identity.membership_account_enabled'=>true]);
        $this->insert('tenant_settings',['area'=>'public','key'=>'REGISTRATION_PUBLIC_ENABLED','label'=>'Synthetic intake','value_type'=>'boolean',
            'encrypted_value'=>Crypt::encryptString('1'),'enabled'=>true,'is_secret'=>false]);
        $this->insert('countries',['code'=>'CH','iso3'=>'CHE','name'=>'Synthetic country']);
        $gender=$this->insert('genders',['code'=>'X','name'=>'Synthetic gender']);
        $billing=$this->insert('billing_recipients',['code'=>'SELF','name'=>'Synthetic self','email_field'=>'email']);
        $this->insert('registration_statuses',['name'=>'Submitted','code'=>'submitted','workflow_key'=>'submitted']);
        $options=$this->getJson($this->base().'/options')->assertOk()->assertJsonPath('data.intake.CreateAccount',true)->json('data.intake');
        $claim=json_decode(Crypt::decryptString($options['claim']),true); $claim['at']=time()-4;
        $email='qa-new-'.bin2hex(random_bytes(6)).'@example.test';
        $input=['claim'=>Crypt::encryptString(json_encode($claim)),'consent'=>true,'account_password'=>'synthetic-password','values'=>[
            'Vorname'=>'Synthetic','Nachname'=>'Applicant','Email'=>$email,'Ort'=>'Teststadt','Geburtsdatum'=>'2010-01-02',
            'Strasse'=>'Teststrasse','PLZ'=>'4450','Land'=>'CH','Nationalitaet'=>'CHE','Geschlecht'=>(string)$gender,'RechnungAn'=>(string)$billing]];
        $this->post($this->base().'/membership',array_replace($input,['values'=>json_encode($input['values'],JSON_THROW_ON_ERROR)]),
            ['Accept'=>'application/json'])->assertOk();
        $this->postJson($this->base().'/membership',$input)->assertOk();
        $this->assertFalse(Account::where('username',$email)->exists());
        $challenge=$this->challenges()->sole();
        $this->assertSame(1,$this->db->table('clubora.registrations')->where('id',$challenge->registration_id)->count());
        $delivery=$this->delivered($challenge);
        $this->postJson($this->base().'/activate',['token'=>$delivery['token']])->assertOk();
        $account=Account::where('username',$email)->firstOrFail();
        $token=$this->login($account);
        $this->withToken($token)->getJson($this->origin.'/api/mobile/v1/context')->assertOk()->assertJsonCount(0,'data.tenants')
            ->assertJsonPath('data.membership_applications.0.status','Submitted');
        $review=$this->insert('registration_statuses',['name'=>'In review','code'=>'review','workflow_key'=>'review']);
        $this->db->table('clubora.registrations')->where('id',$challenge->registration_id)->update(['status_id'=>$review]);
        $this->getJson($this->origin.'/api/mobile/v1/context')->assertOk()->assertJsonPath('data.membership_applications.0.status','In review');
        $this->getJson($this->origin.'/api/mobile/v1/clubs/'.$this->tenant->id.'/registrations')->assertForbidden();
        $this->withToken($this->login($this->owner))->getJson($this->origin.'/api/mobile/v1/context')->assertOk()
            ->assertJsonCount(0,'data.membership_applications');
        Mail::assertNothingSent();
    }

    public function test_public_membership_uses_real_intake_with_bounded_snapshot_and_replay_without_private_access(): void
    {
        config(['clubunio-mobile.public_identity.membership_enabled'=>true]);
        $this->insert('tenant_settings',['area'=>'public','key'=>'REGISTRATION_PUBLIC_ENABLED','label'=>'Synthetic public intake','value_type'=>'boolean',
            'encrypted_value'=>Crypt::encryptString('1'),'enabled'=>true,'is_secret'=>false]);
        $this->insert('countries',['code'=>'CH','iso3'=>'CHE','name'=>'Schweiz']);
        $gender=$this->insert('genders',['code'=>'X','name'=>'Synthetic gender']);
        $billing=$this->insert('billing_recipients',['code'=>'SELF','name'=>'Synthetic self','email_field'=>'email']);
        $this->insert('registration_statuses',['name'=>'Submitted','code'=>'submitted','workflow_key'=>'submitted']);
        $options=$this->getJson($this->base().'/options')->assertOk()->assertJsonPath('data.intake.CanUpload',false)->json('data.intake');
        $claim=json_decode(Crypt::decryptString($options['claim']),true); $claim['at']=time()-4;
        $input=['claim'=>Crypt::encryptString(json_encode($claim)),'consent'=>true,'values'=>[
            'Vorname'=>'Synthetic','Nachname'=>'Public applicant','Email'=>'synthetic@example.test','Ort'=>'Teststadt','Geburtsdatum'=>'2010-01-02',
            'Strasse'=>'Teststrasse','PLZ'=>'4450','Land'=>'CH','Nationalitaet'=>'CHE','Geschlecht'=>(string)$gender,'RechnungAn'=>(string)$billing]];
        $this->postJson($this->base().'/membership',array_replace($input,['values'=>$input['values']+['AHV'=>'not-allowed']]))->assertUnprocessable();
        $this->postJson($this->base().'/membership',$input)->assertOk()->assertExactJson(['data'=>['Submitted'=>true,'PendingAttachments'=>0]]);
        $this->postJson($this->base().'/membership',$input)->assertOk();
        $this->assertSame(1,$this->db->table('clubora.registrations')->where('tenant_id',$this->tenant->id)->count());
        $this->assertSame(1,$this->db->table('clubora.mobile_public_intake_commands')->count());
        $changed=$input; $changed['values']['Vorname']='Changed'; $this->postJson($this->base().'/membership',$changed)->assertConflict();
        $fresh=json_decode(Crypt::decryptString($this->getJson($this->base().'/options')->json('data.intake.claim')),true); $fresh['at']=time()-4;
        $input['claim']=Crypt::encryptString(json_encode($fresh));
        $this->db->table('clubora.genders')->where('id',$gender)->update(['name'=>'Changed lookup']);
        $this->postJson($this->base().'/membership',$input)->assertConflict();
        config(['clubunio-mobile.uploads_enabled'=>true,'clubunio-integrations.azure.tenant_slug'=>$this->tenant->slug,
            'clubunio-mobile.attachments.clamd_socket'=>'fixture-scanner']);
        $scanner=new class implements \App\ClubUnio\Mobile\MalwareScanner {
            public bool $clean=false; public int $calls=0;
            public function scan(string $bytes): array { $this->calls++; return ['clean'=>$this->clean,'version'=>'synthetic-scanner']; }
        };
        $this->app->instance(\App\ClubUnio\Mobile\MalwareScanner::class,$scanner);
        $fresh=json_decode(Crypt::decryptString($this->getJson($this->base().'/options')->assertJsonPath('data.intake.CanUpload',true)->json('data.intake.claim')),true); $fresh['at']=time()-4;
        $input['claim']=Crypt::encryptString(json_encode($fresh)); $input['values']=json_encode($input['values']);
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=');
        $files=['portrait'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic.png',$png),
            'identity'=>array_map(fn ($i)=>\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic-'.$i.'.pdf',"%PDF-1.4\n% synthetic fixture ".$i."\n%%EOF"),range(1,3))];
        $this->post($this->base().'/membership',$input+$files,['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertSame(1,$this->db->table('clubora.registrations')->where('tenant_id',$this->tenant->id)->count());
        $scanner->clean=true;
        $this->post($this->base().'/membership',$input+$files,['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.PendingAttachments',4);
        $this->assertSame(4,$this->db->table('clubora.document_uploads')->where('tenant_id',$this->tenant->id)->where('state','pending')->count());
        $command=$this->db->table('clubora.mobile_public_intake_commands')->where('request_key',$fresh['key'])->first();
        $evidence=json_decode(Crypt::decryptString($command->scan_evidence_encrypted),true);
        $this->assertCount(4,$evidence);
        foreach ($evidence as $entry) {
            $upload=$this->db->table('clubora.document_uploads')->where('tenant_id',$this->tenant->id)->where('sha256',$entry['sha256'])->first();
            $this->assertSame($entry['sha256'],hash('sha256',Crypt::decryptString($upload->encrypted_bytes)));
            $this->assertSame('synthetic-scanner',$entry['scanner']); $this->assertNull($upload->provider_started_at);
        }
        $calls=$scanner->calls;
        $this->post($this->base().'/membership',$input+$files,['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.PendingAttachments',4);
        $this->assertSame($calls,$scanner->calls);
        $this->getJson($this->origin.'/api/mobile/v1/clubs/'.$this->tenant->id.'/registrations')->assertUnauthorized();
        $this->assertSame(0,$this->db->table('clubora.account_member_links')->where('tenant_id',$this->tenant->id)->count());
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    private function verifyEmail(Account $account,string $email): void
    {
        $this->withToken($this->login($account))->postJson($this->personal(),['email'=>$email,'current_password'=>'synthetic-password',
            'email_revision'=>0,'request_key'=>(string)Str::uuid()])->assertOk();
        $row=$this->challenges()->where('purpose','enroll')->where('account_id',$account->id)->first();
        $delivery=$this->delivered($row);
        $this->postJson($this->base().'/confirm-email',['token'=>$delivery['token']])->assertOk();
    }
    public function test_shared_verified_mailbox_needs_exact_account_selection_and_preserves_other_account(): void
    {
        $other=$this->account('qa-new-secondary-'.bin2hex(random_bytes(5)));
        $email='qa-shared-'.bin2hex(random_bytes(5)).'@example.test';
        $this->verifyEmail($this->owner,$email); $this->verifyEmail($other,$email);
        $this->postJson($this->base().'/forgot-password',['email'=>$email,'request_key'=>(string)Str::uuid()])->assertOk();
        $row=$this->challenges()->where('purpose','reset')->first(); $delivery=$this->delivered($row);
        $this->assertCount(2,$delivery['account_names']);
        $input=['token'=>$delivery['token'],'password'=>'changed-synthetic-password','password_confirmation'=>'changed-synthetic-password'];
        $this->postJson($this->base().'/reset-password',$input)->assertUnprocessable();
        $this->postJson($this->base().'/reset-password',$input+['username'=>'unrelated'])->assertUnprocessable();
        $this->postJson($this->base().'/reset-password',$input+['username'=>$other->username])->assertOk();
        $this->assertTrue(Hash::check('synthetic-password',$this->owner->fresh()->password));
        $this->assertTrue(Hash::check('changed-synthetic-password',$other->fresh()->password));
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_revocation_expiry_host_binding_and_default_flags_close_proofs(): void
    {
        $token=$this->login($this->owner);
        $email='qa-revoked-'.bin2hex(random_bytes(5)).'@example.test';
        $this->withToken($token)->postJson($this->personal(),['email'=>$email,'current_password'=>'synthetic-password','email_revision'=>0,'request_key'=>(string)Str::uuid()])->assertOk();
        $row=$this->challenges()->where('purpose','enroll')->first(); $delivery=$this->delivered($row);
        $this->db->table('clubora.mobile_sessions')->where('account_id',$this->owner->id)->update(['revoked_at'=>now()->toIso8601String()]);
        $this->postJson($this->base().'/confirm-email',['token'=>$delivery['token']])->assertConflict();
        $this->assertSame(0,$this->db->table('clubora.mobile_account_emails')->count());
        $register=$this->requestRegister('qa-new-'.bin2hex(random_bytes(5)).'@example.test'); $proof=$this->delivered($register);
        $this->challenges()->where('id',$register->id)->update(['expires_at'=>now()->subSecond()->toIso8601String()]);
        $this->postJson($this->base().'/activate',['token'=>$proof['token']])->assertNotFound();
        $this->getJson('https://untrusted.example/api/mobile/v1/public/identity/clubs')->assertStatus(503);
        config(['clubunio-domains.hosts'=>['identity-fixture.clubunio.test'=>$this->tenant->slug]]);
        $this->getJson($this->origin.'/api/mobile/v1/public/identity/clubs')->assertOk()->assertJsonCount(1,'data.clubs');
        $this->getJson($this->origin.'/api/mobile/v1/public/identity/clubs/'.($this->tenant->id+1).'/options')->assertNotFound();
        config(['clubunio-mobile.public_identity.enabled'=>false]);
        $this->getJson($this->origin.'/api/mobile/v1/public/identity/clubs')->assertStatus(503);
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_identity_dispatch_is_explicit_at_most_once_and_erases_mail_secrets_after_uncertain_result(): void
    {
        $row=$this->requestRegister('qa-new-'.bin2hex(random_bytes(5)).'@example.test');
        $service=app(MobileIdentityChallenges::class);
        config(['clubunio-mobile.public_identity.mail_enabled'=>false]); $service->dispatch($row->id); Mail::assertNothingSent();
        config(['clubunio-mobile.public_identity.mail_enabled'=>true]); $service->dispatch($row->id); $service->dispatch($row->id);
        Mail::assertSent(MobileIdentityMail::class,1);
        $after=$this->challenges()->where('id',$row->id)->first();
        $this->assertSame('attention',$after->delivery_state); $this->assertNull($after->delivery_encrypted);
        $this->assertNotNull($after->payload_encrypted); Http::assertNothingSent();
    }
}
