<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\MobileSponsorShareIdentity;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MobileSponsorShareIdentityTest extends TestCase
{
    private Tenant $tenant;
    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new Tenant; $this->tenant->forceFill(['id'=>501,'slug'=>'synthetic','name'=>'Synthetic tenant']);
        // Domain verification itself is the existing service contract; this spy
        // proves the native helper always calls it rather than trusting raw Host.
        $this->app->instance(TenantDomainService::class,new class($this->tenant) extends TenantDomainService {
            public function __construct(private Tenant $tenant) {}
            public function resolve(Request $request): ?Tenant {
                abort_unless(in_array($request->getHost(),['platform.example','custom.example'],true),404);
                return $request->getHost()==='custom.example' ? $this->tenant : null;
            }
        });
    }
    private function link(array $changes=[]): object
    {
        return (object)array_replace(['tenant_id'=>501,'revoked_at'=>null,'expires_at'=>now()->addHour()->toIso8601String(),
            'token_encrypted'=>Crypt::encryptString(str_repeat('a',64)),'token_hash'=>hash('sha256',str_repeat('a',64))],$changes);
    }
    public function test_platform_and_verified_custom_origin_use_the_same_real_token_and_fixed_public_route(): void
    {
        foreach (['platform.example','custom.example','custom.example:8443'] as $host) {
            $url=app(MobileSponsorShareIdentity::class)->url(Request::create('https://'.$host.'/api/mobile/v1/clubs/501/share'),$this->tenant,$this->link(),true);
            $this->assertSame('https://'.$host.'/sponsor-run#'.str_repeat('a',64),$url);
        }
        $this->assertNull(app(MobileSponsorShareIdentity::class)->url(Request::create('https://platform.example'),$this->tenant,null,false));
    }
    public function test_changed_source_token_hash_tenant_expiry_and_revocation_close_export(): void
    {
        foreach ([['tenant_id'=>502],['revoked_at'=>now()->toIso8601String()],['expires_at'=>now()->subSecond()->toIso8601String()],
            ['token_encrypted'=>Crypt::encryptString('invalid')],['token_hash'=>str_repeat('b',64)]] as $changes) {
            try {
                app(MobileSponsorShareIdentity::class)->url(Request::create('https://custom.example'),$this->tenant,$this->link($changes),true);
                $this->fail('Invalid source must never export');
            } catch (HttpExceptionInterface $error) { $this->assertSame(409,$error->getStatusCode()); }
        }
    }
    public function test_unverified_host_http_and_other_verified_tenant_never_become_export_origins(): void
    {
        foreach (['https://foreign.example','http://platform.example'] as $url) {
            try {
                app(MobileSponsorShareIdentity::class)->url(Request::create($url),$this->tenant,$this->link(),true);
                $this->fail('Invalid origin must never export');
            } catch (HttpExceptionInterface $error) { $this->assertContains($error->getStatusCode(),[400,404]); }
        }
        $foreign=new Tenant; $foreign->forceFill(['id'=>502]);
        try {
            app(MobileSponsorShareIdentity::class)->url(Request::create('https://custom.example'),$foreign,$this->link(['tenant_id'=>502]),true);
            $this->fail('Wrong verified tenant must never export');
        } catch (HttpExceptionInterface $error) { $this->assertSame(404,$error->getStatusCode()); }
    }
}
