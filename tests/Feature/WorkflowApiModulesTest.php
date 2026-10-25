<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\Modules\WorkflowApiModules;
use App\ClubUnio\Models\{Account, ApiIntegration, Member, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, MemberService, MessageTemplateService, PermissionCatalog, TeamPlanningService};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowApiModulesTest extends TestCase
{
    private $db;
    private WorkflowApiModules $api;
    private ApiContext $context;
    private int $profile;
    private int $member;
    private int $team;
    private int $season;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new WorkflowApiModules;
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function fixture(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rollback tests: CLUBUNIO_TEST_DB=1');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $tenant = Tenant::create(['slug' => 'workflow-api-'.Str::random(12), 'name' => 'Workflow API fixture']);
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(24), 'display_name' => 'API fixture', 'password' => bcrypt(Str::random(32)), 'active' => true, 'api_only' => true])->save();
        $this->profile = $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Workflow fixture', 'active' => true,
            'permissions' => json_encode(PermissionCatalog::keys(), JSON_THROW_ON_ERROR)]);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'profile', 'access_profile_id' => $this->profile]);
        $scopes = [];
        foreach ($this->api->definitions() as $d) {
            foreach (['read', 'create', 'update', 'copy', 'approve', 'cancel', 'send', 'deactivate', 'restore'] as $function) {
                $scopes[] = $d['permission_module'].':'.$function;
            }
        }
        $scopes = array_merge($scopes, ['members:create', 'members:update', 'members:restore', 'teams:create', 'documents:create']);
        $integration = new ApiIntegration;
        $integration->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'access_profile_id' => $this->profile,
            'name' => 'Workflow fixture', 'created_by' => $actor->id, 'active' => true, 'scopes' => array_values(array_unique($scopes))])->save();
        $token = $integration->createToken('fixture', $scopes, now()->addHour())->accessToken;
        $this->context = new ApiContext($actor, $tenant, $integration, $token);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Member', 'birth_date' => '2012-01-01']);
        $this->season = $this->insert('seasons', ['name' => 'Fixture season']);
        $team = $this->insert('teams', ['name' => 'Fixture team']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $this->season, 'label' => 'Fixture team / season']);
        TeamPlanningService::seedOptions($tenant->id);
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->context->tenant->id] + $values);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        request()->attributes->remove(BackofficeReadContext::class);
        parent::tearDown();
    }

    private function rejects(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
            $this->fail('Unsafe operation accepted.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error, $error->getMessage());
        }
    }

    private function noDelivery(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function assertIsoTimestamp(string $value): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value);
        $this->assertSame($value, \Carbon\CarbonImmutable::parse($value)->toIso8601String());
    }

    private function eventInput(array $overrides = []): array
    {
        return array_replace(['team_season_id' => $this->team, 'title' => 'API training', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
            'starts_at' => '2090-11-10T18:00', 'ends_at' => '2090-11-10T20:00', 'deadline_at' => '2090-11-09T18:00', 'capacity' => 3], $overrides);
    }

    private function plan(): array
    {
        $exercise = ['title' => 'Fixture exercise', 'durationMinutes' => 20, 'setup' => 'Fixture setup', 'flow' => 'Fixture flow',
            'coachingPoints' => ['Fixture coaching'], 'easier' => 'Less space', 'harder' => 'More pressure', 'diagramDescription' => 'Fixture diagram',
            'markers' => [['kind' => 'player', 'x' => 50, 'y' => 50]]];
        return ['title' => 'Fixture plan', 'focus' => 'Ball control', 'principles' => ['SV Sissach principles'], 'qualityChecks' => ['SFV principles'],
            'exercises' => array_map(fn ($phase) => ['phase' => $phase] + $exercise, ['Einstieg', 'Hauptteil', 'Abschluss'])];
    }

    private function acceptanceFixture(): array
    {
        foreach (['submitted', 'review', 'accepted'] as $code) {
            $this->insert('registration_statuses', ['code' => $code, 'name' => $code, 'workflow_key' => $code]);
        }
        $restored = $this->insert('member_statuses', ['code' => 'active', 'name' => 'Active member', 'restores_membership' => true]);
        $type = $this->insert('member_types', ['code' => 'fixture', 'name' => 'Fixture']);
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Swiss']);
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Neutral']);
        $billing = $this->insert('billing_recipients', ['code' => 'E', 'name' => 'Applicant', 'email_field' => 'email']);
        $parentEmail = 'parent-'.Str::lower(Str::random(12)).'@example.invalid';
        $registration = $this->api->create($this->context, 'registrations', [
            'first_name' => 'New', 'last_name' => 'Applicant', 'email' => 'child@example.invalid', 'city' => 'Fixture', 'birth_date' => '2012-01-01',
            'street' => 'Fixture street', 'postal_code' => '4450', 'country_id' => $country, 'nationality_id' => $country, 'gender_id' => $gender,
            'billing_recipient_id' => $billing, 'mother_email' => $parentEmail, 'notes' => 'PRIVATE applicant note']);
        return ['registration' => $registration, 'type' => $type, 'restored' => $restored, 'parent_email' => $parentEmail];
    }

    private function tokenScopes(array $scopes): void
    {
        $this->context->token->forceFill(['abilities' => $scopes])->save();
    }

    public function test_registration_acceptance_requires_compound_scopes_and_copies_only_local_references(): void
    {
        $this->fixture();
        $f = $this->acceptanceFixture();
        $id = $f['registration']['id'];
        $documentType = $this->insert('document_types', ['code' => 'identity', 'name' => 'Identity']);
        $document = $this->insert('registration_documents', ['registration_id' => $id, 'kind' => 'identity', 'filename' => 'identity.pdf', 'storage_path' => 'PRIVATE/']);
        $parent = new Account;
        $parent->forceFill(['username' => $f['parent_email'], 'display_name' => 'Parent fixture', 'password' => bcrypt(Str::random(32)), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $parent->id, 'role' => 'member', 'portal_enabled' => true]);
        $input = ['revision' => 1, 'reason' => 'Synthetic acceptance', 'member_type_id' => $f['type'], 'document_type_id' => $documentType, 'team_season_id' => $this->team];
        $all = ['registrations:read', 'registrations:approve', 'members:create', 'teams:create', 'documents:create'];
        foreach (['registrations:approve', 'members:create', 'teams:create', 'documents:create'] as $missing) {
            $this->tokenScopes(array_values(array_diff($all, [$missing])));
            $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input), HttpException::class);
            $this->assertNull($this->db->table('clubora.registrations')->where('id', $id)->value('member_id'));
            $this->assertSame(1, $this->db->table('clubora.members')->where('tenant_id', $this->context->tenant->id)->count());
        }
        $this->tokenScopes($all);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(array_values(array_diff(PermissionCatalog::keys(), ['documents.create'])))]);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input), HttpException::class);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(PermissionCatalog::keys())]);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input + ['status_id' => 1]));
        $accepted = $this->api->action($this->context, 'registrations', $id, 'accept', $input);
        $this->assertSame(2, $accepted['revision']);
        $this->assertNotNull($accepted['member_id']);
        $this->assertArrayNotHasKey('applicant_encrypted', $accepted);
        $this->assertArrayNotHasKey('acceptance_summary', $accepted);
        $copy = $this->db->table('clubora.member_documents')->where('tenant_id', $this->context->tenant->id)->where('registration_document_id', $document)->sole();
        $this->assertSame('PRIVATE/', $copy->storage_path);
        $this->assertEquals($accepted['member_id'], $copy->member_id);
        $this->assertTrue($this->db->table('clubora.team_players')->where('tenant_id', $this->context->tenant->id)->where('member_id', $accepted['member_id'])->where('team_season_id', $this->team)->where('active', true)->exists());
        $link = $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->where('member_id', $accepted['member_id'])->sole();
        $this->assertSame('pending', $link->state);
        $this->assertSame(0, $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->where('state', 'approved')->count());
        $this->assertTrue($this->db->table('clubora.registration_events')->where('tenant_id', $this->context->tenant->id)->where('registration_id', $id)->where('event_key', 'registration.accepted')->exists());
        $this->tokenScopes([...$all, 'members:update']);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input));
        $this->noDelivery();
    }

    public function test_registration_reentry_retains_duplicate_version_and_historical_assignment_protection(): void
    {
        $this->fixture();
        $f = $this->acceptanceFixture();
        $id = $f['registration']['id'];
        $left = $this->insert('member_statuses', ['code' => 'left', 'name' => 'Left', 'ends_membership' => true]);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['first_name' => 'New', 'last_name' => 'Applicant', 'status_id' => $left,
            'exit_date' => '2020-01-01', 'pass_number' => '12345', 'member_number' => 999, 'bexio_contact_id' => '987', 'type_id' => $f['type']]);
        $history = $this->insert('team_players', ['member_id' => $this->member, 'team_season_id' => $this->team, 'active' => false, 'legacy_invoice_position_id' => 123]);
        $input = ['revision' => 1, 'reason' => 'Synthetic returning member', 'member_type_id' => $f['type'], 'team_season_id' => $this->team];
        // A possible duplicate cannot silently become another new member.
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input));
        $this->db->table('clubora.registrations')->where('id', $id)->update(['member_id' => $this->member]);
        $this->tokenScopes(['registrations:approve', 'members:create', 'teams:create']);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input), HttpException::class);
        $this->tokenScopes(['registrations:approve', 'members:update', 'teams:create']);
        foreach ([['exit_date' => null, 'status_id' => $left], ['exit_date' => '2020-01-01', 'status_id' => $f['restored']]] as $state) {
            $this->db->table('clubora.members')->where('id', $this->member)->update($state);
            $candidate = Member::withoutGlobalScopes()->findOrFail($this->member);
            $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input + ['member_version' => MemberService::version($candidate)]), HttpException::class);
            $this->assertNull($this->db->table('clubora.registrations')->where('id', $id)->value('accepted_at'));
            $this->assertSame(1, $this->db->table('clubora.registrations')->where('id', $id)->value('revision'));
        }
        $this->db->table('clubora.members')->where('id', $this->member)->update(['exit_date' => '2020-01-01', 'status_id' => $left]);
        $this->tokenScopes(['registrations:approve', 'members:update', 'members:restore', 'teams:create']);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(array_values(array_diff(PermissionCatalog::keys(), ['members.restore'])))]);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input), HttpException::class);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(PermissionCatalog::keys())]);
        $member = Member::withoutGlobalScopes()->findOrFail($this->member);
        $version = MemberService::version($member);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['city' => 'Changed concurrently']);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input + ['member_version' => $version]));
        $member->refresh();
        $accepted = $this->api->action($this->context, 'registrations', $id, 'accept', $input + ['member_version' => MemberService::version($member)]);
        $this->assertSame($this->member, $accepted['member_id']);
        $member->refresh();
        $this->assertNull($member->exit_date);
        $this->assertEquals($f['restored'], $member->status_id);
        $this->assertSame('12345', $member->pass_number);
        $this->assertEquals('987', $member->bexio_contact_id);
        $this->assertEquals(999, $member->member_number);
        $old = $this->db->table('clubora.team_players')->where('id', $history)->sole();
        $this->assertFalse($old->active);
        $this->assertEquals(123, $old->legacy_invoice_position_id);
        $this->assertSame(1, $this->db->table('clubora.team_players')->where('tenant_id', $this->context->tenant->id)->where('member_id', $this->member)->where('active', true)->count());
        $this->noDelivery();
    }

    public function test_registration_acceptance_keeps_pending_azure_gate_and_conditional_scopes(): void
    {
        $this->fixture();
        $f = $this->acceptanceFixture();
        $id = $f['registration']['id'];
        $upload = $this->insert('document_uploads', ['kind' => 'registration', 'object_id' => $id, 'deduplication_key' => hash('sha256', 'fixture'),
            'sha256' => hash('sha256', 'fixture'), 'byte_count' => 7, 'mime_type' => 'application/pdf', 'folder' => 'PRIVATE/', 'filename' => 'fixture.pdf',
            'encrypted_metadata' => Crypt::encryptString('{}'), 'encrypted_bytes' => Crypt::encryptString('fixture'), 'created_by' => $this->context->actor->id]);
        $input = ['revision' => 1, 'reason' => 'Synthetic accepted member', 'member_type_id' => $f['type']];
        // No effective team or document copies: neither unrelated creation scope is needed.
        $this->tokenScopes(['registrations:approve', 'members:create']);
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $id, 'accept', $input));
        $this->assertNull($this->db->table('clubora.registrations')->where('id', $id)->value('accepted_at'));
        $this->assertSame(0, $this->db->table('clubora.team_players')->where('tenant_id', $this->context->tenant->id)->count());
        $this->db->table('clubora.document_uploads')->where('id', $upload)->update(['state' => 'cancelled']);
        $accepted = $this->api->action($this->context, 'registrations', $id, 'accept', $input);
        $this->assertNotNull($accepted['member_id']);
        $this->assertNull($accepted['team_season_id']);
        $this->noDelivery();
    }

    public function test_planning_participation_guests_and_invitations_use_real_profile_services(): void
    {
        $this->fixture();
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        $event = $this->api->create($this->context, 'team_events', $this->eventInput());
        $part = $this->api->action($this->context, 'team_events', $event['id'], 'participate', ['member_id' => $this->member, 'revision' => 0, 'status' => 'OFFEN', 'attendance' => 'OFFEN']);
        $this->assertSame(0, $this->db->table('clubora.account_member_links')->where('account_id', $this->context->actor->id)->count());
        $updated = $this->api->update($this->context, 'team_participations', $part['id'], ['revision' => $part['revision'], 'status' => 'DABEI', 'attendance' => 'ANWESEND', 'comment' => 'PRIVATE']);
        $this->assertSame('DABEI', $updated['status']);
        $this->assertArrayNotHasKey('comment', $updated);
        $this->rejects(fn () => $this->api->update($this->context, 'team_participations', $part['id'], ['revision' => $part['revision'], 'status' => 'ABWESEND', 'attendance' => 'OFFEN']));
        $viaEvent = $this->api->action($this->context, 'team_events', $event['id'], 'participate', ['member_id' => $this->member, 'revision' => $updated['revision'], 'status' => 'ABWESEND', 'attendance' => 'OFFEN']);
        $this->assertSame('ABWESEND', $viaEvent['status']);
        $outsider = $this->insert('members', ['first_name' => 'Invited', 'last_name' => 'Fixture']);
        $this->context->token->forceFill(['abilities' => ['planning:read', 'planning:update']])->save();
        $this->rejects(fn () => $this->api->action($this->context, 'team_events', $event['id'], 'invite', ['revision' => 1, 'member_id' => $outsider]), HttpException::class);
        $this->context->token->forceFill(['abilities' => ['planning:read', 'planning:send', 'planning:cancel', 'planning:create', 'planning:update', 'planning:deactivate']])->save();
        $this->api->action($this->context, 'team_events', $event['id'], 'invite', ['revision' => 1, 'member_id' => $outsider]);
        $this->assertSame([$outsider], $this->api->action($this->context, 'team_events', $event['id'], 'invitations')['member_ids']);
        $guestInput = ['event_id' => $event['id'], 'first_name' => 'Guest', 'last_name' => 'Fixture', 'status' => 'DABEI', 'attendance' => 'OFFEN', 'comment' => 'PRIVATE GUEST'];
        $guest = $this->api->create($this->context, 'team_guests', $guestInput);
        $this->assertArrayNotHasKey('comment_encrypted', $guest);
        $changed = $this->api->update($this->context, 'team_guests', $guest['id'], ['revision' => 1, 'first_name' => 'Guest', 'last_name' => 'Changed', 'status' => 'DABEI', 'attendance' => 'ANWESEND']);
        $this->assertSame(2, $changed['revision']);
        $this->rejects(fn () => $this->api->update($this->context, 'team_guests', $guest['id'], ['revision' => 1, 'first_name' => 'Stale', 'last_name' => 'Fixture', 'status' => 'DABEI', 'attendance' => 'OFFEN']));
        $this->api->action($this->context, 'team_guests', $guest['id'], 'deactivate', ['revision' => 2]);
        $this->assertSame([], $this->api->list($this->context, 'team_guests')['data']);
        $this->api->action($this->context, 'team_events', $event['id'], 'uninvite', ['revision' => 1, 'member_id' => $outsider]);
        $this->assertSame([], $this->api->action($this->context, 'team_events', $event['id'], 'invitations')['member_ids']);
        $this->noDelivery();
    }

    public function test_participation_deadlines_capacity_and_closed_events_remain_service_controlled(): void
    {
        $this->fixture();
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        $event = $this->api->create($this->context, 'team_events', $this->eventInput(['capacity' => 1]));
        $part = $this->api->action($this->context, 'team_events', $event['id'], 'participate', ['member_id' => $this->member, 'revision' => 0, 'status' => 'OFFEN', 'attendance' => 'OFFEN']);
        $this->api->update($this->context, 'team_participations', $part['id'], ['revision' => $part['revision'], 'status' => 'DABEI', 'attendance' => 'OFFEN']);
        $this->rejects(fn () => $this->api->create($this->context, 'team_guests', ['event_id' => $event['id'], 'first_name' => 'Over', 'last_name' => 'Capacity', 'status' => 'DABEI', 'attendance' => 'OFFEN']));
        $this->db->table('clubora.team_events')->where('id', $event['id'])->update(['deadline_at' => now()->subDay()]);
        // Existing manager rights permit a late correction; no represented-member path is manufactured.
        $late = $this->api->update($this->context, 'team_participations', $part['id'], ['revision' => 2, 'status' => 'ABWESEND', 'attendance' => 'ENTSCHULDIGT']);
        $this->assertSame(3, $late['revision']);
        $this->db->table('clubora.team_events')->where('id', $event['id'])->update(['status' => 'ABGESAGT']);
        $this->rejects(fn () => $this->api->update($this->context, 'team_participations', $part['id'], ['revision' => 3, 'status' => 'DABEI', 'attendance' => 'OFFEN']), HttpException::class);
        $this->noDelivery();
    }

    public function test_series_creation_preview_and_fresh_fingerprint_apply_skip_exceptions(): void
    {
        $this->fixture();
        $event = $this->api->create($this->context, 'team_events', $this->eventInput(['repeat_count' => 3, 'description' => 'PRIVATE DESCRIPTION']));
        $rows = $this->api->list($this->context, 'team_events', ['filters' => ['series_key' => $event['series_key']]])['data'];
        $this->assertCount(3, $rows);
        $this->db->table('clubora.team_events')->where('id', $rows[2]['id'])->update(['series_exception' => true]);
        $input = ['revision' => 1, 'from' => '2090-11-01', 'shift_minutes' => 30, 'change_title' => true, 'title' => 'Shifted training'];
        $preview = $this->api->action($this->context, 'team_events', $event['id'], 'series_preview', $input);
        $this->assertCount(2, $preview['changes']);
        $this->assertSame(1, $preview['skipped']);
        $this->assertStringNotContainsString('PRIVATE DESCRIPTION', json_encode($preview));
        $this->db->table('clubora.team_events')->where('id', $rows[1]['id'])->increment('revision');
        $this->rejects(fn () => $this->api->action($this->context, 'team_events', $event['id'], 'series_apply', $input + ['fingerprint' => $preview['fingerprint']]));
        $this->assertSame('API training', $this->api->show($this->context, 'team_events', $event['id'])['title']);
        $fresh = $this->api->action($this->context, 'team_events', $event['id'], 'series_preview', $input);
        $applied = $this->api->action($this->context, 'team_events', $event['id'], 'series_apply', $input + ['fingerprint' => $fresh['fingerprint']]);
        $this->assertSame(2, $applied['applied']);
        $this->assertSame('API training', $this->api->show($this->context, 'team_events', $rows[2]['id'])['title']);
        $this->assertSame('2090-11-10T18:30', \Carbon\CarbonImmutable::parse($this->api->show($this->context, 'team_events', $event['id'])['starts_at'])->tz('Europe/Zurich')->format('Y-m-d\TH:i'));
        $this->context->token->forceFill(['abilities' => ['planning:read', 'planning:update']])->save();
        $this->rejects(fn () => $this->api->action($this->context, 'team_events', $event['id'], 'series_preview', array_replace($input, ['revision' => 2, 'cancel' => true])), HttpException::class);
        $this->rejects(fn () => $this->api->action($this->context, 'team_events', $event['id'], 'series_preview', $input + ['changes' => [['tenant_id' => 1]]]));
        $this->noDelivery();
    }

    public function test_training_plan_content_update_archive_restore_and_private_copy_without_ai(): void
    {
        $this->fixture();
        $plan = $this->plan();
        $input = ['team_season_id' => $this->team, 'durationMinutes' => 60, 'playerCount' => 12, 'goal' => 'Ball control', 'fieldSize' => 'Half field', 'material' => 'Balls'];
        $id = $this->insert('training_plans', ['owner_id' => $this->context->actor->id, 'team_season_id' => $this->team, 'title' => $plan['title'],
            'input_json' => json_encode($input + ['private_key' => 'PRIVATE']), 'plan_json' => json_encode($plan + ['provider_secret' => 'PRIVATE'])]);
        $content = $this->api->action($this->context, 'training_plans', $id, 'content');
        $this->assertArrayNotHasKey('provider_secret', $content['plan']);
        $this->assertArrayNotHasKey('private_key', $content['input']);
        $this->assertSame($plan, $content['plan']);
        $markerExtra = $plan;
        $markerExtra['exercises'][0]['markers'][0]['url'] = 'https://example.invalid/PRIVATE';
        $exerciseExtra = $plan;
        $exerciseExtra['exercises'][0]['private_payload'] = ['secret' => 'PRIVATE'];
        foreach ([$markerExtra, $exerciseExtra, $plan + ['provider_key' => 'PRIVATE']] as $nested) {
            $this->rejects(fn () => $this->api->update($this->context, 'training_plans', $id, ['revision' => 1, 'plan' => $nested, 'shared' => false]));
        }
        $this->context->token->forceFill(['abilities' => ['training:read', 'training:update']])->save();
        $this->rejects(fn () => $this->api->update($this->context, 'training_plans', $id, ['revision' => 1, 'plan' => $plan, 'shared' => true]), HttpException::class);
        $this->rejects(fn () => $this->api->action($this->context, 'training_plans', $id, 'copy', ['revision' => 1, 'request_key' => (string) Str::uuid()]), HttpException::class);
        $saved = $this->api->update($this->context, 'training_plans', $id, ['revision' => 1, 'plan' => $plan, 'shared' => false]);
        $this->assertSame(2, $saved['revision']);
        $this->rejects(fn () => $this->api->update($this->context, 'training_plans', $id, ['revision' => 1, 'plan' => $plan, 'shared' => false]));
        $this->context->token->forceFill(['abilities' => ['training:read', 'training:update', 'training:send', 'training:deactivate', 'training:restore', 'training:copy']])->save();
        $shared = $this->api->update($this->context, 'training_plans', $id, ['revision' => 2, 'plan' => $plan, 'shared' => true]);
        $copyInput = ['revision' => $shared['revision'], 'request_key' => (string) Str::uuid()];
        $copy = $this->api->action($this->context, 'training_plans', $id, 'copy', $copyInput);
        $this->assertFalse($copy['shared']);
        $this->assertSame($copy['id'], $this->api->action($this->context, 'training_plans', $id, 'copy', $copyInput)['id']);
        $archived = $this->api->action($this->context, 'training_plans', $id, 'archive', ['revision' => 3]);
        $this->assertNotNull($archived['archived_at']);
        $this->assertIsoTimestamp($archived['archived_at']);
        $this->rejects(fn () => $this->api->update($this->context, 'training_plans', $id, ['revision' => 4, 'plan' => $plan, 'shared' => true]), HttpException::class);
        $restored = $this->api->action($this->context, 'training_plans', $id, 'restore', ['revision' => 4]);
        $this->assertNull($restored['archived_at']);
        $this->assertSame(5, $restored['revision']);
        $this->assertSame(0, $this->db->table('clubora.training_plan_requests')->where('tenant_id', $this->context->tenant->id)->whereIn('kind', ['generate', 'review'])->count());
        $this->rejects(fn () => $this->api->action($this->context, 'training_plans', $id, 'generate'), HttpException::class);
        $this->noDelivery();
    }

    public function test_metadata_is_explicit_and_has_documentable_action_fields(): void
    {
        foreach ($this->api->definitions() as $key => $d) {
            $this->assertSame($key, $d['key']);
            foreach (['create', 'update'] as $operation) {
                $this->assertSame([], array_diff($d[$operation.'_required'], $d[$operation.'_fields']));
            }
            $this->assertSame([], array_diff($d['search_fields'], $d['read_fields']));
            $this->assertSame([], array_diff($d['filter_fields'], $d['read_fields']));
            foreach (['tenant_id', 'legacy_id', 'applicant_encrypted', 'note_encrypted', 'comment_encrypted', 'original_comment_encrypted', 'storage_reference', 'preview_token', 'bank_account_id', 'notes', 'decision_note', 'reason'] as $private) {
                $this->assertNotContains($private, $d['read_fields'], $key);
            }
            foreach ($d['actions'] as $action) {
                $this->assertIsString($action['function']);
                $this->assertIsArray($action['fields']);
                $this->assertSame([], array_diff($action['required'], $action['fields']));
                $this->assertSame($action['required'], $action['schema']['required']);
                $this->assertFalse($action['schema']['additionalProperties']);
            }
        }
        $this->assertArrayHasKey('external_actions', $this->api->unsupported());
        $this->assertArrayNotHasKey('paid', $this->api->definitions()['expenses']['actions']);
        $this->assertArrayNotHasKey('publish', $this->api->definitions()['announcements']['actions']);
    }

    public function test_every_query_executes_with_only_allowlisted_columns(): void
    {
        $this->fixture();
        foreach ($this->api->definitions() as $key => $d) {
            $rows = $this->api->query($this->context, $key)->get();
            foreach ($rows as $row) {
                $attributes = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->getAttributes() : (array) $row;
                $this->assertSame([], array_diff(array_keys($attributes), $d['read_fields']));
            }
            $this->assertArrayHasKey('meta', $this->api->list($this->context, $key));
        }
    }

    public function test_workflow_dropdowns_are_column_validated_tenant_scoped_and_read_only(): void
    {
        $this->fixture();
        $other = Tenant::create(['slug' => 'lookup-other-'.Str::random(10), 'name' => 'Other lookup tenant']);
        $tables = ['planning_options' => ['planning_options', 'planning', ['category' => 'kind', 'code' => 'API_FIXTURE']],
            'bus_reservation_statuses' => ['bus_statuses', 'bus', ['code' => 'ANGEFRAGT']],
            'material_receipt_statuses' => ['material_receipt_statuses', 'documents', ['code' => 'draft']],
            'announcement_statuses' => ['announcement_statuses', 'announcements', ['code' => 'draft']],
            'sponsor_run_statuses' => ['sponsor_run_statuses', 'sponsor_runs', ['code' => 'draft']]];
        foreach ($tables as $resource => [$table, $module, $values]) {
            $id = $this->insert($table, $values + ['name' => 'Fixture lookup', 'active' => true]);
            $foreign = $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $other->id] + $values + ['name' => 'Foreign lookup', 'active' => true]);
            $this->tokenScopes([$module.':read']);
            $row = $this->api->show($this->context, $resource, $id);
            $this->assertSame($values['code'], $row['code']);
            $this->assertSame($this->api->definitions()[$resource]['read_fields'], array_keys($row));
            $this->assertSame([$id], array_column($this->api->list($this->context, $resource, ['filters' => ['code' => $values['code']]])['data'], 'id'));
            $this->api->query($this->context, $resource)->get();
            $this->rejects(fn () => $this->api->show($this->context, $resource, $foreign), \Illuminate\Database\RecordNotFoundException::class);
            $this->rejects(fn () => $this->api->create($this->context, $resource, ['name' => 'Denied']), HttpException::class);
            $this->rejects(fn () => $this->api->update($this->context, $resource, $id, ['name' => 'Denied']), HttpException::class);
            $this->tokenScopes([$module === 'planning' ? 'bus:read' : 'planning:read']);
            $this->rejects(fn () => $this->api->list($this->context, $resource), HttpException::class);
            $this->assertSame('Fixture lookup', $this->db->table('clubora.'.$table)->where('id', $id)->value('name'));
        }
        $this->noDelivery();
    }

    public function test_families_are_service_written_versioned_and_tenant_isolated(): void
    {
        $this->fixture();
        $family = $this->api->create($this->context, 'families', ['name' => 'API family', 'active' => true]);
        $input = ['name' => 'Updated family', 'active' => true, '_version' => $family['_version']];
        $updated = $this->api->update($this->context, 'families', $family['id'], $input);
        $this->assertNotSame($family['_version'], $updated['_version']);
        $this->rejects(fn () => $this->api->update($this->context, 'families', $family['id'], $input));
        $link = $this->api->create($this->context, 'family_members', ['family_id' => $family['id'], 'member_id' => $this->member, 'discount' => '5.00', 'active' => true, 'notes' => 'PRIVATE']);
        $this->assertArrayNotHasKey('notes', $link);
        $other = Tenant::create(['slug' => 'workflow-other-'.Str::random(8), 'name' => 'Other']);
        $foreign = $this->db->table('clubora.families')->insertGetId(['tenant_id' => $other->id, 'name' => 'Foreign']);
        $this->rejects(fn () => $this->api->show($this->context, 'families', $foreign), \Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->rejects(fn () => $this->api->update($this->context, 'families', $foreign, $input), \Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->assertSame($family['id'], $this->api->list($this->context, 'families')['data'][0]['id']);
        $this->noDelivery();
    }

    public function test_planning_events_and_absences_preserve_versions_and_hide_private_sources(): void
    {
        $this->fixture();
        $input = ['team_season_id' => $this->team, 'title' => 'API training', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
            'starts_at' => '2090-11-10T18:00', 'ends_at' => '2090-11-10T20:00', 'deadline_at' => '2090-11-09T18:00'];
        $event = $this->api->create($this->context, 'team_events', $input);
        foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
            $this->assertIsoTimestamp($event[$field]);
        }
        $this->assertSame('2090-11-10T18:00', \Carbon\CarbonImmutable::parse($event['starts_at'])->setTimezone('Europe/Zurich')->format('Y-m-d\TH:i'));
        $this->assertMatchesRegularExpression('/[+-]\d\d:?\d\d$/', $event['starts_at']);
        $updated = $this->api->update($this->context, 'team_events', $event['id'], $input + ['revision' => 1]);
        $this->assertSame(2, $updated['revision']);
        $this->rejects(fn () => $this->api->update($this->context, 'team_events', $event['id'], $input + ['revision' => 1]));
        $absence = $this->api->create($this->context, 'team_absences', ['member_id' => $this->member, 'starts_on' => '2090-11-09', 'ends_on' => '2090-11-11', 'kind' => 'KRANK', 'note' => 'PRIVATE HEALTH']);
        $this->assertArrayNotHasKey('kind', $absence);
        $this->assertArrayNotHasKey('note_encrypted', $absence);
        $this->insert('team_participations', ['event_id' => $event['id'], 'member_id' => $this->member, 'status' => 'ABWESEND', 'source' => 'absence',
            'comment_encrypted' => Crypt::encryptString('PRIVATE HEALTH'), 'original_comment_encrypted' => Crypt::encryptString('PRIVATE ORIGINAL')]);
        $participation = $this->api->list($this->context, 'team_participations')['data'][0];
        $this->assertSame(['id', 'event_id', 'member_id', 'status', 'attendance', 'revision'], array_keys($participation));
        $this->api->action($this->context, 'team_absences', $absence['id'], 'deactivate', ['revision' => 1]);
        $this->assertSame([], $this->api->list($this->context, 'team_absences')['data']);
        $this->noDelivery();
    }

    public function test_internal_invoice_header_lines_release_and_stale_revision(): void
    {
        $this->fixture();
        foreach (['draft', 'released', 'cancelled'] as $code) {
            $this->insert('invoice_workflow_statuses', ['code' => $code, 'name' => $code]);
        }
        $this->insert('invoice_line_statuses', ['code' => 'P', 'name' => 'Pending']);
        $category = $this->insert('invoice_categories', ['code' => 'fixture', 'name' => 'Fixture']);
        $invoice = $this->api->create($this->context, 'invoices', ['member_id' => $this->member, 'season_id' => $this->season, 'invoice_category_id' => $category, 'tax_mode' => 'exempt', 'notes' => 'PRIVATE']);
        $this->assertArrayNotHasKey('notes', $invoice);
        $lineInput = ['revision' => $invoice['revision'], 'description' => 'Internal fixture', 'gross_amount' => '25.00', 'discount' => '2.00'];
        $next = $this->api->action($this->context, 'invoices', $invoice['id'], 'add_line', $lineInput);
        $this->rejects(fn () => $this->api->action($this->context, 'invoices', $invoice['id'], 'add_line', $lineInput));
        $line = $this->api->list($this->context, 'invoice_lines', ['filters' => ['invoice_id' => $invoice['id']]])['data'][0];
        $this->assertSame('23.0000', $line['net_amount']);
        $released = $this->api->action($this->context, 'invoices', $invoice['id'], 'release', ['revision' => $next['revision']]);
        $this->assertGreaterThan($next['revision'], $released['revision']);
        $this->rejects(fn () => $this->api->action($this->context, 'invoices', $invoice['id'], 'send', []), HttpException::class);
        $this->noDelivery();
    }

    public function test_expense_header_lines_and_local_transitions_never_assert_payment(): void
    {
        $this->fixture();
        foreach (['O', 'F', 'Z', 'R', 'B'] as $code) {
            $this->insert('expense_statuses', ['code' => $code, 'name' => $code]);
        }
        $bank = $this->insert('member_bank_accounts', ['member_id' => $this->member, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $round = $this->insert('expense_rounds', ['name' => 'Fixture', 'code' => 'fixture']);
        $type = $this->insert('expense_types', ['name' => 'Fixture', 'receipt_required' => false, 'amount_source' => 'team_laundry']);
        $teamId = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('team_id');
        $this->db->table('clubora.teams')->where('id', $teamId)->update(['laundry_allowance' => '20.00']);
        $expense = $this->api->create($this->context, 'expenses', ['member_id' => $this->member, 'team_season_id' => $this->team, 'bank_account_id' => $bank, 'round_id' => $round, 'notes' => 'PRIVATE']);
        $this->assertArrayNotHasKey('bank_account_id', $expense);
        $line = $this->api->create($this->context, 'expense_lines', ['expense_id' => $expense['id'], 'expense_revision' => 1, 'expense_type_id' => $type, 'amount' => '15.00', 'notes' => 'PRIVATE']);
        $this->assertSame(1, $line['revision']);
        $this->assertSame('20.0000', $line['suggested_amount']);
        $this->assertFalse($line['suggestion_accepted']);
        $read = $this->api->show($this->context, 'expense_lines', $line['id']);
        $this->assertSame('20.0000', $read['suggested_amount']);
        $this->assertFalse($read['suggestion_accepted']);
        foreach (['suggested_amount' => '15.0000', 'suggestion_accepted' => true] as $field => $value) {
            $this->rejects(fn () => $this->api->create($this->context, 'expense_lines', ['expense_id' => $expense['id'], 'expense_revision' => 2, 'expense_type_id' => $type, 'amount' => '15.00', $field => $value]));
            $this->rejects(fn () => $this->api->update($this->context, 'expense_lines', $line['id'], ['revision' => 1, 'expense_revision' => 2, $field => $value]));
        }
        $this->rejects(fn () => $this->api->update($this->context, 'expense_lines', $line['id'], ['revision' => 1, 'expense_revision' => 1, 'amount' => '20.00']));
        $this->context->token->forceFill(['abilities' => ['expenses:read', 'expenses:update']])->save();
        $this->rejects(fn () => $this->api->action($this->context, 'expenses', $expense['id'], 'submit', ['revision' => 2, 'reason' => 'Synthetic submit']), HttpException::class);
        $this->context->token->forceFill(['abilities' => ['expenses:read', 'expenses:send', 'expenses:approve']])->save();
        $submitted = $this->api->action($this->context, 'expenses', $expense['id'], 'submit', ['revision' => 2, 'reason' => 'Synthetic submit']);
        $approved = $this->api->action($this->context, 'expenses', $expense['id'], 'approve', ['revision' => $submitted['revision'], 'reason' => 'Synthetic approve']);
        $this->assertSame(4, $approved['revision']);
        $this->assertNull($this->db->table('clubora.expenses')->where('id', $expense['id'])->value('paid_on'));
        $this->rejects(fn () => $this->api->action($this->context, 'expenses', $expense['id'], 'paid', ['revision' => 4]), HttpException::class);
        $this->noDelivery();
    }

    public function test_bus_request_reschedule_decision_and_conflict_use_services(): void
    {
        $this->fixture();
        foreach (['ANGEFRAGT', 'GENEHMIGT', 'STORNIERT', 'ABGELEHNT'] as $code) {
            $this->insert('bus_statuses', ['code' => $code, 'name' => $code]);
        }
        $bus = $this->insert('buses', ['name' => 'Fixture bus']);
        $input = ['bus_id' => $bus, 'member_id' => $this->member, 'team_season_id' => $this->team, 'status' => 'ANGEFRAGT', 'starts_at' => '2090-11-10T10:00', 'ends_at' => '2090-11-10T12:00'];
        $reservation = $this->api->create($this->context, 'bus_reservations', $input);
        $this->assertIsoTimestamp($reservation['starts_at']);
        $this->assertIsoTimestamp($reservation['ends_at']);
        $this->assertSame($input['starts_at'], \Carbon\CarbonImmutable::parse($reservation['starts_at'])->tz('Europe/Zurich')->format('Y-m-d\TH:i'));
        $this->rejects(fn () => $this->api->create($this->context, 'bus_reservations', $input));
        $changed = $this->api->update($this->context, 'bus_reservations', $reservation['id'], ['revision' => 1, 'bus_id' => $bus, 'starts_at' => '2090-11-10T13:00', 'ends_at' => '2090-11-10T15:00', 'reason' => 'PRIVATE change']);
        $this->assertSame(2, $changed['revision']);
        $this->rejects(fn () => $this->api->action($this->context, 'bus_reservations', $reservation['id'], 'approve', ['revision' => 1]));
        $approved = $this->api->action($this->context, 'bus_reservations', $reservation['id'], 'approve', ['revision' => 2, 'note' => 'PRIVATE decision']);
        $this->assertSame('GENEHMIGT', $approved['status']);
        $this->assertArrayNotHasKey('decision_note', $approved);
        $this->noDelivery();
    }

    public function test_registrations_save_and_review_without_blob_or_acceptance_exposure(): void
    {
        $this->fixture();
        $statuses = [];
        foreach (['submitted', 'review', 'accepted'] as $code) {
            $statuses[$code] = $this->insert('registration_statuses', ['code' => $code, 'name' => $code, 'workflow_key' => $code]);
        }
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Swiss']);
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Neutral']);
        $billing = $this->insert('billing_recipients', ['code' => 'E', 'name' => 'Applicant', 'email_field' => 'email']);
        $input = ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'email' => 'fixture@example.invalid', 'city' => 'Fixture', 'birth_date' => '2012-01-01',
            'street' => 'Fixture', 'postal_code' => '4450', 'country_id' => $country, 'nationality_id' => $country, 'gender_id' => $gender, 'billing_recipient_id' => $billing, 'notes' => 'PRIVATE'];
        $r = $this->api->create($this->context, 'registrations', $input);
        $this->assertSame('2012-01-01', $r['birth_date']);
        $this->assertArrayNotHasKey('applicant_encrypted', $r);
        $review = $this->api->action($this->context, 'registrations', $r['id'], 'review', ['revision' => 1, 'status_id' => $statuses['review'], 'reason' => 'Synthetic review reason']);
        $this->assertSame(2, $review['revision']);
        $this->rejects(fn () => $this->api->update($this->context, 'registrations', $r['id'], ['revision' => 1, 'city' => 'Stale']));
        $this->rejects(fn () => $this->api->action($this->context, 'registrations', $r['id'], 'review', ['revision' => 2, 'status_id' => $statuses['accepted'], 'reason' => 'Synthetic acceptance']), HttpException::class);
        $this->assertSame(2, $this->api->show($this->context, 'registrations', $r['id'])['revision']);
        $this->noDelivery();
    }

    public function test_templates_and_announcements_remain_local_drafts(): void
    {
        $this->fixture();
        app(MessageTemplateService::class)->seed($this->context->tenant);
        $template = $this->api->list($this->context, 'message_templates')['data'][0];
        $updated = $this->api->update($this->context, 'message_templates', $template['id'], ['revision' => 1, 'title' => 'Fixture title', 'body' => 'Fixture body', 'active' => true]);
        $this->assertSame(2, $updated['revision']);
        $this->rejects(fn () => $this->api->update($this->context, 'message_templates', $template['id'], ['revision' => 1, 'title' => 'Stale', 'body' => 'Fixture', 'active' => true]));
        $this->insert('announcement_statuses', ['code' => 'draft', 'name' => 'Draft']);
        $announcement = $this->api->create($this->context, 'announcements', ['title' => 'Fixture', 'body' => 'Fixture body', 'target_type' => 'all', 'target_ids' => []]);
        $this->assertSame('draft', $announcement['state']);
        $this->rejects(fn () => $this->api->action($this->context, 'announcements', $announcement['id'], 'publish'), HttpException::class);
        $this->noDelivery();
    }

    public function test_contribution_preview_and_internal_draft_require_both_module_scopes(): void
    {
        $this->fixture();
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Neutral']);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['gender_id' => $gender]);
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        $price = $this->insert('contribution_prices', ['name' => 'Fixture tariff', 'amount' => '50.0000']);
        $this->insert('contribution_rules', ['season_id' => $this->season, 'price_id' => $price, 'gender_id' => $gender, 'year_from' => 2012, 'year_to' => 2012]);
        $this->insert('invoice_workflow_statuses', ['name' => 'Draft', 'code' => 'draft']);
        $this->insert('invoice_line_statuses', ['name' => 'Pending', 'code' => 'P']);
        $this->insert('invoice_categories', ['name' => 'Contribution', 'code' => 'contribution']);
        $preview = $this->api->action($this->context, 'contributions', $this->member, 'preview', ['season_id' => $this->season]);
        $this->assertSame(5000, $preview['net_cents']);
        $this->assertArrayNotHasKey('discount_reason', $preview);
        $this->assertArrayNotHasKey('bexio_product_id', $preview);
        $input = ['member_id' => $this->member, 'season_id' => $this->season, 'fingerprint' => $preview['fingerprint']];
        $this->context->token->forceFill(['abilities' => ['contributions:read', 'contributions:create']])->save();
        $this->rejects(fn () => $this->api->create($this->context, 'contributions', $input), HttpException::class);
        $this->context->token->forceFill(['abilities' => ['contributions:read', 'contributions:create', 'invoices:create']])->save();
        $this->rejects(fn () => $this->api->create($this->context, 'contributions', array_replace($input, ['fingerprint' => str_repeat('0', 64)])));
        $draft = $this->api->create($this->context, 'contributions', $input);
        $this->assertSame($this->member, $draft['member_id']);
        $this->assertSame($draft['id'], $this->api->create($this->context, 'contributions', $input)['id']);
        $this->noDelivery();
    }

    public function test_unknown_json_fields_and_unbounded_queries_are_rejected(): void
    {
        $this->fixture();
        foreach (['tenant_id', 'legacy_id', 'id', 'api_only', 'storage_reference', 'preview_token'] as $field) {
            $this->rejects(fn () => $this->api->create($this->context, 'families', ['name' => 'Denied', 'active' => true, $field => 1]));
        }
        foreach ([['per_page' => 101], ['filters' => ['tenant_id' => 1]], ['filters' => ['active' => ['op' => '!=']]], ['include' => 'bankAccounts']] as $input) {
            $this->rejects(fn () => $this->api->list($this->context, 'families', $input));
        }
        $this->rejects(fn () => $this->api->action($this->context, 'invoices', 1, 'add_line', ['revision' => 1, 'line_id' => 4]));
    }

    public function test_fresh_profile_revocation_roles_and_secondary_scopes(): void
    {
        $this->fixture();
        $family = $this->api->create($this->context, 'families', ['name' => 'Fixture', 'active' => true]);
        $this->context->token->forceFill(['abilities' => ['families:read', 'families:update']])->save();
        $this->rejects(fn () => $this->api->update($this->context, 'families', $family['id'], ['name' => 'Fixture', 'active' => false, '_version' => $family['_version']]), HttpException::class);
        foreach (['admin', 'office', 'trainer', 'member', 'technical_lead'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->context->actor->id)->update(['role' => $role, 'access_profile_id' => null]);
            $this->rejects(fn () => $this->api->list($this->context, 'families'), HttpException::class);
        }
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->context->actor->id)->update(['role' => 'profile', 'access_profile_id' => $this->profile]);
        request()->attributes->set(BackofficeReadContext::class, ['account' => $this->context->actor->id, 'tenants' => collect([$this->context->tenant]), 'permissionCache' => []]);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => '[]']);
        $this->rejects(fn () => $this->api->list($this->context, 'families'), HttpException::class);
        $this->assertNotNull(request()->attributes->get(BackofficeReadContext::class));
    }
}
