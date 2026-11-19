<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

class MobileRegistrationsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private array $statuses = [];
    private array $values;
    private int $memberType;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Synthetic transaction opt-in required'); }
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_registrations_enabled' => true,
            'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->db = DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name TO 'clubunio-mobile-registrations-test'");
        $this->tenant = Tenant::create(['slug' => 'mobile-intake-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic intake']);
        $this->actor = new Account; $this->actor->forceFill(['username' => 'mobile-intake-'.bin2hex(random_bytes(8)),
            'display_name' => 'Synthetic reviewer', 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin', 'portal_enabled' => true]);
        foreach (['submitted', 'review', 'trial', 'accepted', 'rejected', 'pool'] as $key) {
            $this->statuses[$key] = $this->insert('registration_statuses', ['code' => $key, 'name' => $key, 'workflow_key' => $key]);
        }
        $this->insert('member_statuses', ['code' => 'active', 'name' => 'Synthetic member', 'restores_membership' => true]);
        $this->memberType = $this->insert('member_types', ['code' => 'active', 'name' => 'Synthetic active']);
        $country = $this->insert('countries', ['code' => 'CH', 'iso3' => 'CHE', 'name' => 'Synthetic country']);
        $gender = $this->insert('genders', ['code' => 'X', 'name' => 'Synthetic gender']);
        $billing = $this->insert('billing_recipients', ['code' => 'SELF', 'name' => 'Synthetic recipient', 'email_field' => 'email']);
        $this->values = ['Vorname' => 'Synthetic', 'Nachname' => 'Applicant', 'Geburtsdatum' => '2014-01-02',
            'Email' => 'intake-'.bin2hex(random_bytes(6)).'@example.invalid', 'Strasse' => 'Synthetic street', 'PLZ' => '4000', 'Ort' => 'Synthetic city',
            'Land' => 'CH', 'Nationalitaet' => 'CHE', 'Geschlecht' => (string) $gender, 'RechnungAn' => (string) $billing];
        $this->temporarySchema();
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $this->actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic intake'])->assertOk()->json('data.token');
        $this->withToken($token);
    }
    protected function tearDown(): void { if (isset($this->db)) { $this->db->rollBack(); } parent::tearDown(); }
    private function insert(string $table, array $values): int { return $this->db->table('clubunio.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values); }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/registrations'; }
    private function create(array $changes = []) {
        return $this->postJson($this->base(), array_replace(['RequestKey' => '9d00f34f-e433-438d-a124-a9dfbf1fd371', 'Values' => $this->values], $changes));
    }
    private function temporarySchema(): void
    {
        foreach (['tenants' => 'id bigint PRIMARY KEY', 'tenant_accounts' => 'tenant_id bigint,account_id bigint,UNIQUE(tenant_id,account_id)',
            'registrations' => 'tenant_id bigint,id bigint,UNIQUE(tenant_id,id)'] as $table => $definition) {
            $this->db->statement('CREATE TEMP TABLE intake_test_'.$table.'('.$definition.') ON COMMIT DROP');
        }
        $this->db->table('pg_temp.intake_test_tenants')->insert(['id' => $this->tenant->id]);
        $this->db->table('pg_temp.intake_test_tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id]);
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000300_mobile_registration_requests.php'))->up());
        $this->assertCount(1, $ddl);
        $sql = str_replace(['CREATE TABLE clubunio.mobile_registration_requests', ");\nREVOKE", 'ON clubunio.mobile_registration_requests'],
            ['CREATE TEMP TABLE mobile_registration_requests', ") ON COMMIT DROP;\nREVOKE", 'ON pg_temp.mobile_registration_requests'], $ddl[0]['query']);
        foreach (['tenants', 'tenant_accounts', 'registrations'] as $table) { $sql = str_replace('REFERENCES clubunio.'.$table.'(', 'REFERENCES pg_temp.intake_test_'.$table.'(', $sql); }
        $this->assertStringNotContainsString('REFERENCES clubunio.', $sql);
        $this->db->unprepared($sql);
        // Only mirror newly created synthetic source IDs into TEMP FK parents; no source DDL.
        $this->db->unprepared(<<<'SQL'
CREATE FUNCTION pg_temp.intake_test_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO pg_temp.intake_test_registrations SELECT tenant_id,id FROM clubunio.registrations
 WHERE tenant_id=NEW.tenant_id AND id=NEW.registration_id ON CONFLICT DO NOTHING;
 RETURN NEW;
END $$;
CREATE TRIGGER intake_test_source BEFORE INSERT ON pg_temp.mobile_registration_requests FOR EACH ROW EXECUTE FUNCTION pg_temp.intake_test_source();
SQL);
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) { return parent::wrapTable($table === 'clubunio.mobile_registration_requests' ? 'pg_temp.mobile_registration_requests' : $table, $prefix); }
        });
    }
    public function test_personal_intake_is_persistent_idempotent_versioned_and_uses_real_services(): void
    {
        $created = $this->create()->assertOk()->assertJsonPath('data.success', true)->assertJsonPath('data.Registration.WorkflowKey', 'submitted')
            ->assertJsonPath('data.Registration.Revision', 1)->assertJsonPath('data.Registration.Nationalitaet', 'CHE')->assertJsonMissingPath('data.Registration.AHVNummer');
        $id = $created->json('data.Registration.AnmeldungID');
        $this->create()->assertOk()->assertJsonPath('data.Registration.AnmeldungID', $id);
        $this->create(['Values' => array_replace($this->values, ['Ort' => 'Changed city'])])->assertConflict();
        $this->assertSame(1, $this->db->table('clubunio.registrations')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubunio.mobile_registration_requests')->count());
        $this->getJson($this->base().'?q=Applicant')->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonMissingPath('data.Registrations.0.Email');
        $this->getJson($this->base().'/options?registration='.$id)->assertOk()->assertJsonCount(6, 'data.Statuses');
        $this->postJson($this->base().'/'.$id.'/intake', ['Revision' => 1, 'Values' => ['Ort' => 'Updated synthetic city']])->assertOk()->assertJsonPath('data.Registration.Revision', 2);
        $this->postJson($this->base().'/'.$id.'/intake', ['Revision' => 1, 'Values' => ['Ort' => 'Stale city']])->assertUnprocessable();
        $this->postJson($this->base().'/'.$id.'/intake', ['Revision' => 2, 'Values' => ['AHVNummer' => 'not permitted']])->assertUnprocessable();
        $this->assertSame('Updated synthetic city', $this->db->table('clubunio.registrations')->where('id', $id)->value('city'));
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_review_acceptance_rejection_and_revocation_keep_existing_business_rules(): void
    {
        $id = $this->create()->assertOk()->json('data.Registration.AnmeldungID');
        $decision = ['revision' => 1, 'status_id' => $this->statuses['trial'], 'reason' => 'Synthetic review reason'];
        $this->postJson($this->base().'/'.$id.'/review', $decision)->assertUnprocessable(); // A trial needs a real team.
        $this->postJson($this->base().'/'.$id.'/review', array_replace($decision, ['status_id' => $this->statuses['accepted'], 'member_type_id' => $this->memberType]))
            ->assertOk()->assertJsonPath('data.Registration.WorkflowKey', 'accepted')->assertJsonPath('data.Registration.Revision', 2)
            ->assertJsonPath('data.Registration.CanEdit', false)->assertJsonPath('data.Registration.CanReview', false);
        $this->assertSame(1, $this->db->table('clubunio.members')->where('tenant_id', $this->tenant->id)->count());
        $this->postJson($this->base().'/'.$id.'/review', array_replace($decision, ['revision' => 2, 'status_id' => $this->statuses['rejected']]))->assertUnprocessable();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'member', 'portal_enabled' => false]);
        $this->getJson($this->base().'/'.$id)->assertForbidden();
        $this->postJson($this->base().'/'.$id.'/review', $decision)->assertForbidden();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'admin']);
        config(['clubunio-mobile.native_registrations_enabled' => false]);
        $this->getJson($this->base())->assertStatus(503);
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_failed_transfer_keeps_protected_pending_upload_then_retries_downloads_and_removes_with_displayed_revision(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true, 'clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $bytes = "%PDF-1.4\nsynthetic upload - no real document";
        $attempts = 0; $reads = 0;
        $this->mock(\App\ClubUnio\Services\RegistrationBlobStore::class)->shouldReceive('put')->times(3)->andReturnUsing(function () use (&$attempts) {
            if (++$attempts === 1) { throw new \RuntimeException('synthetic unavailable provider'); }
        });
        $this->mock(\App\ClubUnio\Services\AzureDocumentReader::class)->shouldReceive('read')->andReturnUsing(function () use (&$reads, $bytes) {
            if (++$reads === 1) { throw new \RuntimeException('synthetic missing uploaded object', 404); }
            return $bytes;
        });
        $id = $this->create()->assertOk()->json('data.Registration.AnmeldungID');
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic.pdf', $bytes);
        $this->post($this->base().'/'.$id.'/files', ['Revision' => 1, 'Kind' => 'portrait', 'file' => $file()], ['Accept' => 'application/json'])->assertUnprocessable();
        $pending = $this->post($this->base().'/'.$id.'/files', ['Revision' => 1, 'Kind' => 'identity', 'file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.Registration.Revision', 1)->assertJsonPath('data.Registration.PendingAttachments', 1);
        $upload = $pending->json('data.Registration.PendingUploads.0.id');
        $protected = $this->db->table('clubunio.document_uploads')->where('id', $upload)->first();
        $this->assertSame('pending', $protected->state); $this->assertNotEmpty($protected->encrypted_bytes);
        $ready = $this->postJson($this->base().'/'.$id.'/uploads/'.$upload.'/retry', ['Revision' => 1])
            ->assertOk()->assertJsonPath('data.Registration.Revision', 2)->assertJsonPath('data.Registration.PendingAttachments', 0);
        $document = $ready->json('data.Registration.AusweisUpload.0.id');
        $this->get($this->base().'/'.$id.'/files/'.$document)->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $bytes));
        $this->postJson($this->base().'/'.$id.'/files/'.$document.'/remove', ['Revision' => 1])->assertUnprocessable();
        $this->postJson($this->base().'/'.$id.'/files/'.$document.'/remove', ['Revision' => 2])->assertOk()->assertJsonPath('data.Registration.Revision', 3);
        $this->getJson($this->base().'/'.$id.'/files/'.$document)->assertNotFound();
        $replaced = $this->post($this->base().'/'.$id.'/files', ['Revision' => 3, 'Kind' => 'identity', 'file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.Registration.Revision', 4);
        $this->assertNotSame($document, $replaced->json('data.Registration.AusweisUpload.0.id'));
        $this->assertFalse($this->db->table('clubunio.registration_documents')->where('id', $document)->value('active'));
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_existing_member_selection_keeps_scope_and_requires_the_displayed_member_version(): void
    {
        $member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'city' => 'Synthetic city', 'birth_date' => '2014-01-02']);
        $id = $this->create()->assertOk()->json('data.Registration.AnmeldungID');
        $candidate = $this->getJson($this->base().'/'.$id.'/members?q=Applicant')->assertOk()->json('data.Members.0');
        $this->assertSame($member, $candidate['id']);
        $this->assertEqualsCanonicalizing(['id', 'label', 'city', 'version'], array_keys($candidate));
        $input = ['revision' => 1, 'status_id' => $this->statuses['accepted'], 'reason' => 'Synthetic existing member review',
            'member_type_id' => $this->memberType, 'member_id' => $member, 'member_version' => $candidate['version']];
        $this->db->table('clubunio.members')->where('id', $member)->update(['city' => 'Updated separately']);
        $this->postJson($this->base().'/'.$id.'/review', $input)->assertUnprocessable();
        $this->assertSame(1, $this->db->table('clubunio.registrations')->where('id', $id)->value('revision'));
        $fresh = $this->getJson($this->base().'/'.$id.'/members?q=Applicant')->assertOk()->json('data.Members.0');
        $this->postJson($this->base().'/'.$id.'/review', array_replace($input, ['member_version' => $fresh['version']]))
            ->assertOk()->assertJsonPath('data.Registration.MitgliederID', $member)->assertJsonPath('data.Registration.WorkflowKey', 'accepted');
        $this->assertSame(1, $this->db->table('clubunio.members')->where('tenant_id', $this->tenant->id)->count());
    }
    public function test_technical_lead_reviews_only_assigned_registrations_without_applicant_or_attachment_privilege(): void
    {
        $id = $this->create()->assertOk()->json('data.Registration.AnmeldungID');
        $other = $this->create(['RequestKey' => '98f14211-0660-4029-8ea1-792050713c0b'])->assertOk()->json('data.Registration.AnmeldungID');
        $leader = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Lead']);
        $this->db->table('clubunio.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id,
            'member_id' => $leader, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('clubunio.registrations')->where('id', $id)->update(['technical_lead_member_id' => $leader]);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'technical_lead']);
        $this->getJson($this->base())->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.CanCreate', false);
        $this->getJson($this->base().'/'.$id)->assertOk()->assertJsonPath('data.Registration.CanEdit', false)
            ->assertJsonPath('data.Registration.CanReview', true)->assertJsonPath('data.Registration.CanAssignLeader', false);
        $this->getJson($this->base().'/'.$other)->assertNotFound();
        $this->getJson($this->base().'/'.$id.'/members')->assertForbidden();
        $this->postJson($this->base().'/'.$id.'/intake', ['Revision' => 1, 'Values' => ['Ort' => 'Not allowed']])->assertForbidden();
        $this->postJson($this->base().'/'.$id.'/review', ['revision' => 1, 'status_id' => $this->statuses['rejected'], 'reason' => 'Synthetic lead decision'])
            ->assertOk()->assertJsonPath('data.Registration.WorkflowKey', 'rejected');
        $this->db->table('clubunio.account_member_links')->where('account_id', $this->actor->id)->update(['state' => 'pending']);
        $this->getJson($this->base().'/'.$id)->assertForbidden();
    }
}
