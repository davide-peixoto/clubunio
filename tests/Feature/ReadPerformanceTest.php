<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioSandbox;
use App\ClubUnio\Pages\SettingsOverview;
use App\ClubUnio\Pages\Synchronisation;
use App\ClubUnio\Resources\BexioCatalogEntryResource;
use App\ClubUnio\Resources\MemberResource\Pages\ListMembers;
use App\ClubUnio\Services\BackofficeHandleComponents;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\MemberAccessService;
use App\Livewire\ClubUnio\SignageFeeds;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_signage_feed_presentation_reuses_only_verified_read_tenant(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $component = new SignageFeeds;
        $this->during('GET', function () use ($component) {
            $this->db->enableQueryLog();
            $this->db->flushQueryLog();
            $component->mount($this->tenant->id);
            $component->render();
            $component->render();
            $this->assertCount(0, $this->db->getQueryLog());
            $this->db->disableQueryLog();
        });
        $this->revoke();
        try {
            $component->render();
            $this->fail('Revoked role rendered feed');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_read_page_and_backoffice_entry_reuse_existing_context_only(): void
    {
        $this->during('GET', function () {
            $this->db->enableQueryLog();
            $this->db->flushQueryLog();
            for ($i = 0; $i < 20; $i++) {
                $this->assertTrue(app(BackofficeReadContext::class)->allowsReadPage($this->actor, $this->tenant));
                $this->assertFalse(app(BackofficeReadContext::class)->allowsReadPage($this->actor, $this->tenant, adminOnly: true));
                $this->assertTrue(app(MemberAccessService::class)->canEnter($this->actor, $this->tenant));
            }
            $this->assertCount(0, $this->db->getQueryLog());
            $this->db->disableQueryLog();
        });
        $this->revoke();
        $this->assertFalse(app(BackofficeReadContext::class)->allowsReadPage($this->actor, $this->tenant));
        $this->assertFalse(app(MemberAccessService::class)->canEnter($this->actor, $this->tenant));
    }

    public function test_admin_page_is_tenant_scoped_and_post_revocation_is_immediate(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'admin']);
        $foreign = Tenant::create(['slug' => 'read-admin-'.Str::random(10), 'name' => 'Other']);
        $this->during('GET', function () use ($foreign) {
            $context = app(BackofficeReadContext::class);
            $this->assertTrue($context->allowsReadPage($this->actor, $this->tenant, adminOnly: true));
            $this->assertFalse($context->allowsReadPage($this->actor, $foreign, adminOnly: true));
            $disabled = clone $this->actor;
            $disabled->active = false;
            $this->assertFalse($context->allowsReadPage($disabled, $this->tenant));
            $this->assertFalse($context->allowsReadPage(null, $this->tenant));
        });
        $this->during('POST', function () {
            $context = app(BackofficeReadContext::class);
            $this->assertTrue($context->allowsReadPage($this->actor, $this->tenant, adminOnly: true));
            $this->revoke();
            $this->assertFalse($context->allowsReadPage($this->actor, $this->tenant, adminOnly: true));
            $this->assertFalse(app(BexioConnectionService::class)::canManage($this->actor->fresh(), $this->tenant));
            $this->assertFalse(app(MemberAccessService::class)->canEnter($this->actor, $this->tenant));
        });
        $this->during('GET', function () {
            $this->assertFalse(app(BackofficeReadContext::class)->allowsReadPage($this->actor, $this->tenant, adminOnly: true));
        });
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

    public function test_admin_in_another_tenant_does_not_unlock_current_office_pages(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $foreign = Tenant::create(['slug' => 'read-other-admin-'.Str::random(8), 'name' => 'Other']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $foreign->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->during('GET', function () use ($foreign) {
            $this->assertTrue(SettingsOverview::canAccess());
            $this->assertFalse(Synchronisation::canAccess());
            $this->assertFalse(BexioSandbox::canAccess());
            $this->assertFalse(BexioCatalogEntryResource::canViewAny());
            Filament::setTenant($foreign);
            try {
                $this->assertTrue(Synchronisation::canAccess());
                $this->assertTrue(BexioCatalogEntryResource::canViewAny());
            } finally {
                Filament::setTenant($this->tenant);
            }
        });
        $this->revoke();
        $this->assertFalse(SettingsOverview::canAccess());
        $this->assertFalse(Synchronisation::canAccess());
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

    public function test_post_render_is_bounded_and_nested_mutations_remain_fresh(): void
    {
        $this->during('POST', function () {
            $context = app(BackofficeReadContext::class);
            $context->duringRender(function () use ($context) {
                $this->db->enableQueryLog();
                $this->db->flushQueryLog();
                for ($i = 0; $i < 50; $i++) {
                    $this->assertTrue($this->actor->canAccessTenant($this->tenant));
                }
                $this->assertCount(0, $this->db->getQueryLog());
                $this->db->disableQueryLog();
                $context->withoutSnapshot(function () {
                    $this->revoke();
                    $this->assertFalse($this->actor->canAccessTenant($this->tenant));
                });
                try {
                    $context->duringRender(function () {
                        $this->assertFalse($this->actor->canAccessTenant($this->tenant));
                        throw new \RuntimeException('nested');
                    });
                } catch (\RuntimeException $error) {
                    $this->assertSame('nested', $error->getMessage());
                }
            });
            $this->assertFalse(request()->attributes->has(BackofficeReadContext::class));
            $this->assertFalse($this->actor->canAccessTenant($this->tenant));
        });
    }

    public function test_livewire_render_exception_does_not_leak_snapshot(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $handler = app(HandleComponents::class);
        $this->assertInstanceOf(BackofficeHandleComponents::class, $handler);
        $component = new class extends Component
        {
            public function render()
            {
                throw new \RuntimeException('render failure');
            }
        };
        $this->during('POST', function () use ($handler, $component) {
            try {
                (new \ReflectionMethod($handler, 'render'))->invoke($handler, $component);
                $this->fail('Expected render exception');
            } catch (\RuntimeException $error) {
                $this->assertSame('render failure', $error->getMessage());
            }
            $this->assertFalse(request()->attributes->has(BackofficeReadContext::class));
            $this->revoke();
            $this->assertFalse($this->actor->canAccessTenant($this->tenant));
        });
    }

    public function test_filter_and_search_keep_tenant_scope_and_reject_revoked_role(): void
    {
        $filament = Filament::class;
        $filament::setCurrentPanel($filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        $filament::setTenant($this->tenant);
        $status = MemberStatus::forceCreate(['tenant_id' => $this->tenant->id, 'name' => 'Fixture', 'code' => 'fixture']);
        $member = Member::forceCreate(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Search', 'status_id' => $status->id]);
        $other = Member::forceCreate(['tenant_id' => $this->tenant->id, 'first_name' => 'Excluded', 'last_name' => 'Search']);
        $foreign = Tenant::create(['slug' => 'search-'.Str::random(12), 'name' => 'Other']);
        $foreignMember = Member::forceCreate(['tenant_id' => $foreign->id, 'first_name' => 'Synthetic', 'last_name' => 'Search']);
        $page = Livewire::test(ListMembers::class)
            ->filterTable('status_id', $status->id)
            ->searchTable('Synthetic')
            ->assertCanSeeTableRecords([$member])
            ->assertCanNotSeeTableRecords([$other, $foreignMember]);
        $this->assertEquals($status->id, $page->get('tableFilters')['status_id']['value']);
        $this->assertFalse(request()->attributes->has(BackofficeReadContext::class));
        $this->revoke();
        $page->searchTable('Search')->assertForbidden();
    }

    public function test_livewire_action_runs_outside_render_context(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Livewire::test(PermissionRenderProbe::class)
            ->call('revokeAndCheck')
            ->assertSet('freshlyDenied', true);
        $this->assertFalse($this->actor->canAccessTenant($this->tenant));
        $this->assertFalse(request()->attributes->has(BackofficeReadContext::class));
    }
}

class PermissionRenderProbe extends Component
{
    public bool $freshlyDenied = false;

    public function revokeAndCheck(): void
    {
        $actor = auth('clubunio')->user();
        abort_if(request()->attributes->has(BackofficeReadContext::class), 409);
        DB::connection('clubunio')->table('clubora.tenant_accounts')->where('account_id', $actor->id)->update(['role' => 'trainer']);
        $this->freshlyDenied = ! $actor->canAccessTenant(Filament::getTenant());
    }

    public function render()
    {
        if (request()->isMethod('POST')) {
            abort_unless(request()->attributes->has(BackofficeReadContext::class), 409);
        }

        return '<div>{{ auth("clubunio")->user()->hasBackofficeTenant() ? "allowed" : "denied" }}</div>';
    }
}
