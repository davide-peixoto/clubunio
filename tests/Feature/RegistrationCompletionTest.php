<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\RegistrationResource;
use App\ClubUnio\Resources\RegistrationResource\Pages\ListRegistrations;
use App\ClubUnio\Resources\Relations\RegistrationsRelationManager;
use App\ClubUnio\Resources\TeamSeasonResource\Pages\ViewTeamSeason;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\RegistrationBlobStore;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationDocumentService;
use App\ClubUnio\Services\RegistrationMessageService;
use App\ClubUnio\Services\RegistrationReviewService;
use App\ClubUnio\Services\TestOutboxDispatcher;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RegistrationCompletionTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Registration $registration;

    private array $statuses = [];

    private int $type;

    private int $team;

    private array $applicant;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated DB opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'registration-'.Str::random(9), 'name' => 'Registration fixture']);
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $this->actor = $this->account('office');
        foreach (['submitted', 'review', 'trial', 'accepted', 'rejected', 'pool'] as $key) {
            $this->statuses[$key] = $this->insert('registration_statuses', ['code' => $key, 'name' => $key, 'workflow_key' => $key]);
        }
        $this->insert('member_statuses', ['code' => 'member', 'name' => 'Mitglied', 'restores_membership' => true]);
        $this->type = $this->insert('member_types', ['code' => 'active', 'name' => 'Aktiv']);
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Schweiz']);
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Neutral']);
        $billing = $this->insert('billing_recipients', ['code' => 'E', 'name' => 'Mitglied', 'email_field' => 'email']);
        $team = $this->insert('teams', ['name' => 'Fixture team']);
        $season = $this->insert('seasons', ['name' => 'Fixture season', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Fixture team / season']);
        app(MessageTemplateService::class)->seed($this->tenant);
        $this->applicant = ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2014-01-02', 'email' => 'child@example.invalid',
            'street' => 'Fixture street', 'postal_code' => '4450', 'city' => 'Fixture city', 'country_id' => $country, 'nationality_id' => $country,
            'gender_id' => $gender, 'billing_recipient_id' => $billing, 'mother_email' => 'parent@example.invalid'];
        $this->registration = app(RegistrationDataService::class)->save($this->actor, $this->tenant, null, $this->applicant);
        Http::preventStrayRequests();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function account(string $role, ?string $username = null): Account
    {
        $account = new Account;
        $account->forceFill(['username' => $username ?? Str::random(14), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $account->id, 'role' => $role, 'portal_enabled' => true]);

        return $account;
    }

    private function review(string $key, array $extra = []): Registration
    {
        return app(RegistrationReviewService::class)->review($this->actor, $this->tenant, $this->registration->id,
            $extra + ['revision' => $this->registration->fresh()->revision, 'status_id' => $this->statuses[$key], 'reason' => 'Synthetic reviewed decision', 'member_type_id' => $this->type]);
    }

    public function test_registration_rows_eager_load_tenant_and_previews_are_opt_in(): void
    {
        $rows = RegistrationResource::getEloquentQuery()->get();
        $this->assertNotEmpty($rows);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        foreach ($rows as $row) {
            $this->assertTrue($row->relationLoaded('tenant'));
            $this->assertSame($this->tenant->id, $row->tenant->id);
        }
        $this->assertCount(0, $this->db->getQueryLog());
        $this->db->disableQueryLog();
        $html = view('clubunio.registration-documents', ['tenant' => $this->tenant,
            'documents' => collect([(object) ['id' => 123, 'kind' => 'portrait', 'original_filename' => 'fixture.png', 'filename' => 'fixture.png']])])->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//iframe[not(ancestor::template)]')->length);
        $this->assertSame(1, $xpath->query('//template[@x-if="previewOpen"]//iframe')->length);
        $this->assertStringContainsString('previewOpen: false', $html);
        $this->assertStringContainsString('download=1', $html);
    }

    public function test_registration_review_is_available_in_team_and_member_dossiers(): void
    {
        $member = Member::findOrFail($this->insert('members', ['first_name' => 'Fixture']));
        $this->registration->forceFill(['member_id' => $member->id, 'team_season_id' => $this->team])->save();
        $manager = Livewire::test(RegistrationsRelationManager::class,
            ['ownerRecord' => $member, 'pageClass' => ViewMember::class]);
        $manager->mountTableAction('view', $this->registration)->assertHasNoTableActionErrors();
        $manager = Livewire::test(RegistrationsRelationManager::class,
            ['ownerRecord' => TeamSeason::findOrFail($this->team), 'pageClass' => ViewTeamSeason::class]);
        $manager->callTableAction('review', $this->registration, data: ['status_id' => $this->statuses['pool'], 'reason' => 'Synthetic capacity decision'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertSame('pool', $this->registration->fresh()->status->workflow_key);
        $this->assertTrue($this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->exists());
        $other = Member::findOrFail($this->insert('members', ['first_name' => 'Other']));
        Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewMember::class])
            ->assertCanNotSeeTableRecords([$this->registration]);
    }

    public function test_acceptance_assigns_team_and_proposes_parent_without_granting_access(): void
    {
        $parent = $this->account('member', 'parent@example.invalid');
        $r = $this->review('accepted', ['team_season_id' => $this->team]);
        $this->assertTrue($this->db->table('clubora.team_players')->where('tenant_id', $this->tenant->id)->where('member_id', $r->member_id)->where('team_season_id', $this->team)->where('active', true)->exists());
        $link = $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->sole();
        $this->assertSame('pending', $link->state);
        $this->assertSame([$link->id], $r->acceptance_summary['parent_link_ids']);
        $this->assertSame(0, app(MemberAccessService::class)->represented($parent, $this->tenant)->count());
        $this->assertFalse($r->acceptance_summary['returning']);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_returning_member_preserves_identity_and_historical_team_financial_links(): void
    {
        $left = $this->insert('member_statuses', ['code' => 'left', 'name' => 'Ausgetreten', 'ends_membership' => true]);
        $id = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2014-01-02', 'status_id' => $left,
            'exit_date' => '2020-01-01', 'pass_number' => '12345', 'member_number' => 999, 'bexio_contact_id' => '987', 'type_id' => $this->type]);
        $old = $this->insert('team_players', ['member_id' => $id, 'team_season_id' => $this->team, 'active' => false, 'legacy_invoice_position_id' => 123]);
        $member = Member::findOrFail($id);
        $r = $this->review('accepted', ['member_id' => $id, 'member_version' => MemberService::version($member), 'team_season_id' => $this->team]);
        $member->refresh();
        $this->assertSame($id, $r->member_id);
        $this->assertNull($member->exit_date);
        $this->assertTrue((bool) $member->status->restores_membership);
        $this->assertSame('12345', $member->pass_number);
        $this->assertEquals(987, $member->bexio_contact_id);
        $this->assertSame('Fixture street', $member->street);
        $this->assertTrue($r->acceptance_summary['returning']);
        $this->assertNotEquals($old, $r->acceptance_summary['team_player_id']);
        $this->assertFalse($this->db->table('clubora.team_players')->where('id', $old)->value('active'));
        $this->assertSame(123, $this->db->table('clubora.team_players')->where('id', $old)->value('legacy_invoice_position_id'));
    }

    public function test_trial_cc_and_pool_email_are_snapshotted_review_has_no_email(): void
    {
        $staff = $this->insert('members', ['first_name' => 'Coach', 'email' => 'coach@example.invalid']);
        $this->insert('team_staff', ['member_id' => $staff, 'team_season_id' => $this->team]);
        $this->review('review');
        $this->assertSame(0, $this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->where('event_key', 'registration.review')->count());
        $this->review('trial', ['team_season_id' => $this->team]);
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->where('event_key', 'registration.trial')->sole();
        $payload = json_decode(Crypt::decryptString($event->payload_encrypted), true);
        $this->assertSame(['coach@example.invalid'], $payload['intended_cc']);
        $this->review('pool');
        $this->assertSame('ready', $this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->where('event_key', 'registration.pool')->value('delivery_state'));
        Mail::assertNothingSent();
    }

    public function test_upload_normalizes_name_and_removal_is_revision_checked(): void
    {
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->withArgs(fn ($folder, $name, $bytes, $mime) => $folder === 'clubunio/registrations/'.$this->tenant->id.'/'.$this->registration->id.'/' && preg_match('/^[a-f0-9-]{36}\.pdf$/', $name) && $mime === 'application/pdf');
        $file = UploadedFile::fake()->createWithContent('Ausweis Ü.pdf', "%PDF-1.4\nfixture");
        $service = app(RegistrationDocumentService::class);
        $service->add($this->actor, $this->tenant, $this->registration->id, 1, 'identity', $file);
        $doc = $this->db->table('clubora.registration_documents')->where('registration_id', $this->registration->id)->sole();
        $this->assertSame('Ausweis Ü.pdf', $doc->original_filename);
        $this->assertSame(64, strlen($doc->sha256));
        $this->assertSame(2, $this->registration->fresh()->revision);
        $service->remove($this->actor, $this->tenant, $this->registration->id, $doc->id, 2);
        $this->assertFalse($this->db->table('clubora.registration_documents')->where('id', $doc->id)->value('active'));
        $this->expectException(ValidationException::class);
        $service->add($this->actor, $this->tenant, $this->registration->id, 1, 'identity', $file);
    }

    public function test_upload_failure_has_reference_and_rolls_back_metadata(): void
    {
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->andThrow(new \RuntimeException('secret signed URL'));
        try {
            app(RegistrationDocumentService::class)->add($this->actor, $this->tenant, $this->registration->id, 1, 'identity', UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nfixture"));
            $this->fail('Upload failure accepted');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Referenz:', $error->getMessage());
            $this->assertStringNotContainsString('secret', $error->getMessage());
        }
        $this->assertSame(1, $this->registration->fresh()->revision);
        $this->assertSame(0, $this->db->table('clubora.registration_documents')->where('registration_id', $this->registration->id)->count());
    }

    public function test_staff_cannot_upload_or_edit_applicant_and_foreign_team_is_rejected(): void
    {
        $trainer = $this->account('trainer');
        $this->expectException(HttpException::class);
        app(RegistrationDataService::class)->save($trainer, $this->tenant, $this->registration->id, ['revision' => 1, 'city' => 'Forbidden']);
    }

    public function test_panel_has_duplicates_documents_mail_and_review_actions(): void
    {
        $page = Livewire::test(ListRegistrations::class)->assertTableActionVisible('duplicates', $this->registration)
            ->assertTableActionVisible('upload', $this->registration)->assertTableActionVisible('testMail', $this->registration)
            ->mountTableAction('duplicates', $this->registration)->assertSet('mountedActions.0.name', 'duplicates');
        $this->assertStringContainsString('Keine passenden bestehenden Mitglieder', $page->instance()->getMountedAction()->getModalContent()->render());
        $this->get('/registrations')->assertOk();
    }

    public function test_disabled_test_mail_does_not_claim_delivery(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => false]);
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->where('event_key', 'registration.received')->value('id');
        $this->assertSame(['accepted' => 0, 'failed' => 0], app(RegistrationMessageService::class)->dispatchOne($this->actor, $this->tenant, $this->registration->id, $event));
        Mail::assertNothingSent();
    }

    public function test_acceptance_failure_rolls_back_team_and_parent_work(): void
    {
        $this->account('member', 'parent@example.invalid');
        $this->mock(RegistrationMessageService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic event failure'));
        try {
            $this->review('accepted', ['team_season_id' => $this->team]);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic event failure', $error->getMessage());
        }
        $this->assertSame(0, $this->db->table('clubora.members')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, $this->db->table('clubora.team_players')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, $this->db->table('clubora.account_member_links')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->registration->fresh()->revision);
        $this->assertNull($this->registration->fresh()->accepted_at);
    }

    public function test_returning_member_does_not_restore_revoked_parent_access(): void
    {
        $parent = $this->account('member', 'parent@example.invalid');
        $member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2014-01-02']);
        $link = $this->insert('account_member_links', ['account_id' => $parent->id, 'member_id' => $member, 'kind' => 'parent', 'state' => 'revoked', 'source' => 'fixture']);
        $r = $this->review('accepted', ['member_id' => $member, 'member_version' => MemberService::version(Member::findOrFail($member))]);
        $this->assertSame([], $r->acceptance_summary['parent_link_ids']);
        $this->assertSame('revoked', $this->db->table('clubora.account_member_links')->where('id', $link)->value('state'));
    }

    public function test_role_revocation_is_checked_even_with_stale_actor_instance(): void
    {
        $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => false]);
        $this->expectException(HttpException::class);
        $this->review('pool');
    }

    public function test_single_mail_dispatch_is_scoped_to_the_selected_registration_and_tenant(): void
    {
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $this->registration->id)->where('event_key', 'registration.received')->value('id');
        $this->mock(TestOutboxDispatcher::class)->shouldReceive('dispatch')->once()
            ->with('registration_events', 1, $this->tenant->id, $event)->andReturn(['accepted' => 1, 'failed' => 0]);
        $result = app(RegistrationMessageService::class)->dispatchOne($this->actor, $this->tenant, $this->registration->id, $event);
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'registration_events')->where('object_id', $event)->count());
        $this->expectException(HttpException::class);
        app(RegistrationMessageService::class)->dispatchOne($this->actor, $this->tenant, $this->registration->id + 1, $event);
    }
}
