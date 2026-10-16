<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadPerformanceTest extends TestCase
{
    private $db;

    private Account $actor;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional database opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'read-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'office']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function during(string $method, \Closure $next): mixed
    {
        $request = Request::create('/members', $method);
        $previous = app('request');
        app()->instance('request', $request);
        $request->setUserResolver(fn ($guard = null) => $this->actor);
        try {
            return app(BackofficeReadContext::class)->during($request, $next);
        } finally {
            $this->assertFalse($request->attributes->has(BackofficeReadContext::class));
            app()->instance('request', $previous);
        }
    }

    private function revoke(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
    }

    public function test_repeated_get_checks_do_not_query_and_next_request_sees_revocation(): void
    {
        $this->during('GET', function () {
            $this->db->enableQueryLog();
            $this->db->flushQueryLog();
            for ($i = 0; $i < 50; $i++) {
                $this->assertTrue($this->actor->canAccessTenant($this->tenant));
                $this->assertTrue($this->actor->hasBackofficeTenant());
            }
            $this->assertCount(0, $this->db->getQueryLog());
            $this->db->disableQueryLog();
        });
        $this->revoke();
        $this->assertFalse($this->actor->canAccessTenant($this->tenant));
        $this->during('GET', function () {
            $this->assertFalse($this->actor->canAccessTenant($this->tenant));
            $this->assertFalse($this->actor->hasBackofficeTenant());
        });
    }

    public function test_post_checks_stay_fresh_even_within_the_same_request(): void
    {
        $this->during('POST', function () {
            $this->assertTrue($this->actor->canAccessTenant($this->tenant));
            $this->revoke();
            $this->assertFalse($this->actor->canAccessTenant($this->tenant));
            $this->assertFalse($this->actor->hasBackofficeTenant());
        });
    }

    public function test_snapshot_does_not_authorize_another_account_or_tenant(): void
    {
        $other = new Account;
        $other->forceFill(['username' => Str::random(20), 'display_name' => 'Other', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Other']);
        $this->during('GET', function () use ($other, $foreign) {
            $this->assertFalse($other->canAccessTenant($this->tenant));
            $this->assertFalse($this->actor->canAccessTenant($foreign));
            $disabled = clone $this->actor;
            $disabled->active = false;
            $this->assertFalse($disabled->canAccessTenant($this->tenant));
        });
    }

    public function test_exception_also_removes_read_context(): void
    {
        try {
            $this->during('GET', function () {
                throw new \RuntimeException('synthetic');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic', $error->getMessage());
        }
        $this->revoke();
        $this->assertFalse($this->actor->canAccessTenant($this->tenant));
    }
}
