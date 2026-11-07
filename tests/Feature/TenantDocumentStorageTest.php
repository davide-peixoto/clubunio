<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\RegistrationBlobStore;
use App\ClubUnio\Services\Storage\S3DocumentProvider;
use App\ClubUnio\Services\TenantDocumentStorage;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result as AwsResult;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantDocumentStorageTest extends TestCase
{
    private const FILE = '8e48608d-1b48-4286-a957-3824947be97a.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'clubunio-storage.profiles_enabled' => true, 'clubunio-storage.managed_enabled' => true,
            'clubunio-storage.s3_enabled' => true, 'clubunio-storage.google_enabled' => true, 'clubunio-storage.graph_enabled' => true,
            'clubunio-integrations.azure.tenant_slug' => 'legacy']);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.tenant_storage_profiles (id TEXT PRIMARY KEY, tenant_id INTEGER, provider TEXT,
            account TEXT, container TEXT, encrypted_key TEXT, active INTEGER, UNIQUE (tenant_id, provider, account, container))');
        $db->statement("CREATE UNIQUE INDEX clubora.remote_target ON tenant_storage_profiles (provider, container) WHERE provider IN ('s3','google','graph')");
        $db->statement('CREATE TABLE clubora.tenant_storage_objects (profile_id TEXT, object_key TEXT, remote_id TEXT,
            PRIMARY KEY (profile_id, object_key), UNIQUE (profile_id, remote_id))');
        Storage::fake('local');
    }

    private function tenant(int $id, string $slug = 'fixture'): Tenant
    {
        return (new Tenant)->forceFill(['id' => $id, 'slug' => $slug]);
    }

    private function select(Tenant $tenant, array $data): array
    {
        return DB::connection('clubunio')->transaction(fn () => app(TenantDocumentStorage::class)->select($tenant, $data));
    }

    private function azure(string $account = 'fixtureaccount', ?string $key = null): array
    {
        return ['provider' => 'azure', 'storage_account' => $account, 'storage_container' => 'fixture-private',
            'storage_key' => $key ?? base64_encode(str_repeat('x', 64))];
    }

    private function mockAzure(array $responses, array &$history): void
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);
        $this->app->instance(RegistrationBlobStore::class, new RegistrationBlobStore($client));
        $this->app->instance(AzureDocumentReader::class, new AzureDocumentReader($client));
    }

    public function test_profile_switch_and_rotation_preserve_published_references_and_encrypt_key(): void
    {
        $tenant = $this->tenant(41);
        $storage = app(TenantDocumentStorage::class);
        $this->assertFalse($storage->canUpload($tenant));
        $managed = $this->select($tenant, ['provider' => 'managed']);
        $local = $storage->folder($tenant, 'expenses', 17);
        $storage->put($tenant, $local, self::FILE, '%PDF-1.4 local', 'application/pdf');
        $history = [];
        $this->mockAzure([new Response(200), new Response(200), new Response(201), new Response(200),
            new Response(200, [], '%PDF-1.4 azure')], $history);
        $azure = $this->select($tenant, $this->azure());
        $remote = $storage->folder($tenant, 'expenses', 17);
        $this->assertNotSame($local, $remote);
        $storage->put($tenant, $remote, self::FILE, '%PDF-1.4 azure', 'application/pdf');
        $rotation = $this->select($tenant, $this->azure(key: base64_encode(str_repeat('y', 64))));
        $this->assertSame($azure, $rotation);
        $this->assertSame($managed, $this->select($tenant, ['provider' => 'managed']));
        config(['clubunio-storage.profiles_enabled' => false, 'clubunio-storage.managed_enabled' => false]);
        $this->assertSame('%PDF-1.4 local', $storage->readExpense($tenant, $local.self::FILE));
        $this->assertSame('%PDF-1.4 azure', $storage->readExpense($tenant, $remote.self::FILE));
        $this->assertSame(['HEAD', 'HEAD', 'PUT', 'HEAD', 'GET'], array_map(fn ($item) => $item['request']->getMethod(), $history));
        $this->assertSame('fixtureaccount.blob.core.windows.net', $history[4]['request']->getUri()->getHost());
        $this->assertSame('/fixture-private/'.$remote.self::FILE, $history[4]['request']->getUri()->getPath());
        $encrypted = DB::connection('clubunio')->table('clubora.tenant_storage_profiles')->where('id', $azure['profile_id'])->value('encrypted_key');
        $this->assertNotSame(base64_encode(str_repeat('y', 64)), $encrypted);
    }

    public function test_cross_tenant_profile_and_expense_object_references_fail_closed(): void
    {
        $tenant = $this->tenant(41);
        $this->select($tenant, ['provider' => 'managed']);
        $storage = app(TenantDocumentStorage::class);
        $folder = $storage->folder($tenant, 'expenses', 17);
        $foreign = str_replace('/expenses/41/', '/expenses/42/', $folder);
        foreach ([fn () => $storage->read($this->tenant(42), $folder, self::FILE),
            fn () => $storage->read($this->tenant(42), $foreign, self::FILE),
            fn () => $storage->expensePath($tenant, $folder.self::FILE, 18),
            fn () => $storage->expensePath($tenant, $folder.'../'.self::FILE, 17),
            fn () => $storage->read($tenant, 'upload/anmeldungen/', 'legacy.pdf')] as $action) {
            try {
                $action();
                $this->fail('Expected isolated storage');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
    }

    public function test_public_azure_container_does_not_receive_document(): void
    {
        $tenant = $this->tenant(41);
        $history = [];
        $this->mockAzure([new Response(200), new Response(200, ['x-ms-blob-public-access' => 'blob']),
            new Response(200, ['x-ms-blob-public-access' => 'blob'])], $history);
        $this->select($tenant, $this->azure());
        $storage = app(TenantDocumentStorage::class);
        try {
            $storage->put($tenant, $storage->folder($tenant, 'expenses', 17), self::FILE, '%PDF-1.4', 'application/pdf');
            $this->fail('Public target accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('Private document upload unavailable', $error->getMessage());
        }
        try {
            $this->select($tenant, $this->azure('secondfixture'));
            $this->fail('Public target activated');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('storage_key', $error->errors());
        }
        $this->assertSame('fixtureaccount', $storage->active($tenant)->account);
        $this->assertSame(['HEAD', 'HEAD', 'HEAD'], array_map(fn ($item) => $item['request']->getMethod(), $history));
    }

    public function test_managed_disk_cannot_overwrite_and_flag_blocks_writes_not_reads(): void
    {
        $tenant = $this->tenant(41);
        $this->select($tenant, ['provider' => 'managed']);
        $storage = app(TenantDocumentStorage::class);
        $folder = $storage->folder($tenant, 'expenses', 17);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 original', 'application/pdf');
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 original', 'application/pdf');
        try {
            $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 different', 'application/pdf');
            $this->fail('Overwrite accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
        }
        config(['clubunio-storage.managed_enabled' => false]);
        $this->assertFalse($storage->canUpload($tenant));
        $this->assertSame('%PDF-1.4 original', $storage->read($tenant, $folder, self::FILE));
    }

    private function s3(): array
    {
        return ['provider' => 's3', 'storage_account' => 'eu-central-2', 'storage_owner' => '123456789012',
            'storage_container' => 'clubunio-fixture-bucket', 'storage_key' => json_encode([
                'access_key_id' => str_repeat('A', 20), 'secret_access_key' => str_repeat('b', 40),
            ])];
    }

    public function test_s3_checks_bucket_owner_and_privacy_and_writes_conditionally(): void
    {
        $private = new AwsResult(['PublicAccessBlockConfiguration' => array_fill_keys([
            'BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets',
        ], true)]);
        $policy = new AwsResult(['PolicyStatus' => ['IsPublic' => false]]);
        $history = [];
        $conflict = new AwsException('Synthetic conflict', new Command('PutObject'), ['code' => 'PreconditionFailed', 'response' => new Response(412)]);
        $body = fn () => new AwsResult(['Body' => Utils::streamFor('%PDF-1.4 remote')]);
        $mock = new AwsMockHandler([$private, $policy, $private, $policy, new AwsResult,
            $body(), $private, $policy, $conflict, $body(), $private, $policy, $conflict, $body(), $private, $policy,
            $private, $policy, new AwsResult(['PublicAccessBlockConfiguration' => ['BlockPublicAcls' => true]])]);
        $client = new S3Client(['version' => 'latest', 'region' => 'eu-central-2', 'credentials' => false,
            'handler' => function ($cmd, $req) use (&$history, $mock) {
                $history[] = [$cmd->getName(), $cmd['ExpectedBucketOwner'], $cmd['Key'], $cmd['IfNoneMatch']];

                return $mock($cmd, $req);
            }]);
        $this->app->instance(S3DocumentProvider::class, new class($client) extends S3DocumentProvider
        {
            public function __construct(private S3Client $fixture) {}

            protected function client(object $profile, array $credentials): S3Client
            {
                return $this->fixture;
            }
        });
        $tenant = $this->tenant(41);
        $s3 = $this->select($tenant, $this->s3());
        $storage = app(TenantDocumentStorage::class);
        $folder = $storage->folder($tenant, 'expenses', 17);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 remote', 'application/pdf');
        $this->assertSame('%PDF-1.4 remote', $storage->readExpense($tenant, $folder.self::FILE));
        $this->assertSame(['PutObject', '123456789012', $folder.self::FILE, '*'], $history[4]);
        $this->assertSame(['GetObject', '123456789012', $folder.self::FILE, null], $history[5]);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 remote', 'application/pdf');
        try {
            $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 different', 'application/pdf');
            $this->fail('Conflicting upload succeeded');
        } catch (\RuntimeException $error) {
            $this->assertSame(409, $error->getCode());
        }
        $this->select($tenant, ['provider' => 'managed']);
        $rotation = $this->select($tenant, ['storage_action' => 'rotate'] + $this->s3());
        $this->assertSame($s3['profile_id'], $rotation['profile_id']);
        $this->assertSame('managed', $storage->active($tenant)->provider);
        try {
            $this->select($this->tenant(42), $this->s3());
            $this->fail('Bucket assigned to a second tenant');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('storage_container', $error->errors());
        }
        $this->assertFalse($storage->canUpload($this->tenant(42)));
        $this->expectExceptionCode(503);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 other', 'application/pdf');
    }

    public function test_google_reserves_id_before_upload_and_conflicts_reuse_only_matching_bytes(): void
    {
        Http::preventStrayRequests();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $windowsConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $rsa = openssl_pkey_new($options);
        $this->assertNotFalse($rsa);
        $this->assertTrue(openssl_pkey_export($rsa, $pem, null, $options));
        $input = ['provider' => 'google', 'storage_container' => 'googleFixture', 'storage_key' => json_encode([
            'type' => 'service_account', 'client_email' => 'fixture@example.test', 'private_key' => $pem, 'private_key_id' => 'key-fixture',
            'token_uri' => 'https://disallowed.test/token',
        ])];
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-google', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/drives/googleFixture*' => Http::response(['id' => 'googleFixture',
                'restrictions' => ['driveMembersOnly' => true], 'capabilities' => ['canAddChildren' => true]]),
            'www.googleapis.com/drive/v3/files/generateIds*' => Http::response(['ids' => ['googleFileId']]),
            'www.googleapis.com/upload/drive/v3/files?uploadType=*' => Http::sequence()
                ->push('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=fixture'])->push('', 409)->push('', 409),
            'www.googleapis.com/upload/drive/v3/files?upload_id=*' => Http::response(['id' => 'googleFileId', 'driveId' => 'googleFixture']),
            'www.googleapis.com/drive/v3/files/googleFileId?alt=*' => fn () => Http::response('%PDF-1.4 original'),
            'www.googleapis.com/drive/v3/files/googleFileId?fields=*' => Http::response(['id' => 'googleFileId', 'driveId' => 'googleFixture']),
        ]);
        $tenant = $this->tenant(41);
        $profile = $this->select($tenant, $input);
        $storage = app(TenantDocumentStorage::class);
        $folder = $storage->folder($tenant, 'expenses', 17);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 original', 'application/pdf');
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 original', 'application/pdf');
        $this->assertSame('googleFileId', DB::connection('clubunio')->table('clubora.tenant_storage_objects')
            ->where('profile_id', $profile['profile_id'])->sole()->remote_id);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'generateIds')));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')
            && $request['id'] === 'googleFileId' && $request['parents'] === ['googleFixture']);
        $this->expectExceptionCode(409);
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 different', 'application/pdf');
    }

    public function test_graph_uses_scoped_drive_ids_and_does_not_send_oauth_to_transfer_hosts(): void
    {
        Http::preventStrayRequests();
        $tenant = $this->tenant(41);
        $input = ['provider' => 'graph', 'storage_account' => 'fd155a0e-9a99-462b-ad08-af320777f566',
            'storage_container' => 'b!graphFixture', 'storage_private_confirmed' => '1',
            'storage_key' => '{"client_id":"b067ad10-a0de-4d55-87a1-f7ed26e2640c","client_secret":"synthetic-secret"}'];
        $itemId = 'itemFixture';
        $contentUrl = 'https://fixture.sharepoint.com/download?key=fixture';
        $uploadUrl = 'https://fixture.sharepoint.com/upload?key=fixture';
        $item = [];
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-graph']),
            'graph.microsoft.com/v1.0/drives/b%21graphFixture?*' => Http::response(['id' => 'b!graphFixture', 'driveType' => 'documentLibrary']),
            'graph.microsoft.com/v1.0/drives/*/root:/*:/createUploadSession' => Http::sequence()
                ->push(['uploadUrl' => $uploadUrl])->push(['uploadUrl' => 'https://fixture.sharepoint.com@blocked.test/path']),
            'fixture.sharepoint.com/upload?*' => function () use (&$item) {
                return Http::response($item, 201);
            },
            'graph.microsoft.com/v1.0/drives/*/items/itemFixture/content' => Http::response('', 302, ['Location' => $contentUrl]),
            'fixture.sharepoint.com/download?*' => fn () => Http::response('%PDF-1.4 graph'),
        ]);
        $this->select($tenant, $input);
        $storage = app(TenantDocumentStorage::class);
        $folder = $storage->folder($tenant, 'members', 11);
        $item = ['id' => $itemId, 'name' => hash('sha256', $folder.self::FILE).'.pdf',
            'file' => ['mimeType' => 'application/pdf'], 'parentReference' => ['driveId' => 'b!graphFixture']];
        $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 graph', 'application/pdf');
        $this->assertSame('%PDF-1.4 graph', $storage->read($tenant, $folder, self::FILE));
        Http::assertSent(fn ($request) => $request->url() === $uploadUrl && $request->method() === 'PUT'
            && ! $request->hasHeader('Authorization') && $request->hasHeader('Content-Range', 'bytes 0-13/14'));
        Http::assertSent(fn ($request) => $request->url() === $contentUrl && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/createUploadSession')
            && $request['item']['@microsoft.graph.conflictBehavior'] === 'fail');
        try {
            $storage->put($tenant, $folder, self::FILE, '%PDF-1.4 graph', 'application/pdf');
            $this->fail('Untrusted transfer URL accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'blocked.test'));
        config(['clubunio-storage.graph_enabled' => false]);
        $this->assertFalse($storage->canUpload($tenant));
        $this->assertSame('%PDF-1.4 graph', $storage->read($tenant, $folder, self::FILE));
    }

    public function test_graph_rejects_private_confirmation_or_invalid_credentials_without_switching(): void
    {
        Http::preventStrayRequests();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'synthetic-secret'], 401)]);
        $tenant = $this->tenant(41);
        $this->select($tenant, ['provider' => 'managed']);
        $input = ['provider' => 'graph', 'storage_account' => 'fd155a0e-9a99-462b-ad08-af320777f566',
            'storage_container' => 'b!graphFixture', 'storage_key' => '{"client_id":"b067ad10-a0de-4d55-87a1-f7ed26e2640c","client_secret":"synthetic-secret"}'];
        foreach ([$input, $input + ['storage_private_confirmed' => '1']] as $request) {
            try {
                $this->select($tenant, $request);
                $this->fail('Invalid Graph profile activated');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(isset($request['storage_private_confirmed']) ? 'storage_key' : 'storage_private_confirmed', $error->errors());
                $this->assertStringNotContainsString('synthetic-secret', $error->getMessage());
            }
        }
        Http::assertSentCount(1);
        $this->assertSame('managed', app(TenantDocumentStorage::class)->active($tenant)->provider);
    }

    public function test_platform_renders_provider_forms_in_both_languages_without_secret_values(): void
    {
        $tenant = $this->tenant(41);
        $tenant->forceFill(['name' => 'Synthetic tenant', 'platform_revision' => 1]);
        $variables = ['tenant' => $tenant, 'brand' => ['color' => '#123456', 'ink' => '#ffffff', 'title' => 'Test', 'logo' => '/logo.png'],
            'errors' => new ViewErrorBag, 'storageProfile' => null, 'storageDestinations' => []];
        foreach (['domains', 'accounts', 'roles', 'profiles', 'changes'] as $key) {
            $variables[$key] = collect();
        }
        foreach (['de' => 'Nur Zugangsdaten aktualisieren', 'en' => 'Update credentials only'] as $locale => $label) {
            app()->setLocale($locale);
            $html = view('clubunio.platform.show', $variables)->render();
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('name="provider" value="s3"', $html);
            $this->assertStringContainsString('name="provider" value="google"', $html);
            $this->assertStringContainsString('name="provider" value="graph"', $html);
            $this->assertDoesNotMatchRegularExpression('/name="storage_key"[^>]*value=/', $html);
        }
    }
}
