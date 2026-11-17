<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileSeasonScope;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\PlanningCalendarService;
use App\ClubUnio\Services\PortalReadContext;
use App\ClubUnio\Services\TeamPlanningService;
use App\ClubUnio\Services\TrainerWebSeasonScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class TrainerWebSeasonScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-verein-oidc.invites_enabled' => false]);
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.accounts (id integer PRIMARY KEY, active boolean);
CREATE TABLE clubora.tenants (id integer PRIMARY KEY);
CREATE TABLE clubora.tenant_accounts (tenant_id integer, account_id integer, role text, portal_enabled boolean);
CREATE TABLE clubora.tenant_settings (tenant_id integer, area text, key text, encrypted_value text);
CREATE TABLE clubora.seasons (id integer, tenant_id integer, legacy_id integer, name text, active boolean, starts_on text, ends_on text);
CREATE TABLE clubora.teams (id integer, tenant_id integer, active boolean);
CREATE TABLE clubora.team_seasons (id integer, tenant_id integer, team_id integer, season_id integer, active boolean);
CREATE TABLE clubora.team_staff (member_id integer, tenant_id integer, team_season_id integer, active boolean);
CREATE TABLE clubora.team_players (member_id integer, tenant_id integer, team_season_id integer, active boolean);
CREATE TABLE clubora.team_events (id integer, tenant_id integer, team_season_id integer, deleted_at text);
CREATE TABLE clubora.team_invitations (tenant_id integer, event_id integer, member_id integer);
CREATE TABLE clubora.team_absences (id integer, tenant_id integer, member_id integer, starts_on text, ends_on text, deleted_at text);
CREATE TABLE clubora.sfv_schedule (id integer, tenant_id integer, starts_at text);
CREATE TABLE clubora.bus_reservations (id integer, tenant_id integer, member_id integer, team_season_id integer, active boolean);
CREATE TABLE clubora.members (id integer, tenant_id integer, status_id integer, archived_at text, exit_date text);
CREATE TABLE clubora.member_statuses (id integer, ends_membership boolean);
CREATE TABLE clubora.expenses (id integer, tenant_id integer, member_id integer, team_season_id integer, season_id integer, active boolean);
CREATE TABLE clubora.invoices (id integer, tenant_id integer, member_id integer, season_id integer, workflow_status_id integer, active boolean);
CREATE TABLE clubora.invoice_workflow_statuses (id integer, code text);
INSERT INTO clubora.accounts VALUES (1,1),(2,1);
INSERT INTO clubora.tenants VALUES (1);
INSERT INTO clubora.tenant_accounts VALUES (1,1,'trainer',1),(1,2,'admin',1);
INSERT INTO clubora.seasons VALUES (9,1,37,'Previous',1,'2025-07-01','2026-06-30'),(10,1,38,'Current',1,'2026-07-01','2027-06-30');
INSERT INTO clubora.teams VALUES (1,1,1),(2,1,1);
INSERT INTO clubora.team_seasons VALUES (11,1,1,9,1),(12,1,1,10,1),(13,1,2,10,1);
INSERT INTO clubora.team_staff VALUES (101,1,11,1),(101,1,12,1);
INSERT INTO clubora.team_players VALUES (102,1,11,1),(103,1,12,1),(104,1,13,1);
INSERT INTO clubora.members VALUES (101,1,NULL,NULL,NULL),(102,1,NULL,NULL,NULL),(103,1,NULL,NULL,NULL),(104,1,NULL,NULL,NULL);
INSERT INTO clubora.team_events VALUES (201,1,11,NULL),(202,1,12,NULL),(203,1,13,NULL);
INSERT INTO clubora.team_invitations VALUES (1,201,101),(1,203,101);
INSERT INTO clubora.team_absences VALUES (301,1,103,'2026-06-01','2026-06-15',NULL),(302,1,103,'2026-06-30','2026-07-02',NULL);
INSERT INTO clubora.sfv_schedule VALUES (701,1,'2026-06-30T23:59:59+02:00'),(702,1,'2026-07-01T00:00:00+02:00'),(703,1,'2027-07-01T00:00:00+02:00');
INSERT INTO clubora.bus_reservations VALUES (401,1,101,11,1),(402,1,101,12,1),(403,1,104,13,1);
INSERT INTO clubora.expenses VALUES (501,1,101,11,9,1),(502,1,101,12,10,1);
INSERT INTO clubora.invoices VALUES (601,1,103,9,NULL,1),(602,1,103,10,NULL,1);
SQL);
        $db->table('clubora.tenant_settings')->insert(['tenant_id' => 1, 'area' => 'general', 'key' => 'SaisonID', 'encrypted_value' => Crypt::encryptString('38')]);
        $this->partialMock(MemberAccessService::class, function ($access) {
            $access->shouldReceive('represented')->andReturnUsing(fn () => Member::withoutGlobalScopes()->whereKey(101));
        });
    }

    private function routeContext(string $name): void
    {
        request()->setRouteResolver(fn () => app('router')->getRoutes()->getByName($name));
    }

    public function test_web_trainer_sees_current_assignments_and_cannot_open_a_historical_invitation(): void
    {
        $this->actingAs($actor = Account::findOrFail(1), 'portal');
        $tenant = Tenant::findOrFail(1);
        $this->routeContext('clubunio.planning.calendar');
        $planning = app(TeamPlanningService::class);

        $this->assertSame([12], app(MemberAccessService::class)->responsibleTeams($actor, $tenant));
        $this->assertSame([101, 103], app(MemberAccessService::class)->visibleMembers($actor, $tenant)->orderBy('id')->pluck('id')->all());
        $this->assertSame([12], $planning->teams($actor, $tenant)->pluck('id')->all());
        $this->assertSame([202, 203], app(PlanningCalendarService::class)->visible($actor, $tenant)->orderBy('id')->pluck('id')->all());
        $this->assertSame([302], $planning->absences($actor, $tenant)->pluck('id')->all());
        $sfv = $planning->table($tenant, 'sfv_schedule');
        $this->assertSame([702], app(TrainerWebSeasonScope::class)->sfvMatches($sfv, $actor, $tenant)->pluck('id')->all());
        $this->assertSame([402], app(BusReservationService::class)->visible($actor, $tenant)->pluck('id')->all());
        $this->assertSame([502], app(ExpenseService::class)->visible($actor, $tenant)->pluck('id')->all());
        $this->assertSame([602], app(InvoiceAccessService::class)->visible($actor, $tenant)->pluck('id')->all());
        $this->assertFalse(app(MobileSeasonScope::class)->enabled());
        $this->routeContext('clubunio.planning.show');
        $this->assertSame(203, $planning->event($actor, $tenant, 203)->id);
        $this->expectException(NotFoundHttpException::class);
        $planning->event($actor, $tenant, 201);
    }

    public function test_season_setting_selects_a_different_assignment_without_changing_the_team(): void
    {
        DB::connection('clubunio')->table('clubora.tenant_settings')->update(['encrypted_value' => Crypt::encryptString('37')]);
        $this->actingAs($actor = Account::findOrFail(1), 'portal');
        $this->routeContext('clubunio.portal.members');

        $this->assertSame([11], app(MemberAccessService::class)->responsibleTeams($actor, Tenant::findOrFail(1)));
    }

    public function test_missing_season_stays_closed_and_other_contexts_keep_existing_scope(): void
    {
        DB::connection('clubunio')->table('clubora.tenant_settings')->delete();
        $this->actingAs($trainer = Account::findOrFail(1), 'portal');
        $tenant = Tenant::findOrFail(1);
        $this->routeContext('clubunio.planning.index');
        $planning = app(TeamPlanningService::class);

        $this->assertSame([], $planning->teams($trainer, $tenant)->pluck('id')->all());
        $this->assertSame([], $planning->invitedEvents($trainer, $tenant)->pluck('event_id')->all());
        $this->assertSame([], app(BusReservationService::class)->visible($trainer, $tenant)->pluck('id')->all());
        request()->attributes->set('clubunioWorkspace', true);
        $this->assertSame([11, 12], $planning->teams($trainer, $tenant)->orderBy('id')->pluck('id')->all());
        request()->attributes->remove('clubunioWorkspace');
        request()->setRouteResolver(fn () => null);
        $this->assertFalse(app(TrainerWebSeasonScope::class)->applies($trainer, $tenant));
        $this->routeContext('clubunio.planning.index');
        $this->actingAs($admin = Account::findOrFail(2), 'portal');
        $this->assertSame([11, 12, 13], $planning->teams($admin, $tenant)->orderBy('id')->pluck('id')->all());
    }

    public function test_access_reads_are_reused_only_for_the_same_authenticated_portal_get(): void
    {
        $this->actingAs($trainer = Account::findOrFail(1), 'portal');
        $this->routeContext('clubunio.planning.index');
        $reads = app(PortalReadContext::class);
        $access = fn (Account $actor) => $reads->accessFact($actor, 'membership:1', fn () => new \stdClass);

        $first = $access($trainer);
        $this->assertSame($first, $access($trainer));
        $other = Account::findOrFail(2);
        $this->assertNotSame($access($other), $access($other));

        $request = Request::create('/planning/demo-sport', 'GET');
        $this->app->instance('request', $request);
        $this->routeContext('clubunio.planning.index');
        $this->assertNotSame($first, $access($trainer));
        $this->assertSame($access($trainer), $access($trainer));
        $request->attributes->set('clubunio_impersonation', true);
        $this->assertNotSame($access($trainer), $access($trainer));
        $request->attributes->remove('clubunio_impersonation');
        $request->setMethod('POST');
        $this->assertNotSame($access($trainer), $access($trainer));
    }
}
