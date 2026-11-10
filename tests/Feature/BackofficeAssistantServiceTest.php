<?php

namespace Tests\Feature;

use App\ClubUnio\Assistant\{AssistantAccess, AssistantActionTools, AssistantProvider, AssistantReadTools, BackofficeAssistantService};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\ImpersonationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL constraints; every parent, ledger and action is TEMP and rolled back. */
class BackofficeAssistantServiceTest extends TestCase
{
    private $db;
    private $grammar;
    private $schemaGrammar;
    private Tenant $tenant;
    private Tenant $foreign;
    private Account $actor;
    private Account $other;
    private AssistantProvider $provider;
    private AssistantReadTools $reads;
    private AssistantActionTools $writes;
    private BackofficeAssistantService $service;
    private const TABLES = ['tenants', 'accounts', 'tenant_accounts', 'assistant_conversations', 'assistant_runs', 'assistant_actions'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in with CLUBUNIO_TEST_DB=1 to TEMP PostgreSQL rollback fixtures.');
        }
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        config(['clubunio-assistant.enabled' => true, 'clubunio-assistant.provider_enabled' => true,
            'clubunio-assistant.privacy_reviewed' => true, 'clubunio-assistant.key' => 'synthetic-key',
            'clubunio-assistant.monthly_limit_microusd' => 30000000,
            'clubunio-assistant.request_reservation_microusd' => 400000,
            'clubunio-assistant.max_rounds' => 4, 'clubunio-assistant.retention_days' => 30,
            'clubunio-assistant.confirmation_minutes' => 10]);
        app()->setLocale('en');
        $this->provider = $this->createMock(AssistantProvider::class);
        $this->provider->method('enabled')->willReturn(true);
        $this->reads = $this->createMock(AssistantReadTools::class);
        $this->writes = $this->createMock(AssistantActionTools::class);
        $this->reads->method('definitions')->willReturn([$this->definition('read_members')]);
        $this->writes->method('definitions')->willReturn([$this->definition('prepare_registration_status')]);
        $this->service = $this->serviceWith($this->provider);

        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->getSchemaBuilder();
        $this->schemaGrammar = $this->db->getSchemaGrammar();
        $this->assertSame(0, $this->db->transactionLevel());
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name = 'clubunio-assistant-synthetic-tests'");
        // Minimal parent contracts avoid touching live rows or nontransactional ID sequences.
        $this->db->statement('CREATE TEMP TABLE tenants (id bigint PRIMARY KEY, slug text NOT NULL,
            name text NOT NULL, created_at timestamp, updated_at timestamp) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE accounts (id bigint PRIMARY KEY, username text NOT NULL,
            display_name text NOT NULL, password text NOT NULL, active boolean NOT NULL DEFAULT true,
            api_only boolean NOT NULL DEFAULT false, created_at timestamp, updated_at timestamp) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE tenant_accounts (tenant_id bigint NOT NULL REFERENCES pg_temp.tenants(id),
            account_id bigint NOT NULL REFERENCES pg_temp.accounts(id), role text NOT NULL,
            portal_enabled boolean NOT NULL DEFAULT true, PRIMARY KEY(tenant_id,account_id)) ON COMMIT DROP');
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_06_000100_backoffice_assistant.php'))->up());
        $this->assertNotEmpty($ddl);
        foreach ($ddl as $query) {
            $this->db->statement(str_replace(['clubunio.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']));
        }
        $this->db->setQueryGrammar(new class($this->db, self::TABLES) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function __construct($connection, private array $tables) { parent::__construct($connection); }
            public function wrapTable($table, $prefix = null)
            {
                if (is_string($table)) {
                    foreach ($this->tables as $name) {
                        if ($table === 'clubunio.'.$name || str_starts_with($table, 'clubunio.'.$name.' as ')) {
                            $table = 'pg_temp.'.substr($table, strlen('clubunio.'));
                            break;
                        }
                    }
                }
                return parent::wrapTable($table, $prefix);
            }
            public function wrap($value, $prefixAlias = false)
            {
                if (is_string($value)) {
                    foreach ($this->tables as $name) {
                        if (str_starts_with($value, 'clubunio.'.$name.'.')) {
                            $value = 'pg_temp.'.substr($value, strlen('clubunio.'));
                            break;
                        }
                    }
                }
                return parent::wrap($value, $prefixAlias);
            }
        });
        $tempSchema = $this->db->scalar('SELECT nspname FROM pg_namespace WHERE oid = pg_my_temp_schema()');
        $this->db->setSchemaGrammar(new class($this->db, $tempSchema) extends \Illuminate\Database\Schema\Grammars\PostgresGrammar {
            public function __construct($connection, private string $tempSchema) { parent::__construct($connection); }
            public function compileTableExists($schema, $table)
            {
                return parent::compileTableExists($schema === 'clubunio' && str_starts_with($table, 'assistant_')
                    ? $this->tempSchema : $schema, $table);
            }
        });
        $this->tenant = (new Tenant)->forceFill(['id' => 101, 'slug' => 'assistant-fixture', 'name' => 'Synthetic club']);
        $this->tenant->save();
        $this->foreign = (new Tenant)->forceFill(['id' => 102, 'slug' => 'assistant-foreign', 'name' => 'Synthetic foreign']);
        $this->foreign->save();
        $this->actor = $this->account(201);
        $this->other = $this->account(202);
        foreach ([$this->tenant, $this->foreign] as $tenant) {
            foreach ([$this->actor, $this->other] as $actor) {
                $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id,
                    'account_id' => $actor->id, 'role' => 'admin']);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db) {
                while ($this->db->transactionLevel() > 0) $this->db->rollBack();
                if ($this->grammar) $this->db->setQueryGrammar($this->grammar);
                if ($this->schemaGrammar) $this->db->setSchemaGrammar($this->schemaGrammar);
                Http::assertNothingSent();
                Mail::assertNothingSent();
                Mail::assertNothingQueued();
            }
        } finally {
            $this->travelBack();
            parent::tearDown();
        }
    }

    private function account(int $id): Account
    {
        $actor = (new Account)->forceFill(['id' => $id, 'username' => 'assistant-'.$id,
            'display_name' => 'Synthetic actor', 'password' => 'not-a-login-credential', 'active' => true, 'api_only' => false]);
        $actor->save();
        return $actor;
    }

    private function serviceWith(AssistantProvider $provider): BackofficeAssistantService
    {
        return new BackofficeAssistantService(new AssistantAccess, $provider, $this->reads, $this->writes);
    }

    private function definition(string $name): array
    {
        return ['type' => 'function', 'name' => $name, 'strict' => true,
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]];
    }

    private function table(string $name)
    {
        return $this->db->table('clubunio.assistant_'.$name);
    }

    private function encrypted(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function conversation(?Account $actor = null, ?Tenant $tenant = null, array $values = []): int
    {
        return $this->table('conversations')->insertGetId($this->timestamps($values) + ['tenant_id' => ($tenant ?? $this->tenant)->id,
            'account_id' => ($actor ?? $this->actor)->id,
            'transcript_encrypted' => $this->encrypted([['role' => 'user', 'content' => 'Private synthetic history']])]);
    }

    private function proposal(array $arguments = []): array
    {
        return ['operation' => 'prepare_registration_status', 'arguments' => $arguments + ['id' => 301, 'revision' => 'revision-1'],
            'preview' => ['title' => 'Synthetic status change', 'changes' => [['field' => 'status', 'old' => 'review', 'new' => 'trial']],
                'effects' => ['No external delivery']]];
    }

    private function action(?int $conversation = null, array $values = [], ?array $proposal = null): int
    {
        $proposal ??= $this->proposal();
        return $this->table('actions')->insertGetId($this->timestamps($values) + ['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id,
            'conversation_id' => $conversation ?? $this->conversation(), 'operation' => $proposal['operation'],
            'arguments_encrypted' => $this->encrypted($proposal['arguments']), 'preview_encrypted' => $this->encrypted($proposal['preview']),
            'state' => 'pending', 'locale' => 'en', 'expires_at' => now()->addMinutes(10)->toIso8601String()]);
    }

    private function timestamps(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $values);
    }

    private function response(array $calls = [], string $text = 'Synthetic answer'): array
    {
        return ['text' => $text, 'calls' => $calls, 'input_tokens' => 10, 'output_tokens' => 20, 'cost_microusd' => 100];
    }

    private function toolCall(string $name, string $id = 'call-1', array $arguments = []): array
    {
        return ['name' => $name, 'call_id' => $id, 'arguments' => $arguments];
    }

    private function ask(?int $conversation = null, ?string $key = null, string $text = 'Synthetic question'): array
    {
        return $this->service->ask($this->actor, $this->tenant, $conversation, $text, $key ?? (string) Str::uuid());
    }

    private function assertStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP '.$status.'.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function assertValidation(callable $callback): ValidationException
    {
        try {
            $callback();
            $this->fail('Expected a safe validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assistant', $e->errors());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY', json_encode($e->errors()));
            return $e;
        }
    }

    private function role(string $role): void
    {
        $this->db->table('clubunio.tenant_accounts')->where('account_id', $this->actor->id)
            ->where('tenant_id', $this->tenant->id)->update(['role' => $role]);
    }

    public function test_privacy_gate_stops_before_http_conversation_or_reservation(): void
    {
        config(['clubunio-assistant.privacy_reviewed' => false]);
        $this->reads->expects($this->never())->method('execute');
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $service = $this->serviceWith(new AssistantProvider);
        $this->assertValidation(fn () => $service->ask($this->actor, $this->tenant, null, 'Synthetic question'));
        $this->assertSame(0, $this->table('runs')->count());
        $this->assertSame(0, $this->table('conversations')->count());
        Http::assertNothingSent();
    }

    public static function deniedActors(): array
    {
        return array_combine(['trainer', 'inactive', 'api_only', 'missing_tenant', 'impersonation', 'session_impersonation', 'disabled', 'no_membership'],
            array_map(fn ($name) => [$name], ['trainer', 'inactive', 'api_only', 'missing_tenant', 'impersonation', 'session_impersonation', 'disabled', 'no_membership']));
    }

    #[DataProvider('deniedActors')]
    public function test_fresh_access_denies_every_entrypoint(string $reason): void
    {
        $id = $this->action();
        match ($reason) {
            'trainer' => $this->role('trainer'),
            'inactive' => $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update(['active' => false]),
            'api_only' => $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update(['api_only' => true]),
            'missing_tenant' => $this->tenant = (new Tenant)->forceFill(['id' => 999, 'slug' => 'missing', 'name' => 'Missing tenant']),
            'impersonation' => request()->attributes->set('clubunio_impersonation', false),
            'session_impersonation' => $this->enableSessionImpersonation(),
            'disabled' => config(['clubunio-assistant.enabled' => false]),
            'no_membership' => $this->db->table('clubunio.tenant_accounts')->where('account_id', $this->actor->id)->delete(),
        };
        $this->provider->expects($this->never())->method('respond');
        $this->reads->expects($this->never())->method('execute');
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $this->assertFalse($this->service->access($this->actor, $this->tenant));
        foreach ([fn () => $this->service->state($this->actor, $this->tenant), fn () => $this->ask(),
            fn () => $this->service->briefing($this->actor, $this->tenant),
            fn () => $this->service->confirm($this->actor, $this->tenant, $id),
            fn () => $this->service->cancel($this->actor, $this->tenant, $id)] as $callback) {
            $this->assertStatus(403, $callback);
        }
        $this->assertSame(0, $this->table('runs')->count());
        $this->assertSame('pending', $this->table('actions')->where('id', $id)->value('state'));
    }

    private function enableSessionImpersonation(): void
    {
        request()->setLaravelSession(app('session.store'));
        request()->session()->put(ImpersonationService::KEY, 'synthetic-support-session');
    }

    public function test_office_role_is_allowed_without_admin_escalation(): void
    {
        $this->role('office');
        $this->assertTrue($this->service->access($this->actor, $this->tenant));
        $this->assertSame([], $this->service->state($this->actor, $this->tenant)['conversations']);
    }

    public function test_tenant_and_owner_isolation_applies_to_history_ask_confirm_and_cancel(): void
    {
        $own = $this->conversation();
        $others = [$this->conversation($this->other), $this->conversation($this->actor, $this->foreign)];
        $this->provider->expects($this->never())->method('respond');
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        foreach ($others as $conversation) {
            $row = $this->table('conversations')->find($conversation);
            $action = $this->action($conversation, ['account_id' => $row->account_id, 'tenant_id' => $row->tenant_id]);
            $this->assertStatus(404, fn () => $this->service->state($this->actor, $this->tenant, $conversation));
            $this->assertStatus(404, fn () => $this->ask($conversation));
            $this->assertStatus(404, fn () => $this->service->confirm($this->actor, $this->tenant, $action));
            $this->assertStatus(404, fn () => $this->service->cancel($this->actor, $this->tenant, $action));
        }
        $state = $this->service->state($this->actor, $this->tenant);
        $this->assertSame([$own], array_column($state['conversations'], 'id'));
        $this->assertSame([], $state['messages']);
        $this->assertSame(0, $this->table('runs')->count());
    }

    public function test_duplicate_request_is_not_called_or_charged_twice_and_changed_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $this->provider->expects($this->once())->method('respond')->willReturn($this->response());
        $first = $this->ask(null, $key);
        $second = $this->ask(null, $key);
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->table('runs')->count());
        $this->assertSame(100, (int) $this->table('runs')->sum('actual_microusd'));
        $this->assertEquals(0.0001, $second['budget']['used_usd']);
        $this->assertStatus(409, fn () => $this->ask(null, $key, 'Different question'));
        $this->assertStatus(409, fn () => $this->ask($first['conversation_id'], $key));
    }

    public function test_request_keys_are_scoped_to_owner_and_tenant(): void
    {
        $key = (string) Str::uuid();
        $this->provider->expects($this->exactly(3))->method('respond')->willReturn($this->response());
        $first = $this->ask(null, $key);
        $other = $this->service->ask($this->other, $this->tenant, null, 'Synthetic question', $key);
        $foreign = $this->service->ask($this->actor, $this->foreign, null, 'Synthetic question', $key);
        $this->assertCount(3, array_unique([$first['conversation_id'], $other['conversation_id'], $foreign['conversation_id']]));
        $this->assertSame(3, $this->table('runs')->count());
        $this->assertEquals(0.0002, $this->service->state($this->actor, $this->tenant)['budget']['used_usd']);
        $this->assertEquals(0.0001, $foreign['budget']['used_usd']);
    }

    public function test_outstanding_reservation_blocks_overlapping_owner_request_at_tenant_budget(): void
    {
        config(['clubunio-assistant.monthly_limit_microusd' => 400000]);
        $this->provider->expects($this->once())->method('respond')->willReturnCallback(function () {
            $run = $this->table('runs')->sole();
            $this->assertSame('running', $run->state);
            $this->assertNull($run->actual_microusd);
            $this->assertSame(400000, (int) $run->reserved_microusd);
            // Interleave a second caller while the first provider response is outstanding.
            $this->assertValidation(fn () => $this->service->ask($this->other, $this->tenant, null,
                'Overlapping synthetic request', (string) Str::uuid()));
            $this->assertSame(1, $this->table('runs')->count());
            return $this->response();
        });
        $this->ask();
        $this->assertSame(100, (int) $this->table('runs')->value('actual_microusd'));
    }

    public function test_busy_conversation_and_running_request_cannot_be_reentered(): void
    {
        $key = (string) Str::uuid();
        $this->provider->expects($this->once())->method('respond')->willReturnCallback(function () use ($key) {
            $conversation = (int) $this->table('runs')->sole()->conversation_id;
            $this->assertStatus(409, fn () => $this->ask(null, $key));
            $this->assertStatus(409, fn () => $this->ask($conversation));
            return $this->response();
        });
        $this->ask(null, $key);
        $this->assertSame(1, $this->table('runs')->count());
    }

    public function test_timeout_keeps_reservation_attention_and_prevents_same_request_retry(): void
    {
        $key = (string) Str::uuid();
        $this->provider->expects($this->once())->method('respond')->willThrowException(new ConnectionException('PRIVATE_PROVIDER_BODY'));
        $this->assertValidation(fn () => $this->ask(null, $key));
        $run = $this->table('runs')->sole();
        $this->assertSame('attention', $run->state);
        $this->assertSame('provider_connection_uncertain', $run->error_code);
        $this->assertNull($run->actual_microusd);
        $this->assertSame(400000, (int) $run->reserved_microusd);
        $this->assertNull($this->table('conversations')->find($run->conversation_id)->busy_run);
        $this->assertEquals(0.4, $this->service->state($this->actor, $this->tenant)['budget']['used_usd']);
        $this->assertStatus(409, fn () => $this->ask(null, $key));
        $this->assertSame(1, $this->table('runs')->count());
        $this->assertSame(0, $this->table('actions')->count());
    }

    public function test_timeout_after_prepare_discards_unfinished_proposals_and_retains_full_reservation(): void
    {
        $round = 0;
        $this->provider->expects($this->exactly(2))->method('respond')->willReturnCallback(function () use (&$round) {
            if ($round++ === 0) return $this->response([$this->toolCall('prepare_registration_status')]);
            throw new ConnectionException('PRIVATE_PROVIDER_BODY');
        });
        $this->writes->expects($this->once())->method('prepare')->willReturn($this->proposal());
        $this->writes->expects($this->never())->method('execute');
        $this->assertValidation(fn () => $this->ask());
        $this->assertSame(0, $this->table('actions')->count());
        $run = $this->table('runs')->sole();
        $this->assertSame('attention', $run->state);
        $this->assertNull($run->actual_microusd);
        $this->assertSame(10, (int) $run->input_tokens);
        $this->assertSame(20, (int) $run->output_tokens);
        $this->assertEquals(0.4, $this->service->state($this->actor, $this->tenant)['budget']['used_usd']);
    }

    public function test_undersized_reservation_is_rejected_before_any_provider_call(): void
    {
        config(['clubunio-assistant.request_reservation_microusd' => 399999]);
        $this->provider->expects($this->never())->method('respond');
        $this->assertStatus(503, fn () => $this->ask());
        $this->assertSame(0, $this->table('runs')->count());
        $this->assertSame(0, $this->table('conversations')->count());
    }

    public function test_round_limit_is_hard_capped_and_does_not_execute_proposals(): void
    {
        config(['clubunio-assistant.max_rounds' => 99]);
        $this->provider->expects($this->exactly(4))->method('respond')->willReturn($this->response([
            $this->toolCall('prepare_registration_status')]));
        $this->writes->expects($this->exactly(4))->method('prepare')->willReturn($this->proposal());
        $this->writes->expects($this->never())->method('execute');
        $state = $this->ask();
        $this->assertCount(1, $state['actions']);
        $this->assertSame('pending', $state['actions'][0]['status']);
        $this->assertSame(400, (int) $this->table('runs')->value('actual_microusd'));
    }

    public function test_unknown_forged_tool_is_rejected_without_read_prepare_or_write(): void
    {
        $this->provider->expects($this->once())->method('respond')->willReturn($this->response([$this->toolCall('execute_bank_payment')]));
        $this->reads->expects($this->never())->method('execute');
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $this->assertValidation(fn () => $this->ask());
        $this->assertSame('attention', $this->table('runs')->value('state'));
        $this->assertSame('http_422', $this->table('runs')->value('error_code'));
        $this->assertSame(0, $this->table('actions')->count());
    }

    public function test_multiround_read_and_duplicate_prepare_never_execute_before_confirmation(): void
    {
        $proposal = $this->proposal();
        $round = 0;
        $this->reads->expects($this->once())->method('execute')->with($this->actor, $this->tenant, 'read_members', [])
            ->willReturn(['title' => 'Synthetic members', 'rows' => [['id' => 301, 'revision' => 'revision-1']]]);
        $this->writes->expects($this->exactly(2))->method('prepare')->with($this->actor, $this->tenant,
            'prepare_registration_status', $proposal['arguments'])->willReturn($proposal);
        $this->writes->expects($this->never())->method('execute');
        $this->provider->expects($this->exactly(3))->method('respond')->willReturnCallback(function ($input, $tools, $locale) use (&$round, $proposal) {
            $this->assertSame('en', $locale);
            $this->assertSame(['read_members', 'prepare_registration_status'], array_column($tools, 'name'));
            if ($round++ === 0) return $this->response([$this->toolCall('read_members', 'read-1')]);
            $outputs = array_values(array_filter($input, fn ($item) => ($item['type'] ?? '') === 'function_call_output'));
            if ($round === 2) {
                $this->assertSame('read-1', $outputs[0]['call_id']);
                $this->assertSame(301, json_decode($outputs[0]['output'], true)['rows'][0]['id']);
                return $this->response([$this->toolCall('prepare_registration_status', 'prepare-1', $proposal['arguments']),
                    $this->toolCall('prepare_registration_status', 'prepare-2', $proposal['arguments'])]);
            }
            $this->assertSame('awaiting_human_confirmation', json_decode($outputs[1]['output'], true)['status']);
            $this->assertSame('awaiting_human_confirmation', json_decode($outputs[2]['output'], true)['status']);
            return $this->response([], 'Please review the proposal.');
        });
        $state = $this->ask();
        $this->assertCount(1, $state['actions']);
        $this->assertSame('pending', $state['actions'][0]['status']);
        $this->assertSame(300, (int) $this->table('runs')->value('actual_microusd'));
        $this->assertSame('en', $this->table('actions')->value('locale'));
        $this->assertNull($this->table('actions')->value('completed_at'));
        $this->assertStringNotContainsString('Synthetic question', $this->table('conversations')->value('transcript_encrypted'));
        $this->assertStringNotContainsString('revision-1', $this->table('actions')->value('arguments_encrypted'));
    }

    public function test_permission_revocation_during_provider_call_stops_tool_dispatch(): void
    {
        $this->provider->expects($this->once())->method('respond')->willReturnCallback(function () {
            $this->role('trainer');
            return $this->response([$this->toolCall('read_members')]);
        });
        $this->reads->expects($this->never())->method('execute');
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $this->assertValidation(fn () => $this->ask());
        $this->assertSame('attention', $this->table('runs')->value('state'));
        $this->assertSame('http_403', $this->table('runs')->value('error_code'));
        $this->assertNull($this->table('conversations')->value('busy_run'));
    }

    public function test_permission_revocation_between_tool_calls_stops_next_prepare(): void
    {
        $this->provider->expects($this->once())->method('respond')->willReturn($this->response([
            $this->toolCall('read_members'), $this->toolCall('prepare_registration_status', 'call-2')]));
        $this->reads->expects($this->once())->method('execute')->willReturnCallback(function () {
            $this->role('trainer');
            return ['rows' => []];
        });
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $this->assertValidation(fn () => $this->ask());
        $this->assertSame(0, $this->table('actions')->count());
    }

    public function test_expired_confirmation_cannot_execute(): void
    {
        $id = $this->action(values: ['expires_at' => now()->subSecond()]);
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $this->assertStatus(409, fn () => $this->service->confirm($this->actor, $this->tenant, $id));
        $conversation = (int) $this->table('actions')->find($id)->conversation_id;
        $this->assertSame('expired', $this->service->state($this->actor, $this->tenant, $conversation)['actions'][0]['status']);
    }

    public function test_prepared_expiry_is_ten_minutes_in_absolute_time_on_non_utc_database_session(): void
    {
        $this->db->statement("SET LOCAL TIME ZONE 'America/Los_Angeles'");
        $this->travelTo(now()->startOfSecond());
        $this->provider->expects($this->exactly(2))->method('respond')->willReturnOnConsecutiveCalls(
            $this->response([$this->toolCall('prepare_registration_status')]), $this->response());
        $this->writes->expects($this->once())->method('prepare')->willReturn($this->proposal());
        $this->writes->expects($this->never())->method('execute');
        $this->ask();
        $expiry = \Carbon\CarbonImmutable::parse($this->table('actions')->sole()->expires_at);
        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $expiry->getTimestamp(),
            'Confirmation expires_at must carry its UTC offset, not inherit the PostgreSQL session timezone.');
    }

    public function test_month_budget_boundary_is_zurich_absolute_time_not_database_timezone(): void
    {
        $this->db->statement("SET LOCAL TIME ZONE 'America/Los_Angeles'");
        $month = \Carbon\CarbonImmutable::now('Europe/Zurich')->startOfMonth()->utc();
        $conversation = $this->conversation();
        foreach ([[$month->subSecond(), 200000], [$month, 400000]] as [$created, $cost]) {
            $this->table('runs')->insert(['id' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'conversation_id' => $conversation,
                'fingerprint' => hash('sha256', 'synthetic'), 'state' => 'attention', 'reserved_microusd' => $cost,
                'created_at' => $created->toIso8601String()]);
        }
        $this->assertEquals(0.4, $this->service->state($this->actor, $this->tenant)['budget']['used_usd']);
    }

    public function test_retention_boundary_uses_absolute_time_for_visibility_and_purge(): void
    {
        $this->db->statement("SET LOCAL TIME ZONE 'America/Los_Angeles'");
        $this->travelTo(now()->startOfSecond());
        $inside = $this->conversation(values: ['updated_at' => now()->subDays(30)->addHour()]);
        $outside = $this->conversation(values: ['updated_at' => now()->subDays(30)->subSecond()]);
        $this->assertSame([$inside], array_column($this->service->state($this->actor, $this->tenant)['conversations'], 'id'));
        $this->assertSame($inside, $this->service->state($this->actor, $this->tenant, $inside)['conversation_id']);
        $this->assertStatus(404, fn () => $this->service->state($this->actor, $this->tenant, $outside));
        $this->artisan('clubunio:assistant:purge', ['--apply' => true])->assertExitCode(0);
        $this->assertNotNull($this->table('conversations')->find($inside));
        $this->assertNull($this->table('conversations')->find($outside));
    }

    public static function staleProposals(): array
    {
        return ['preview' => ['preview'], 'revision' => ['revision']];
    }

    #[DataProvider('staleProposals')]
    public function test_stale_preview_or_revision_cannot_execute(string $change): void
    {
        $id = $this->action();
        $fresh = $this->proposal();
        if ($change === 'preview') $fresh['preview']['effects'][] = 'New side effect';
        else $fresh['arguments']['revision'] = 'revision-2';
        $this->writes->expects($this->once())->method('prepare')->willReturn($fresh);
        $this->writes->expects($this->never())->method('execute');
        $this->assertStatus(409, fn () => $this->service->confirm($this->actor, $this->tenant, $id));
        $this->assertSame('pending', $this->table('actions')->find($id)->state);
    }

    public function test_confirmation_uses_original_locale_and_restores_current_locale(): void
    {
        $id = $this->action(values: ['locale' => 'de']);
        $this->writes->expects($this->once())->method('prepare')->willReturnCallback(function () {
            $this->assertSame('de', app()->getLocale());
            return $this->proposal();
        });
        $this->writes->expects($this->once())->method('execute')->willReturn(['status' => 'done']);
        $this->service->confirm($this->actor, $this->tenant, $id);
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('succeeded', $this->table('actions')->find($id)->state);
    }

    public function test_confirmation_claim_is_persisted_before_execute_and_reentry_and_replay_are_exactly_once(): void
    {
        $id = $this->action();
        $this->writes->expects($this->once())->method('prepare')->willReturn($this->proposal());
        $this->writes->expects($this->once())->method('execute')->willReturnCallback(function () use ($id) {
            $this->assertSame('running', $this->table('actions')->find($id)->state);
            $this->assertSame(1, $this->db->transactionLevel(), 'The claim transaction must have ended before the adapter runs.');
            $this->assertStatus(409, fn () => $this->service->confirm($this->actor, $this->tenant, $id));
            $this->assertStatus(409, fn () => $this->service->cancel($this->actor, $this->tenant, $id));
            return ['status' => 'done', 'id' => 301];
        });
        $first = $this->service->confirm($this->actor, $this->tenant, $id);
        $this->assertSame($first, $this->service->confirm($this->actor, $this->tenant, $id));
        $row = $this->table('actions')->find($id);
        $this->assertSame('succeeded', $row->state);
        $this->assertNotNull($row->completed_at);
        $this->assertSame(['status' => 'done', 'id' => 301], json_decode(Crypt::decryptString($row->result_encrypted), true));
    }

    public function test_failed_write_stays_attention_and_is_never_retried_or_cancelled(): void
    {
        $id = $this->action();
        $this->writes->expects($this->once())->method('prepare')->willReturn($this->proposal());
        $this->writes->expects($this->once())->method('execute')->willThrowException(new \RuntimeException('PRIVATE_PROVIDER_BODY'));
        $this->assertValidation(fn () => $this->service->confirm($this->actor, $this->tenant, $id));
        $row = $this->table('actions')->find($id);
        $this->assertSame('attention', $row->state);
        $this->assertSame('unexpected_failure', $row->error_code);
        $this->assertNotNull($row->completed_at);
        $this->assertNull($row->result_encrypted);
        $this->assertStatus(409, fn () => $this->service->confirm($this->actor, $this->tenant, $id));
        $this->assertStatus(409, fn () => $this->service->cancel($this->actor, $this->tenant, $id));
    }

    public function test_permission_revoked_during_fresh_preview_is_checked_again_before_execute(): void
    {
        $id = $this->action();
        $this->writes->expects($this->once())->method('prepare')->willReturnCallback(function () {
            $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update(['active' => false]);
            return $this->proposal();
        });
        $this->writes->expects($this->never())->method('execute');
        $this->assertValidation(fn () => $this->service->confirm($this->actor, $this->tenant, $id));
        $this->assertSame('attention', $this->table('actions')->find($id)->state);
        $this->assertSame('http_403', $this->table('actions')->find($id)->error_code);
    }

    public function test_cancel_is_idempotent_and_permanently_blocks_confirmation(): void
    {
        $id = $this->action();
        $this->writes->expects($this->never())->method('prepare');
        $this->writes->expects($this->never())->method('execute');
        $state = $this->service->cancel($this->actor, $this->tenant, $id);
        $this->assertSame('cancelled', $state['actions'][0]['status']);
        $this->assertSame($state, $this->service->cancel($this->actor, $this->tenant, $id));
        $this->assertNotNull($this->table('actions')->find($id)->completed_at);
        $this->assertStatus(409, fn () => $this->service->confirm($this->actor, $this->tenant, $id));
    }

    public function test_purge_preview_is_read_only_and_apply_deletes_only_expired_clean_payloads(): void
    {
        $expired = $this->conversation(values: ['updated_at' => now()->subDays(31)]);
        $expiredAction = $this->action($expired, ['state' => 'succeeded']);
        $recent = $this->conversation();
        $busy = $this->conversation(values: ['updated_at' => now()->subDays(31), 'busy_run' => (string) Str::uuid()]);
        $running = $this->conversation(values: ['updated_at' => now()->subDays(31)]);
        $attention = $this->conversation(values: ['updated_at' => now()->subDays(31)]);
        $this->action($running, ['state' => 'running']);
        $this->action($attention, ['state' => 'attention']);
        $run = (string) Str::uuid();
        $this->table('runs')->insert(['id' => $run, 'request_key' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'account_id' => $this->actor->id, 'conversation_id' => $expired, 'fingerprint' => hash('sha256', 'synthetic'),
            'state' => 'succeeded', 'reserved_microusd' => 400000, 'actual_microusd' => 100]);
        $this->artisan('clubunio:assistant:purge')->assertExitCode(0);
        $this->assertSame(5, $this->table('conversations')->count());
        $this->assertNotNull($this->table('actions')->find($expiredAction));
        $this->artisan('clubunio:assistant:purge', ['--apply' => true])->assertExitCode(0);
        $this->assertNull($this->table('conversations')->find($expired));
        $this->assertNull($this->table('actions')->find($expiredAction));
        $this->assertSame([$recent, $busy, $running, $attention], $this->table('conversations')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $ledger = $this->table('runs')->find($run);
        $this->assertNotNull($ledger);
        $this->assertNull($ledger->conversation_id);
        $this->assertSame(100, (int) $ledger->actual_microusd);
    }
}
