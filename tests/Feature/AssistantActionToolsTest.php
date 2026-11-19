<?php

namespace Tests\Feature;

use App\ClubUnio\Assistant\AssistantActionTools;
use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\{Account, Member, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, ImpersonationService, MessageTemplateService};
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Opt-in PostgreSQL fixtures only: no migrations, delivery or persistent writes. */
final class AssistantActionToolsTest extends TestCase
{
    private ?Connection $db = null;
    private AssistantActionTools $tools;
    private Tenant $tenant;
    private Account $actor;
    private int $member;
    private int $team;
    private int $season;
    private array $statuses = [];
    private array $pairs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['clubunio-mobile-push.delivery_enabled' => false]);
        $this->tools = app(AssistantActionTools::class);
        $this->pairs = json_decode(file_get_contents(base_path('deploy/assistant-action-translations.json')), true, flags: JSON_THROW_ON_ERROR);
        // Main owns the catalog merge. Exercise both requested catalogs without editing them.
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            app('translator')->addLines(array_map(fn ($pair) => $pair[$locale], $this->pairs), $locale);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->disableQueryLog();
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        parent::tearDown();
    }

    private function fixtures(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to synthetic PostgreSQL rollback fixtures.');
        }
        $this->db = DB::connection('clubunio');
        self::assertSame('pgsql', $this->db->getDriverName());
        self::assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name TO 'clubunio-assistant-action-test'");
        $this->tenant = Tenant::create(['slug' => 'assistant-action-'.bin2hex(random_bytes(8)), 'name' => 'Synthetic action fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'assistant-'.bin2hex(random_bytes(8)).'@example.invalid',
            'display_name' => 'Synthetic assistant admin', 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin', 'portal_enabled' => true]);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Contact', 'email' => 'before@example.invalid', 'city' => 'Before']);
        $this->season = $this->insert('seasons', ['name' => 'Synthetic current', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
        $this->team = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic team']),
            'season_id' => $this->season, 'label' => 'Synthetic team / season']);
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubunio.'.$table)->insertGetId($values + ['tenant_id' => $this->tenant->id]);
    }

    private function table(string $table)
    {
        return $this->db->table('clubunio.'.$table)->where('tenant_id', $this->tenant->id);
    }

    private function contact(array $extra = []): array
    {
        return $extra + ['id' => $this->member, 'changes' => [['field' => 'city', 'value' => ' After '], ['field' => 'email', 'value' => 'after@example.invalid']]];
    }

    private function plan(string $name, array $arguments): array
    {
        $logging = $this->db->logging();
        $offset = count($this->db->getQueryLog());
        $this->db->enableQueryLog();
        $plan = $this->tools->prepare($this->actor, $this->tenant, $name, $arguments);
        self::assertSame($name, $plan['operation']);
        self::assertSame($plan['revision'], $plan['arguments']['_revision']);
        self::assertSame($plan, $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
        self::assertStringNotContainsString('assistant.action.', json_encode($plan['preview'], JSON_THROW_ON_ERROR));
        foreach (array_slice($this->db->getQueryLog(), $offset) as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|with|show)\b/i', $query['query']);
            self::assertDoesNotMatchRegularExpression('/\bfor (share|update)\b/i', $query['query']);
        }
        if (! $logging) {
            $this->db->disableQueryLog();
        }

        return $plan;
    }

    private function execute(array $plan): array
    {
        $logging = $this->db->logging();
        $offset = count($this->db->getQueryLog());
        $this->db->enableQueryLog();
        try {
            $result = $this->tools->execute($this->actor, $this->tenant, $plan['operation'], $plan['arguments']);
            $tables = match ($plan['operation']) {
                'prepare_registration_review' => ['registration_statuses'],
                'prepare_bus_approve', 'prepare_bus_reject' => ['bus_statuses'],
                'prepare_expense_status' => ['expense_statuses'],
                'prepare_pitch_approve', 'prepare_pitch_reject' => ['training_options', 'team_seasons'],
                default => [],
            };
            $queries = array_column(array_slice($this->db->getQueryLog(), $offset), 'query');
            foreach ($tables as $table) {
                self::assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, '"clubunio"."'.$table.'"') && str_contains($sql, 'for share')),
                    'Semantic dependency must stay locked through domain service execution: '.$table);
            }
            return $result;
        } finally {
            if (! $logging) {
                $this->db->disableQueryLog();
            }
        }
    }

    private function invalid(\Closure $action, ?string $field = null): void
    {
        try {
            $action();
            self::fail('Invalid operation was accepted.');
        } catch (ValidationException $error) {
            self::assertNotEmpty($error->errors());
            if ($field !== null) {
                self::assertArrayHasKey($field, $error->errors());
            }
        }
    }

    private function denied(\Closure $action): void
    {
        try {
            $action();
            self::fail('Unauthorized operation was accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(403, $error->getStatusCode());
        }
    }

    public function test_definitions_are_closed_strict_prepare_only_and_translated(): void
    {
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            app()->setLocale($locale);
            $definitions = $this->tools->definitions();
            self::assertCount(7, $definitions);
            foreach ($definitions as $definition) {
                self::assertSame('function', $definition['type']);
                self::assertTrue($definition['strict']);
                self::assertStringStartsWith('prepare_', $definition['name']);
                self::assertSame($this->pairs['assistant.action.'.$definition['name']][$locale], $definition['description']);
                $this->assertClosed($definition['parameters']);
                self::assertArrayNotHasKey('_revision', $definition['parameters']['properties']);
            }
        }
        foreach ($this->pairs as $pair) {
            self::assertSame(['de', 'en'], array_keys($pair));
            self::assertNotEmpty($pair['de']);
            self::assertNotEmpty($pair['en']);
        }
    }

    private function assertClosed(array $schema): void
    {
        if ($schema['type'] === 'object') {
            self::assertFalse($schema['additionalProperties']);
            self::assertSame(array_keys($schema['properties']), $schema['required']);
            foreach ($schema['properties'] as $property) {
                $this->assertClosed($property);
            }
        } elseif ($schema['type'] === 'array') {
            $this->assertClosed($schema['items']);
        }
    }

    public function test_unknown_operations_and_missing_snapshots_never_touch_database(): void
    {
        DB::shouldReceive('connection')->never();
        foreach (['delete_member', 'prepare_payment', 'prepare_account_role', 'prepare_bexio', 'member_contact'] as $name) {
            $this->invalid(fn () => $this->tools->prepare(new Account, new Tenant, $name, []), 'operation');
            $this->invalid(fn () => $this->tools->execute(new Account, new Tenant, $name, []), 'operation');
        }
        foreach ([null, '', 12, [], str_repeat('x', 64)] as $revision) {
            $this->invalid(fn () => $this->tools->execute(new Account, new Tenant, 'prepare_member_contact', ['_revision' => $revision]), '_revision');
        }
    }

    public function test_member_prepare_is_read_only_stable_normalized_and_execute_uses_domain_audit(): void
    {
        $this->fixtures();
        $recipient = $this->insert('billing_recipients', ['code' => 'SELF', 'name' => 'Self', 'email_field' => 'email']);
        $status = $this->insert('member_statuses', ['code' => 'ACTIVE', 'name' => 'Active']);
        $this->table('members')->where('id', $this->member)->update(['billing_recipient_id' => $recipient, 'status_id' => $status]);
        $this->db->enableQueryLog();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        foreach ($this->db->getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|with|show)\b/i', $query['query']);
        }
        $this->db->disableQueryLog();
        self::assertSame(0, $this->table('object_changes')->count());
        self::assertSame('Before', $this->table('members')->where('id', $this->member)->value('city'));
        self::assertSame('After', $plan['arguments']['changes'][0]['value']);
        self::assertStringContainsString('Synthetic Contact', $plan['preview']['changes'][1]['before']);
        self::assertSame($plan['preview']['changes'][1]['before'], $plan['preview']['changes'][1]['after']);
        self::assertSame('executed', $this->execute($plan)['status']);
        foreach (['billing_recipients', 'member_statuses'] as $table) {
            self::assertNotEmpty(array_filter($this->db->getQueryLog(), fn ($query) =>
                str_contains($query['query'], '"clubunio"."'.$table.'"') && str_contains($query['query'], 'for share')));
        }
        self::assertSame('After', $this->table('members')->where('id', $this->member)->value('city'));
        self::assertSame(1, $this->table('object_changes')->where('object_type', 'members')->where('actor_id', $this->actor->id)->count());
        self::assertSame(0, $this->table('notifications')->count());
        $this->invalid(fn () => $this->execute($plan));
        self::assertSame(1, $this->table('object_changes')->count());
    }

    public function test_member_revision_binds_untouched_columns_arguments_and_actor(): void
    {
        $this->fixtures();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        $tampered = $plan;
        $tampered['arguments']['changes'][0]['value'] = 'Unapproved';
        $this->invalid(fn () => $this->execute($tampered), '_revision');
        $this->table('members')->where('id', $this->member)->update(['phone_business' => 'Changed elsewhere']);
        $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']), '_revision');
        $this->invalid(fn () => $this->execute($plan), '_revision');
        self::assertSame('Before', $this->table('members')->where('id', $this->member)->value('city'));
        self::assertSame(0, $this->table('object_changes')->count());
    }

    public function test_member_allowlist_json_types_and_required_billing_email_are_enforced(): void
    {
        $this->fixtures();
        foreach (['iban', 'bexio_contact_id', 'billing_recipient_id', 'free_membership', 'password', 'role', 'website', 'status_id', 'first_name'] as $field) {
            $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, 'prepare_member_contact',
                ['id' => $this->member, 'changes' => [['field' => $field, 'value' => 'not allowed']]]));
        }
        foreach ([['url' => 'https://example.invalid'], ['id' => (string) $this->member], ['id' => true],
            ['changes' => []], ['changes' => [['field' => 'city', 'value' => 'A', 'extra' => true]]],
            ['changes' => [['field' => 'email', 'value' => 'invalid']]],
            ['changes' => [['field' => 'city', 'value' => 'A'], ['field' => 'city', 'value' => 'B']]]] as $input) {
            $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, 'prepare_member_contact', $this->contact($input)));
        }
        $recipient = $this->insert('billing_recipients', ['code' => 'SELF', 'name' => 'Self', 'email_field' => 'email']);
        $this->table('members')->where('id', $this->member)->update(['billing_recipient_id' => $recipient]);
        $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, 'prepare_member_contact',
            $this->contact(['changes' => [['field' => 'email', 'value' => null]]])), 'changes');
        self::assertSame(0, $this->table('object_changes')->count());
    }

    public function test_rights_are_fresh_in_prepare_and_execute_despite_get_snapshots(): void
    {
        $this->fixtures();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        request()->attributes->set(BackofficeReadContext::class, ['account' => $this->actor->id,
            'permissionCache' => [], 'tenants' => collect([$this->tenant])]);
        request()->attributes->set(MobileReadContext::class, ['stale' => true]);
        $profile = $this->insert('access_profiles', ['name' => 'Synthetic all member rights',
            'permissions' => json_encode(['members.view', 'members.update'], JSON_THROW_ON_ERROR)]);
        foreach (['member', 'trainer', 'technical_lead', 'profile'] as $role) {
            $this->table('tenant_accounts')->where('account_id', $this->actor->id)->update([
                'role' => $role, 'access_profile_id' => $role === 'profile' ? $profile : null, 'portal_enabled' => $role !== 'profile',
            ]);
            $this->denied(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
            $this->denied(fn () => $this->execute($plan));
            self::assertSame(['stale' => true], request()->attributes->get(MobileReadContext::class));
        }
        $this->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'office', 'access_profile_id' => null, 'portal_enabled' => true]);
        foreach ([['active' => false], ['active' => true, 'api_only' => true]] as $change) {
            $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update($change);
            $this->denied(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
            $this->denied(fn () => $this->execute($plan));
        }
        $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update(['active' => true, 'api_only' => false]);
        self::assertSame('executed', $this->execute($plan)['status']);
    }

    public function test_missing_tenant_and_removed_membership_deny_standalone_adapter(): void
    {
        $this->fixtures();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        $missing = new Tenant;
        $missing->forceFill(['id' => -1]);
        $this->denied(fn () => $this->tools->prepare($this->actor, $missing, $plan['operation'], $plan['arguments']));
        $this->table('tenant_accounts')->where('account_id', $this->actor->id)->delete();
        $this->denied(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
        $this->denied(fn () => $this->execute($plan));
        self::assertSame(0, $this->table('object_changes')->count());
    }

    public function test_fresh_context_removes_new_frames_and_restores_original_frames_even_on_failure(): void
    {
        $method = new \ReflectionMethod($this->tools, 'freshContext');
        foreach ([null, ['original' => true]] as $original) {
            foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
                request()->attributes->set($key, $original);
            }
            try {
                $method->invoke($this->tools, function () {
                    foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
                        self::assertFalse(request()->attributes->has($key));
                        request()->attributes->set($key, ['created' => true]);
                    }
                    throw new \RuntimeException('Synthetic failure');
                });
                self::fail('Exception swallowed.');
            } catch (\RuntimeException $error) {
                self::assertSame('Synthetic failure', $error->getMessage());
            }
            foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
                self::assertSame($original, request()->attributes->get($key));
                self::assertSame($original !== null, request()->attributes->has($key));
            }
        }
    }

    public function test_impersonation_attribute_and_session_deny_both_entrypoints(): void
    {
        $this->fixtures();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => $this->tenant->id, 'target_id' => $this->actor->id]);
        $this->denied(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
        $this->denied(fn () => $this->execute($plan));
        request()->attributes->remove('clubunio_impersonation');
        request()->setLaravelSession(app('session')->driver());
        request()->session()->put(ImpersonationService::KEY, 'synthetic-support-session');
        $this->denied(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], $plan['arguments']));
        $this->denied(fn () => $this->execute($plan));
        self::assertSame(0, $this->table('object_changes')->count());
    }

    public function test_other_tenant_and_archived_member_are_not_accessible(): void
    {
        $this->fixtures();
        $plan = $this->plan('prepare_member_contact', $this->contact());
        $other = Tenant::create(['slug' => 'assistant-other-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic other']);
        $this->denied(fn () => $this->tools->prepare($this->actor, $other, $plan['operation'], $plan['arguments']));
        $this->denied(fn () => $this->tools->execute($this->actor, $other, $plan['operation'], $plan['arguments']));
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        try {
            $this->tools->prepare($this->actor, $other, $plan['operation'], $plan['arguments']);
            self::fail('Cross-tenant row found.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::addToAssertionCount(1);
        }
        $this->table('members')->where('id', $this->member)->update(['archived_at' => now()]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->execute($plan);
    }

    private function registration(): int
    {
        foreach (['submitted', 'review', 'trial', 'pool', 'rejected', 'accepted'] as $status) {
            $this->statuses[$status] = $this->insert('registration_statuses', ['code' => $status, 'name' => $status, 'workflow_key' => $status]);
        }
        return $this->insert('registrations', ['first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'email' => 'applicant@example.invalid', 'status_id' => $this->statuses['submitted']]);
    }

    public function test_registration_uses_domain_review_events_and_rejects_acceptance_or_stale_team(): void
    {
        $this->fixtures();
        $id = $this->registration();
        app(MessageTemplateService::class)->seed($this->tenant);
        $arguments = ['id' => $id, 'status' => 'trial', 'team_season_id' => $this->team, 'reason' => 'Synthetic reviewed decision'];
        $plan = $this->plan('prepare_registration_review', $arguments);
        self::assertSame('Synthetic Applicant', $plan['preview']['changes'][1]['before']);
        self::assertSame('Synthetic Applicant', $plan['preview']['changes'][1]['after']);
        self::assertSame(0, $this->table('registration_events')->count());
        $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], array_replace($arguments, ['status' => 'accepted'])));
        $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], array_replace($arguments, ['team_season_id' => null])));
        self::assertSame('executed', $this->execute($plan)['status']);
        $row = $this->table('registrations')->find($id);
        self::assertSame($this->statuses['trial'], $row->status_id);
        self::assertSame($this->team, $row->team_season_id);
        self::assertSame(2, $row->revision);
        self::assertSame(2, $this->table('registration_events')->count());
        self::assertSame(1, $this->table('object_changes')->where('object_type', 'registrations')->count());
        self::assertSame(1, $this->table('members')->count());
        $this->invalid(fn () => $this->execute($plan));
        $assignment = $this->plan('prepare_registration_review', array_replace($arguments, ['status' => 'review']));
        $this->table('registrations')->where('id', $id)->update(['revision' => 3]);
        $this->invalid(fn () => $this->execute($assignment), '_revision');
    }

    private function bus(): int
    {
        foreach (['ANGEFRAGT', 'GENEHMIGT', 'ABGELEHNT'] as $status) {
            $this->insert('bus_statuses', ['code' => $status, 'name' => $status]);
        }
        return $this->insert('bus_reservations', ['bus_id' => $this->insert('buses', ['name' => 'Synthetic bus']),
            'member_id' => $this->member, 'team_season_id' => $this->team, 'status' => 'ANGEFRAGT',
            'starts_at' => now()->addDays(10)->toIso8601String(), 'ends_at' => now()->addDays(10)->addHour()->toIso8601String()]);
    }

    public function test_bus_approve_and_reject_delegate_and_recheck_conflicts(): void
    {
        $this->fixtures();
        $id = $this->bus();
        $plan = $this->plan('prepare_bus_approve', ['id' => $id, 'note' => 'Synthetic approval']);
        $row = $this->table('bus_reservations')->find($id);
        $conflict = $this->insert('bus_reservations', ['bus_id' => $row->bus_id, 'member_id' => $this->member,
            'team_season_id' => $this->team, 'status' => 'ANGEFRAGT', 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at]);
        $this->invalid(fn () => $this->execute($plan), 'id');
        self::assertSame(0, $this->table('object_changes')->count());
        $reject = $this->plan('prepare_bus_reject', ['id' => $conflict, 'note' => null]);
        self::assertSame('executed', $this->execute($reject)['status']);
        self::assertSame('ABGELEHNT', $this->table('bus_reservations')->where('id', $conflict)->value('status'));
        self::assertSame('executed', $this->execute($plan)['status']);
        self::assertSame('GENEHMIGT', $this->table('bus_reservations')->where('id', $id)->value('status'));
        self::assertSame(2, $this->table('object_changes')->where('object_type', 'bus_reservations')->count());
        $this->invalid(fn () => $this->execute($plan));
    }

    private function pitch(): int
    {
        $field = $this->insert('training_options', ['category' => 'field', 'code' => 'SYNTHETIC', 'name' => 'Synthetic pitch']);
        $status = $this->insert('facility_statuses', ['code' => 'SYNTHETIC', 'name' => 'Open', 'blocks_training' => false]);
        $this->insert('field_availabilities', ['field_id' => $field, 'status_id' => $status]);
        return $this->insert('pitch_requests', ['requester_account_id' => $this->actor->id, 'team_season_id' => $this->team,
            'field_id' => $field, 'area_id' => null, 'date' => now()->addDays(10)->toDateString(), 'starts_at' => '17:00',
            'duration_minutes' => 90, 'opponent' => 'Synthetic opponent', 'status' => 'pending']);
    }

    public function test_pitch_decisions_keep_audit_notifications_and_do_not_override_restrictions(): void
    {
        $this->fixtures();
        $id = $this->pitch();
        app(MessageTemplateService::class)->seed($this->tenant);
        $approve = $this->plan('prepare_pitch_approve', ['id' => $id]);
        self::assertSame(0, $this->table('pitch_planning_events')->count());
        self::assertSame(0, $this->table('notifications')->count());
        $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, $approve['operation'], ['id' => $id, 'override_field_status' => true]));
        $this->table('facility_statuses')->update(['blocks_training' => true]);
        $this->invalid(fn () => $this->execute($approve), 'id');
        $this->table('facility_statuses')->update(['blocks_training' => false]);
        self::assertSame('executed', $this->execute($approve)['status']);
        self::assertSame('approved', $this->table('pitch_requests')->where('id', $id)->value('status'));
        self::assertSame(1, $this->table('pitch_planning_events')->count());
        self::assertSame(1, $this->table('object_changes')->where('object_type', 'pitch_planning')->count());
        self::assertSame(1, $this->table('notifications')->count());
        $this->invalid(fn () => $this->execute($approve));
        $source = (array) $this->table('pitch_requests')->find($id);
        unset($source['id'], $source['created_at'], $source['updated_at'], $source['decided_by']);
        $source['status'] = 'pending';
        $source['revision'] = 1;
        $second = $this->insert('pitch_requests', $source);
        $reject = $this->plan('prepare_pitch_reject', ['id' => $second]);
        self::assertSame('executed', $this->execute($reject)['status']);
        self::assertSame('rejected', $this->table('pitch_requests')->where('id', $second)->value('status'));
    }

    public function test_expense_internal_only_and_bexio_or_payment_markers_fail_closed(): void
    {
        $this->fixtures();
        foreach (['O', 'F', 'R', 'Z', 'B'] as $status) {
            $this->statuses[$status] = $this->insert('expense_statuses', ['code' => $status, 'name' => $status]);
        }
        $id = $this->insert('expenses', ['member_id' => $this->member, 'season_id' => $this->season,
            'status_id' => $this->statuses['F'], 'released_on' => now()->toDateString(), 'released_by' => 'Synthetic']);
        $input = ['id' => $id, 'status' => 'R', 'reason' => 'Synthetic internal rejection'];
        $plan = $this->plan('prepare_expense_status', $input);
        self::assertStringContainsString('Synthetic Contact', $plan['preview']['changes'][1]['after']);
        self::assertContains('Synthetic current', array_column($plan['preview']['changes'], 'after'));
        foreach (['F', 'Z', 'B'] as $status) {
            $this->invalid(fn () => $this->tools->prepare($this->actor, $this->tenant, $plan['operation'], array_replace($input, ['status' => $status])));
        }
        foreach (['bexio_bill_id' => 'synthetic-external', 'payment_released_by' => 'Synthetic', 'paid_by' => 'Synthetic'] as $field => $value) {
            $this->table('expenses')->where('id', $id)->update([$field => $value]);
            $this->invalid(fn () => $this->execute($plan), 'status');
            $this->table('expenses')->where('id', $id)->update([$field => null]);
        }
        self::assertSame('executed', $this->execute($plan)['status']);
        $row = $this->table('expenses')->find($id);
        self::assertSame($this->statuses['R'], $row->status_id);
        self::assertNull($row->released_on);
        self::assertNull($row->released_by);
        self::assertSame(1, $this->table('expense_events')->count());
        $reopen = $this->plan('prepare_expense_status', array_replace($input, ['status' => 'O']));
        self::assertSame('executed', $this->execute($reopen)['status']);
        self::assertSame($this->statuses['O'], $this->table('expenses')->where('id', $id)->value('status_id'));
        self::assertSame(2, $this->table('object_changes')->where('object_type', 'expenses')->count());
    }

    public function test_preview_locale_changes_only_labels_not_arguments_revision_or_user_text(): void
    {
        $this->fixtures();
        $plans = [];
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $plans[$locale] = $this->plan('prepare_member_contact', $this->contact());
        }
        self::assertSame($plans['de']['arguments'], $plans['en']['arguments']);
        self::assertSame($plans['de']['revision'], $plans['en']['revision']);
        self::assertNotSame($plans['de']['preview']['title'], $plans['en']['preview']['title']);
        self::assertSame(array_column($plans['de']['preview']['changes'], 'after'), array_column($plans['en']['preview']['changes'], 'after'));
    }
}
