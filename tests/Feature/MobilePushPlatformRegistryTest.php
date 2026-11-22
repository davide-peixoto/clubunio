<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobilePushRegistry;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MobilePushPlatformRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['clubunio-mobile-push.enabled' => true]);
        $this->app->instance(TenantDomainService::class, new class extends TenantDomainService {
            public function resolve(Request $request): ?Tenant { return null; }
        });
    }

    private function entry(string $platform): array
    {
        return ['platform' => $platform, 'origin' => 'https://app.example.invalid',
            'fixed_tenant_id' => null, 'project_id' => 'synthetic-project', 'credentials_file' => '/private/synthetic.json'];
    }

    public function test_platform_keys_support_same_native_id_and_preserve_legacy_fallback(): void
    {
        config(['clubunio-mobile-push.apps' => ['com.example.core' => $this->entry('ios'),
            'android:com.example.core' => $this->entry('android')]]);
        foreach (['ios', 'android'] as $platform) {
            $this->assertSame($platform, (new MobilePushRegistry)->app('com.example.core', $platform, 'https://app.example.invalid')['platform']);
        }
        config(['clubunio-mobile-push.apps' => ['ios:com.example.core' => $this->entry('ios'),
            'android:com.example.core' => $this->entry('android')]]);
        foreach (['ios', 'android'] as $platform) {
            $this->assertSame($platform, (new MobilePushRegistry)->app('com.example.core', $platform, 'https://app.example.invalid')['platform']);
        }
    }

    public function test_internal_prefix_wrong_platform_and_foreign_origin_are_rejected(): void
    {
        config(['clubunio-mobile-push.apps' => ['ios:com.example.core' => $this->entry('ios')]]);
        foreach ([['ios:com.example.core', 'ios', 'https://app.example.invalid'],
            ['com.example.core', 'android', 'https://app.example.invalid'],
            ['com.example.core', 'ios', 'https://foreign.example.invalid']] as [$id, $platform, $origin]) {
            try {
                (new MobilePushRegistry)->app($id, $platform, $origin);
                $this->fail('Invalid scope accepted');
            } catch (HttpExceptionInterface $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
    }

    public function test_custom_build_requires_current_tenant_domain_binding(): void
    {
        $entry = $this->entry('ios'); $entry['fixed_tenant_id'] = 4;
        config(['clubunio-mobile-push.apps' => ['ios:com.example.club' => $entry]]);
        $this->expectException(HttpExceptionInterface::class);
        (new MobilePushRegistry)->app('com.example.club', 'ios', 'https://app.example.invalid');
    }

    public function test_config_accepts_both_platforms_and_rejects_mismatched_key_prefix(): void
    {
        $env = \Illuminate\Support\Env::getRepository();
        $before = $env->get('CLUBUNIO_MOBILE_PUSH_APPS');
        try {
            $env->set('CLUBUNIO_MOBILE_PUSH_APPS', json_encode(['ios:com.example.core' => $this->entry('ios'),
                'android:com.example.core' => $this->entry('android')]));
            $this->assertCount(2, (require config_path('clubunio-mobile-push.php'))['apps']);
            $env->set('CLUBUNIO_MOBILE_PUSH_APPS', json_encode(['ios:com.example.core' => $this->entry('android')]));
            $this->assertSame([], (require config_path('clubunio-mobile-push.php'))['apps']);
        } finally {
            if ($before === null) $env->clear('CLUBUNIO_MOBILE_PUSH_APPS');
            else $env->set('CLUBUNIO_MOBILE_PUSH_APPS', $before);
        }
    }

    public function test_capability_resolves_platform_prefix_and_still_requires_feature_and_origin(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/php/extras/ssl/openssl.cnf')) $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        $file = tempnam(sys_get_temp_dir(), 'synthetic-push-');
        try {
            file_put_contents($file, json_encode(['type' => 'service_account', 'project_id' => 'synthetic-project',
                'client_email' => 'synthetic@synthetic-project.iam.gserviceaccount.com',
                'token_uri' => 'https://oauth2.googleapis.com/token', 'private_key' => $pem]));
            $entry = $this->entry('android'); $entry['credentials_file'] = $file;
            config(['clubunio-mobile-push.delivery_enabled' => true,
                'clubunio-mobile-push.apps' => ['android:com.example.core' => $entry]]);
            $ready = new \App\ClubUnio\Mobile\MobilePushReady;
            $this->assertFalse($ready->available(Request::create('https://app.example.invalid')));
            $headers = ['HTTP_X_CLUBUNIO_MOBILE_FEATURES' => 'personal-push-v1'];
            $this->assertTrue($ready->available(Request::create('https://app.example.invalid', 'GET', [], [], [], $headers)));
            $this->assertFalse($ready->available(Request::create('https://foreign.example.invalid', 'GET', [], [], [], $headers)));
        } finally { unlink($file); }
    }
}
