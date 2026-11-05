<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\DashboardReservationRequests;
use Illuminate\Support\Facades\{DB, Http};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;

class DashboardReservationRequestsTest extends TestCase
{
    private Account $actor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.accounts (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, active BOOLEAN, api_only BOOLEAN, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE clubora.tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, active BOOLEAN, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE clubora.tenant_accounts (tenant_id INTEGER, account_id INTEGER, role TEXT)');
        $db->statement('CREATE TABLE clubora.bus_reservations (id INTEGER PRIMARY KEY, tenant_id INTEGER, starts_at TEXT, location TEXT, status TEXT, active BOOLEAN)');
        $db->statement('CREATE TABLE clubora.pitch_requests (id INTEGER PRIMARY KEY, tenant_id INTEGER, date TEXT, starts_at TEXT, opponent TEXT, status TEXT)');
        $this->actor = Account::forceCreate(['id' => 1, 'username' => 'synthetic', 'display_name' => 'Synthetic', 'active' => true]);
        $this->tenant = Tenant::forceCreate(['id' => 1, 'name' => 'Synthetic', 'slug' => 'synthetic', 'active' => true]);
        $db->table('clubora.tenant_accounts')->insert(['tenant_id' => 1, 'account_id' => 1, 'role' => 'office']);
        Http::preventStrayRequests();
    }

    private function seedRequests(): void
    {
        foreach (range(1, 8) as $id) {
            DB::connection('clubunio')->table('clubora.bus_reservations')->insert(['id' => $id, 'tenant_id' => 1,
                'starts_at' => '2026-01-01 10:00:00', 'location' => 'Destination', 'status' => 'ANGEFRAGT', 'active' => true]);
            DB::connection('clubunio')->table('clubora.pitch_requests')->insert(['id' => $id, 'tenant_id' => 1,
                'date' => '2026-01-01', 'starts_at' => '18:00:00', 'opponent' => 'Opponent', 'status' => 'pending']);
        }
    }

    public function test_pending_counts_are_tenant_scoped_bounded_and_keep_overdue_requests(): void
    {
        $this->seedRequests();
        $db = DB::connection('clubunio');
        $db->table('clubora.bus_reservations')->where('id', 1)->update(['tenant_id' => 2]);
        $db->table('clubora.bus_reservations')->where('id', 2)->update(['active' => false]);
        $db->table('clubora.bus_reservations')->where('id', 3)->update(['status' => 'GENEHMIGT']);
        $db->table('clubora.pitch_requests')->where('id', 1)->update(['tenant_id' => 2]);
        $db->table('clubora.pitch_requests')->where('id', 2)->update(['status' => 'approved']);
        $result = app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant);
        $this->assertSame(5, $result['bus']['count']);
        $this->assertSame([4, 5, 6, 7, 8], $result['bus']['rows']->pluck('id')->all());
        $this->assertSame(6, $result['pitch']['count']);
        $this->assertCount(5, $result['pitch']['rows']);
        $this->assertTrue($result['pitch']['has_more']);
        $next = app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant, pitchPage: 2);
        $this->assertSame([8], $next['pitch']['rows']->pluck('id')->all());
        $this->assertFalse($next['pitch']['has_more']);
        Http::assertNothingSent();
    }

    public function test_revoked_role_is_rechecked_before_any_request_data(): void
    {
        $this->seedRequests();
        DB::connection('clubunio')->table('clubora.tenant_accounts')->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant);
    }

    public function test_foreign_tenant_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        app(DashboardReservationRequests::class)->pending($this->actor, Tenant::forceCreate(['id' => 2, 'name' => 'Other', 'slug' => 'other']));
    }

    public function test_disabled_account_is_rechecked(): void
    {
        DB::connection('clubunio')->table('clubora.accounts')->where('id', 1)->update(['active' => false]);
        $this->expectException(HttpException::class);
        app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant);
    }

    public function test_read_only_support_mode_does_not_offer_approval_inboxes(): void
    {
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 1, 'target_id' => 1]);
        $this->assertSame([], app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant));
    }

    public function test_empty_lists_and_invalid_pagination(): void
    {
        $result = app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant);
        $this->assertSame(0, $result['bus']['count']);
        $this->assertSame(0, $result['pitch']['count']);
        $this->expectException(HttpException::class);
        app(DashboardReservationRequests::class)->pending($this->actor, $this->tenant, 0);
    }

    public function test_widget_renders_review_links_and_escaped_labels_in_both_languages(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        foreach (['de' => 'Offene Reservierungsanfragen', 'en' => 'Pending reservation requests'] as $locale => $heading) {
            app()->setLocale($locale);
            Livewire::test(ReservationRequestsWidgetHarness::class)->assertSee($heading)
                ->assertSee('/bus/7', false)->assertSee('booking=9', false)->assertSee('date=2026-11-05', false)
                ->assertDontSee('<script>unsafe</script>', false)->assertSee('wire:click="$set(\'busPage\', 2)"', false);
        }
    }
}

class ReservationRequestsWidgetHarness extends \App\ClubUnio\Widgets\PendingReservationRequests
{
    public static function canView(): bool { return true; }

    protected function getViewData(): array
    {
        return ['groups' => [
            'bus' => ['count' => 6, 'page' => 1, 'has_more' => true, 'rows' => collect([(object) ['id' => 7, 'starts_at' => '2026-11-05T18:00:00+01:00', 'location' => '<script>unsafe</script>']])],
            'pitch' => ['count' => 1, 'page' => 1, 'has_more' => false, 'rows' => collect([(object) ['id' => 9, 'date' => '2026-11-05', 'starts_at' => '18:00:00', 'opponent' => 'Opponent']])],
        ]];
    }
}
