<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BexioConnectionService, OpenAiModels, PlatformAdministrationService, TenantAiConfiguration, TenantAiGateway, TenantAiKnowledge, TenantDocumentStorage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Http, Storage};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantAiTest extends TestCase
{
    private Tenant $tenant;
    private Tenant $foreign;
    private Account $admin;
    private Account $platform;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'clubunio',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'clubunio-tenant-ai.enabled' => true, 'clubunio-storage.profiles_enabled' => true,
            'clubunio-storage.managed_enabled' => true]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'accounts (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1, api_only INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)',
            'tenants (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, created_at TEXT, updated_at TEXT)',
            'tenant_accounts (tenant_id INTEGER, account_id INTEGER, role TEXT, PRIMARY KEY(tenant_id,account_id))',
            'platform_admins (account_id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)',
            "tenant_ai_connections (tenant_id INTEGER PRIMARY KEY, mode TEXT NOT NULL CHECK(mode IN ('own','clubunio')), project_id TEXT UNIQUE, encrypted_key TEXT, key_hash TEXT UNIQUE, enabled INTEGER NOT NULL DEFAULT 0, privacy_reviewed INTEGER NOT NULL DEFAULT 0, monthly_limit_microusd INTEGER NOT NULL DEFAULT 30000000 CHECK(monthly_limit_microusd BETWEEN 1 AND 1000000000), verified_fingerprint TEXT, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "tenant_ai_stores (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenant_ai_connections(tenant_id), name TEXT NOT NULL, purpose TEXT NOT NULL CHECK(purpose IN ('training','club')), remote_id TEXT UNIQUE, state TEXT NOT NULL DEFAULT 'creating', usage_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,id))",
            "tenant_ai_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, store_id INTEGER NOT NULL, name TEXT NOT NULL, folder TEXT, filename TEXT, mime_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, remote_file_id TEXT UNIQUE, state TEXT NOT NULL DEFAULT 'stored', error_code TEXT, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,store_id) REFERENCES tenant_ai_stores(tenant_id,id))",
            "tenant_ai_usage (id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL REFERENCES tenant_ai_connections(tenant_id), task TEXT NOT NULL, mode TEXT NOT NULL, project_id TEXT NOT NULL, model TEXT, state TEXT NOT NULL DEFAULT 'running', reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd>=0), actual_microusd INTEGER, input_tokens INTEGER, output_tokens INTEGER, search_calls INTEGER NOT NULL DEFAULT 0, error_code TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'tenant_ai_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants(id), actor_id INTEGER, action TEXT NOT NULL, object_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'tenant_storage_profiles (id TEXT PRIMARY KEY, tenant_id INTEGER, provider TEXT, account TEXT, container TEXT, encrypted_key TEXT, active INTEGER, UNIQUE(tenant_id,provider,account,container))',
            'tenant_storage_objects (profile_id TEXT, object_key TEXT, remote_id TEXT, PRIMARY KEY(profile_id,object_key), UNIQUE(profile_id,remote_id))',
        ] as $definition) {
            $db->statement('CREATE TABLE clubora.'.$definition);
        }
        $db->table('clubora.tenants')->insert([
            ['id' => 41, 'slug' => 'synthetic-own', 'name' => 'Synthetic own'],
            ['id' => 42, 'slug' => 'synthetic-foreign', 'name' => 'Synthetic foreign'],
        ]);
        $db->table('clubora.accounts')->insert([['id' => 1], ['id' => 2]]);
        $db->table('clubora.tenant_accounts')->insert([
            ['tenant_id' => 41, 'account_id' => 1, 'role' => 'admin'],
            ['tenant_id' => 42, 'account_id' => 1, 'role' => 'admin'],
            ['tenant_id' => 41, 'account_id' => 2, 'role' => 'admin'],
        ]);
        $db->table('clubora.platform_admins')->insert(['account_id' => 2, 'active' => true]);
        $this->tenant = Tenant::findOrFail(41);
        $this->foreign = Tenant::findOrFail(42);
        $this->admin = Account::findOrFail(1);
        $this->platform = Account::findOrFail(2);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => [['id' => OpenAiModels::TEXT]]])]);
    }

    private function configuration(): TenantAiConfiguration
    {
        return app(TenantAiConfiguration::class);
    }

    private function input(Tenant $tenant, array $overrides = []): array
    {
        return array_replace(['mode' => 'own', 'project_id' => 'proj_synthetic_'.$tenant->id,
            'api_key' => 'sk-synthetic_tenant_key_'.$tenant->id, 'enabled' => false,
            'privacy_reviewed' => false, 'monthly_limit_microusd' => 30000000,
            'revision' => $this->configuration()->summary($tenant)['revision']], $overrides);
    }

    private function enable(Tenant $tenant, ?Account $actor = null, array $overrides = []): void
    {
        $actor ??= $this->admin;
        $this->configuration()->save($actor, $tenant, $this->input($tenant, $overrides));
        $this->configuration()->verify($actor, $tenant);
        $this->configuration()->save($actor, $tenant, $this->input($tenant,
            array_replace($overrides, ['api_key' => null, 'enabled' => true, 'privacy_reviewed' => true])));
    }

    private function assertHttpStatus(int $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$expected);
        } catch (HttpException $error) {
            $this->assertSame($expected, $error->getStatusCode());
        }
    }

    private function store(Tenant $tenant, string $remote = 'vs_synthetic_own'): int
    {
        return $this->configuration()->table('stores')->insertGetId(['tenant_id' => $tenant->id,
            'name' => 'Synthetic knowledge', 'purpose' => 'training', 'remote_id' => $remote, 'state' => 'ready']);
    }

    private function payload(): array
    {
        return ['model' => OpenAiModels::TEXT, 'store' => false, 'input' => 'Synthetic request', 'max_output_tokens' => 100];
    }

    public function test_tenant_profile_does_not_bypass_assistant_provider_gates(): void
    {
        $this->enable($this->tenant);
        Http::fake();
        config(['clubunio-assistant.provider_enabled'=>false,'clubunio-assistant.privacy_reviewed'=>false]);
        $provider = app(\App\ClubUnio\Assistant\AssistantProvider::class);
        $this->assertFalse($provider->enabled($this->tenant));
        Http::assertNothingSent();
    }

    public function test_training_ignores_legacy_or_injected_global_credentials_and_stores(): void
    {
        $this->enable($this->tenant);
        $this->store($this->tenant);
        Http::fake(['https://api.openai.com/v1/responses'=>Http::response([],503)]);
        $this->assertHttpStatus(502,fn()=>app(\App\ClubUnio\Services\TrainingPlanAi::class)->generate($this->tenant,[],providerConfiguration:[
            'OPENAI_API_KEY'=>'sk-global-must-not-leave','OPENAI_TRAINING_MODEL'=>OpenAiModels::TRAINING,
            'OPENAI_TRAINING_VECTOR_STORE_ID'=>'vs_foreign']));
        Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer sk-synthetic_tenant_key_41')
            && $r->hasHeader('OpenAI-Project','proj_synthetic_41')
            && $r['tools'][0]['vector_store_ids']===['vs_synthetic_own']
            && !str_contains($r->body(),'SV-Sissach'));
        Http::assertSentCount(1);
    }

    public function test_mobile_without_own_profile_never_uses_global_key(): void
    {
        config(['clubunio-mobile.ai.openai.key'=>'sk-global-must-not-leave']);
        $this->assertHttpStatus(503,fn()=>(new \App\ClubUnio\Mobile\OpenAiTextProvider(tenant:$this->tenant))->transform('translate','Synthetic','en'));
        Http::assertNothingSent();
    }

    public function test_budget_is_reloaded_after_waiting_for_tenant_lock(): void
    {
        $this->enable($this->tenant);
        Http::fake();
        $changed=false;
        DB::listen(function($query) use (&$changed) {
            if (!$changed && str_contains($query->sql,'from "clubora"."tenants"')) {
                $changed=true;
                $this->configuration()->table()->where('tenant_id',$this->tenant->id)->update(['monthly_limit_microusd'=>1]);
            }
        });
        $this->assertHttpStatus(429,fn()=>app(TenantAiGateway::class)->respond($this->tenant,'mobile',$this->payload()));
        $this->assertTrue($changed);
        Http::assertNothingSent();
    }

    public function test_store_attach_rechecks_project_after_remote_validation(): void
    {
        $this->enable($this->tenant);
        Http::fake(['https://api.openai.com/v1/vector_stores/vs_race'=>function() {
            $this->configuration()->table()->where('tenant_id',$this->tenant->id)->update(['project_id'=>'proj_changed','revision'=>3,
                'verified_fingerprint'=>hash('sha256','proj_changed|'.hash('sha256','sk-synthetic_tenant_key_41'))]);
            return Http::response(['id'=>'vs_race','usage_bytes'=>0]);
        }]);
        $this->assertHttpStatus(409,fn()=>app(TenantAiKnowledge::class)->attachStore($this->admin,$this->tenant,'Synthetic','training','vs_race'));
        $this->assertSame(0,$this->configuration()->table('stores')->count());
        Http::assertSentCount(1);
    }

    public function test_real_authorization_checks_fresh_actor_and_platform_registration(): void
    {
        $this->assertTrue(BexioConnectionService::canManage($this->admin, $this->tenant));
        $this->assertFalse(app(PlatformAdministrationService::class)->allowed($this->admin));
        $this->assertTrue(app(PlatformAdministrationService::class)->allowed($this->platform));
        DB::table('clubora.accounts')->where('id', 1)->update(['active' => false]);
        $this->assertFalse(BexioConnectionService::canManage($this->admin, $this->tenant));
        $this->assertHttpStatus(403, fn () => $this->configuration()->save($this->admin, $this->tenant, $this->input($this->tenant)));
        Http::assertNothingSent();
    }

    public function test_own_profile_is_encrypted_masked_verified_then_enabled(): void
    {
        $this->enable($this->tenant);
        $row = $this->configuration()->table()->sole();
        $this->assertSame('sk-synthetic_tenant_key_41', Crypt::decryptString($row->encrypted_key));
        $this->assertNotSame('sk-synthetic_tenant_key_41', $row->encrypted_key);
        $summary = $this->configuration()->summary($this->tenant);
        $this->assertTrue($summary['key_present']);
        $this->assertTrue($summary['verified']);
        $this->assertTrue($summary['enabled']);
        $this->assertStringNotContainsString('sk-', json_encode($summary));
        $this->assertArrayNotHasKey('encrypted_key', $summary);
        $this->assertArrayNotHasKey('key_hash', $summary);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.openai.com/v1/models'
            && $r->hasHeader('Authorization', 'Bearer sk-synthetic_tenant_key_41') && $r->hasHeader('OpenAI-Project', 'proj_synthetic_41'));
        Http::assertSentCount(1);
    }

    public function test_activation_requires_verification_and_privacy_review(): void
    {
        foreach ([false, true] as $privacy) {
            try {
                $this->configuration()->save($this->admin, $this->tenant,
                    $this->input($this->tenant, ['enabled' => true, 'privacy_reviewed' => $privacy]));
                $this->fail('Unverified profile enabled');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('enabled', $error->errors());
            }
        }
        $this->assertSame(0, $this->configuration()->table()->count());
        Http::assertNothingSent();
    }

    public function test_duplicate_project_and_key_are_rejected_without_http(): void
    {
        $this->configuration()->save($this->admin, $this->tenant, $this->input($this->tenant));
        foreach ([['project_id' => 'proj_synthetic_41'], ['api_key' => 'sk-synthetic_tenant_key_41']] as $duplicate) {
            $this->assertHttpStatus(422, fn () => $this->configuration()->save($this->admin, $this->foreign, $this->input($this->foreign, $duplicate)));
        }
        $this->assertSame(1, $this->configuration()->table()->count());
        Http::assertNothingSent();
    }

    public function test_profile_key_or_project_change_invalidates_verification(): void
    {
        $this->enable($this->tenant);
        $this->configuration()->save($this->admin, $this->tenant, $this->input($this->tenant, ['api_key' => 'sk-synthetic_rotated_key_41']));
        $this->assertFalse($this->configuration()->summary($this->tenant)['verified']);
        $this->assertHttpStatus(503, fn () => $this->configuration()->credentials($this->tenant));
        $this->configuration()->verify($this->admin, $this->tenant);
        $this->configuration()->save($this->admin, $this->tenant,
            $this->input($this->tenant, ['project_id' => 'proj_synthetic_changed', 'api_key' => null]));
        $this->assertFalse($this->configuration()->summary($this->tenant)['verified']);
        $this->assertFalse($this->configuration()->summary($this->tenant)['key_present']);
    }

    public function test_project_change_is_blocked_when_store_binding_exists(): void
    {
        $this->enable($this->tenant);
        $this->store($this->tenant);
        $before = $this->configuration()->summary($this->tenant);
        $this->assertHttpStatus(409, fn () => $this->configuration()->save($this->admin, $this->tenant,
            $this->input($this->tenant, ['project_id' => 'proj_synthetic_changed'])));
        $this->assertSame($before, $this->configuration()->summary($this->tenant));
        Http::assertSentCount(1);
    }

    public function test_gateway_uses_only_selected_tenant_credentials_and_records_cost(): void
    {
        $this->enable($this->tenant);
        $this->enable($this->foreign);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10], 'output' => []])]);
        app(TenantAiGateway::class)->respond($this->tenant, 'mobile', $this->payload());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.openai.com/v1/responses'
            && $r->hasHeader('Authorization', 'Bearer sk-synthetic_tenant_key_41')
            && $r->hasHeader('OpenAI-Project', 'proj_synthetic_41') && $r['store'] === false);
        $usage = $this->configuration()->table('usage')->sole();
        $this->assertSame(41, $usage->tenant_id);
        $this->assertSame('completed', $usage->state);
        $this->assertSame(OpenAiModels::cost(OpenAiModels::TEXT, 20, 10), $usage->actual_microusd);
        $this->assertSame(OpenAiModels::cost(OpenAiModels::TEXT, 272000, 100), $usage->reserved_microusd);
    }

    public function test_monthly_budget_rejects_before_http_or_reservation(): void
    {
        $this->enable($this->tenant, overrides: ['monthly_limit_microusd' => 1]);
        $this->assertHttpStatus(429, fn () => app(TenantAiGateway::class)->respond($this->tenant, 'mobile', $this->payload()));
        $this->assertSame(0, $this->configuration()->table('usage')->count());
        Http::assertSentCount(1);
    }

    public function test_timeout_keeps_reservation_in_attention_without_retry(): void
    {
        $this->enable($this->tenant);
        $attempts = 0;
        Http::fake(['https://api.openai.com/v1/responses' => function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout');
        }]);
        try {
            app(TenantAiGateway::class)->respond($this->tenant, 'mobile', $this->payload());
            $this->fail('Timeout accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame(502, $error->getCode());
        }
        $this->assertSame(1, $attempts);
        $usage = $this->configuration()->table('usage')->sole();
        $this->assertSame('attention', $usage->state);
        $this->assertSame('provider_uncertain', $usage->error_code);
        $this->assertNull($usage->actual_microusd);
        $this->assertGreaterThan(0, $usage->reserved_microusd);
    }

    public function test_foreign_store_and_document_ids_fail_before_http(): void
    {
        $this->configuration()->save($this->admin, $this->foreign, $this->input($this->foreign));
        $store = $this->store($this->foreign, 'vs_synthetic_foreign');
        $id = $this->configuration()->table('documents')->insertGetId(['tenant_id' => 42, 'store_id' => $store,
            'name' => 'foreign.txt', 'mime_type' => 'text/plain', 'size_bytes' => 4, 'sha256' => hash('sha256', 'test'),
            'remote_file_id' => 'file-synthetic_foreign', 'state' => 'ready']);
        $knowledge = app(TenantAiKnowledge::class);
        foreach ([fn () => $knowledge->upload($this->admin, $this->tenant, $store, UploadedFile::fake()->createWithContent('test.txt', 'Synthetic test')),
            fn () => $knowledge->refresh($this->admin, $this->tenant, $id),
            fn () => $knowledge->remove($this->admin, $this->tenant, $id),
            fn () => $knowledge->download($this->admin, $this->tenant, $id),
            fn () => $knowledge->removeStore($this->admin, $this->tenant, $store)] as $action) {
            $this->assertHttpStatus(404, $action);
        }
        $this->assertSame([], $knowledge->documents($this->admin, $this->tenant));
        Http::assertNothingSent();
    }

    public function test_managed_credentials_and_budget_increase_require_platform_admin(): void
    {
        $this->enable($this->tenant, $this->platform, ['mode' => 'clubunio']);
        foreach ([['api_key' => 'sk-synthetic_replacement_41'], ['project_id' => 'proj_synthetic_replacement'],
            ['monthly_limit_microusd' => 40000000]] as $change) {
            $input = $this->input($this->tenant, array_replace(['mode' => 'clubunio', 'api_key' => null], $change));
            $this->assertHttpStatus(403, fn () => $this->configuration()->save($this->admin, $this->tenant, $input));
        }
        $this->configuration()->save($this->platform, $this->tenant,
            $this->input($this->tenant, ['mode' => 'clubunio', 'api_key' => null, 'monthly_limit_microusd' => 40000000]));
        $this->assertSame(40000000, $this->configuration()->summary($this->tenant)['monthly_limit_microusd']);
        Http::assertSentCount(1);
    }

    public function test_platform_provisioning_does_not_grant_document_access(): void
    {
        DB::table('clubora.tenant_accounts')->where('account_id', 2)->delete();
        $this->assertFalse(BexioConnectionService::canManage($this->platform, $this->tenant));
        $this->configuration()->save($this->platform, $this->tenant,
            $this->input($this->tenant, ['mode' => 'clubunio']), true);
        $this->configuration()->verify($this->platform, $this->tenant, true);
        $this->assertTrue($this->configuration()->summary($this->tenant)['verified']);
        $this->assertHttpStatus(403, fn () => app(TenantAiKnowledge::class)->stores($this->platform, $this->tenant));
        $this->assertHttpStatus(403, fn () => $this->configuration()->save($this->platform, $this->tenant,
            $this->input($this->tenant, ['mode' => 'clubunio', 'api_key' => null])));
    }

    public function test_impersonation_denies_configuration_and_document_operations(): void
    {
        $this->withSession([\App\ClubUnio\Services\ImpersonationService::KEY => ['synthetic' => true]]);
        request()->setLaravelSession($this->app['session.store']);
        $this->assertHttpStatus(403, fn () => $this->configuration()->save($this->admin, $this->tenant, $this->input($this->tenant)));
        $this->assertHttpStatus(403, fn () => $this->configuration()->verify($this->admin, $this->tenant));
        $this->assertHttpStatus(403, fn () => app(TenantAiKnowledge::class)->stores($this->admin, $this->tenant));
        Http::assertNothingSent();
    }

    public function test_training_gateway_rejects_foreign_vector_ids_before_http(): void
    {
        $this->enable($this->tenant);
        $this->store($this->tenant);
        $payload = array_replace($this->payload(), ['tools' => [['type' => 'file_search',
            'vector_store_ids' => ['vs_synthetic_foreign']]], 'tool_choice' => 'required']);
        $this->assertHttpStatus(403, fn () => app(TenantAiGateway::class)->respond($this->tenant, 'training', $payload));
        $this->assertSame(0, $this->configuration()->table('usage')->count());
        Http::assertSentCount(1);
    }

    public function test_upload_preserves_private_original_and_removal_only_detaches_remote_file(): void
    {
        $this->enable($this->tenant);
        $storage = app(TenantDocumentStorage::class);
        DB::connection('clubunio')->transaction(fn () => $storage->select($this->tenant, ['provider' => 'managed']));
        Http::fake([
            'https://api.openai.com/v1/vector_stores' => Http::response(['id' => 'vs_synthetic_upload']),
            'https://api.openai.com/v1/files' => Http::response(['id' => 'file-synthetic_upload']),
            'https://api.openai.com/v1/vector_stores/vs_synthetic_upload/files' => Http::response(['status' => 'completed']),
            'https://api.openai.com/v1/vector_stores/vs_synthetic_upload/files/file-synthetic_upload' => Http::response(['deleted' => true]),
        ]);
        $knowledge = app(TenantAiKnowledge::class);
        $knowledge->createStore($this->admin, $this->tenant, 'Synthetic upload');
        $store = $this->configuration()->table('stores')->sole();
        $bytes = 'Synthetic private knowledge original.';
        $knowledge->upload($this->admin, $this->tenant, $store->id,
            UploadedFile::fake()->createWithContent('synthetic.txt', $bytes));
        $document = $this->configuration()->table('documents')->sole();
        $this->assertSame('ready', $document->state);
        $path = TenantDocumentStorage::parse($document->folder);
        $this->assertSame('ai', $path['namespace']);
        $this->assertSame('41', $path['tenant']);
        $this->assertSame($bytes, $storage->read($this->tenant, $document->folder, $document->filename));
        $this->assertSame($bytes, $knowledge->download($this->admin, $this->tenant, $document->id)->getContent());
        $knowledge->remove($this->admin, $this->tenant, $document->id);
        $this->assertSame('archived', $this->configuration()->table('documents')->sole()->state);
        $this->assertSame($bytes, $storage->read($this->tenant, $document->folder, $document->filename));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.openai.com/v1/vector_stores/vs_synthetic_upload/files/file-synthetic_upload');
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_starts_with($r->url(), 'https://api.openai.com/v1/files/'));
    }

    public function test_invalid_remote_store_id_is_not_persisted_as_ready(): void
    {
        $this->enable($this->tenant);
        Http::fake(['https://api.openai.com/v1/vector_stores' => Http::response(['id' => 'vs_bad/path'])]);
        $this->assertHttpStatus(502, fn () => app(TenantAiKnowledge::class)->createStore($this->admin, $this->tenant, 'Synthetic invalid'));
        $store = $this->configuration()->table('stores')->sole();
        $this->assertSame('attention', $store->state);
        $this->assertNull($store->remote_id);
    }

    public function test_failed_removal_blocks_retrieval_and_refresh_retries_only_detach(): void
    {
        $this->enable($this->tenant);
        $store = $this->store($this->tenant);
        $id = $this->configuration()->table('documents')->insertGetId(['tenant_id' => 41, 'store_id' => $store,
            'name' => 'synthetic.txt', 'mime_type' => 'text/plain', 'size_bytes' => 4, 'sha256' => hash('sha256', 'test'),
            'remote_file_id' => 'file-synthetic_removal', 'state' => 'ready']);
        $url = 'https://api.openai.com/v1/vector_stores/vs_synthetic_own/files/file-synthetic_removal';
        Http::fake([$url => Http::sequence()->push([], 500)->push(['deleted' => true])]);
        $knowledge = app(TenantAiKnowledge::class);
        $revision = $this->configuration()->summary($this->tenant)['revision'];
        $this->assertHttpStatus(502, fn () => $knowledge->remove($this->admin, $this->tenant, $id));
        $this->assertSame('removing', $this->configuration()->table('documents')->sole()->state);
        $this->assertSame($revision + 1, $this->configuration()->summary($this->tenant)['revision']);
        $this->assertHttpStatus(503, fn () => $knowledge->trainingStores($this->tenant));
        $knowledge->refresh($this->admin, $this->tenant, $id);
        $this->assertSame('archived', $this->configuration()->table('documents')->sole()->state);
        $this->assertSame(['vs_synthetic_own'], $knowledge->trainingStores($this->tenant));
        Http::assertNotSent(fn ($r) => $r->url() === $url && $r->method() !== 'DELETE');
    }

    public function test_late_refresh_cannot_overwrite_removal_intent(): void
    {
        $this->enable($this->tenant);
        $store=$this->store($this->tenant);
        $id=$this->configuration()->table('documents')->insertGetId(['tenant_id'=>41,'store_id'=>$store,'name'=>'synthetic.txt',
            'mime_type'=>'text/plain','size_bytes'=>4,'sha256'=>hash('sha256','test'),'remote_file_id'=>'file-race','state'=>'ready']);
        $knowledge=app(TenantAiKnowledge::class);
        Http::fake(['https://api.openai.com/v1/vector_stores/vs_synthetic_own/files/file-race'=>function($request) use($knowledge,$id) {
            if ($request->method()==='DELETE') return Http::response([],500);
            $this->assertHttpStatus(502,fn()=>$knowledge->remove($this->admin,$this->tenant,$id));
            return Http::response(['status'=>'completed']);
        }]);
        $this->assertHttpStatus(409,fn()=>$knowledge->refresh($this->admin,$this->tenant,$id));
        $this->assertSame('removing',$this->configuration()->table('documents')->sole()->state);
        $this->assertHttpStatus(503,fn()=>$knowledge->trainingStores($this->tenant));
    }

    public function test_remove_during_file_upload_never_attaches_or_reopens_source(): void
    {
        $this->enable($this->tenant);
        DB::connection('clubunio')->transaction(fn()=>app(TenantDocumentStorage::class)->select($this->tenant,['provider'=>'managed']));
        $store=$this->store($this->tenant);
        $knowledge=app(TenantAiKnowledge::class);
        Http::fake(['https://api.openai.com/v1/files'=>function() use($knowledge) {
            $id=$this->configuration()->table('documents')->sole()->id;
            $knowledge->remove($this->admin,$this->tenant,$id);
            return Http::response(['id'=>'file-late']);
        }]);
        $this->assertHttpStatus(502,fn()=>$knowledge->upload($this->admin,$this->tenant,$store,UploadedFile::fake()->createWithContent('synthetic.txt','Synthetic private source')));
        $row=$this->configuration()->table('documents')->sole();
        $this->assertSame('archived',$row->state);
        $this->assertSame('file-late',$row->remote_file_id);
        $this->assertSame('attention',$this->configuration()->table('stores')->sole()->state);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'/vector_stores/') && $r->method()==='POST');
    }

    public function test_uncertain_attachment_keeps_store_quarantined_after_document_removal(): void
    {
        $this->enable($this->tenant);
        DB::connection('clubunio')->transaction(fn()=>app(TenantDocumentStorage::class)->select($this->tenant,['provider'=>'managed']));
        $store=$this->store($this->tenant);
        $knowledge=app(TenantAiKnowledge::class);
        Http::fake(['https://api.openai.com/v1/files'=>Http::response(['id'=>'file-uncertain']),
            'https://api.openai.com/v1/vector_stores/vs_synthetic_own/files'=>Http::response([],500),
            'https://api.openai.com/v1/vector_stores/vs_synthetic_own/files/file-uncertain'=>Http::response(['deleted'=>true])]);
        $this->assertHttpStatus(502,fn()=>$knowledge->upload($this->admin,$this->tenant,$store,UploadedFile::fake()->createWithContent('synthetic.txt','Synthetic source')));
        $id=$this->configuration()->table('documents')->sole()->id;
        $knowledge->remove($this->admin,$this->tenant,$id);
        $this->assertSame('attention',$this->configuration()->table('stores')->sole()->state);
        $this->assertHttpStatus(503,fn()=>$knowledge->trainingStores($this->tenant));
    }
}
