<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class TrainerPortalShellTest extends TestCase
{
    private function trainerFixture(): array
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'app.url' => 'http://localhost', 'clubunio-support.enabled' => false]);
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $db->unprepared("CREATE TABLE clubunio.tenants (id integer PRIMARY KEY); INSERT INTO clubunio.tenants VALUES (2);
            CREATE TABLE clubunio.tenant_accounts (account_id integer, tenant_id integer, role text);
            INSERT INTO clubunio.tenant_accounts VALUES (1, 2, 'trainer')");
        $actor = new Account;
        $actor->forceFill(['id' => 1, 'display_name' => 'Demo Trainer', 'active' => true]);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 2, 'slug' => 'demo-sport', 'name' => 'Demo Sport']);
        $other = new Tenant;
        $other->forceFill(['id' => 3, 'slug' => 'demo-youth', 'name' => 'Demo Youth']);
        $this->mock(MemberAccessService::class, function ($access) use ($actor, $tenant) {
            $access->shouldReceive('canEnter')->with($actor, $tenant)->andReturnTrue();
            $access->shouldReceive('membership')->with($actor, $tenant)->andReturn((object) ['role' => 'trainer']);
            $access->shouldReceive('represented')->with($actor, $tenant)->andReturnUsing(fn () => Tenant::query());
        });
        $this->actingAs($actor, 'portal');

        return ['actor' => $actor, 'tenant' => $tenant, 'tenants' => collect([$tenant, $other]), 'errors' => new ViewErrorBag];
    }

    public function test_trainer_pages_share_navigation_without_backoffice_actions(): void
    {
        $fixture = $this->trainerFixture();
        foreach ([
            ['portal', 'clubunio.portal.members', '/portal/demo-sport/members'],
            ['planning', 'clubunio.planning.calendar', '/planning/demo-sport/calendar'],
            ['bus', 'clubunio.bus.index', '/bus/demo-sport'],
        ] as [$layout, $routeName, $path]) {
            request()->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));
            app()->setLocale($layout === 'planning' ? 'en' : 'de');
            $html = Blade::render("@extends('clubunio.$layout.layout') @section('content') <h1>Fixture</h1> @endsection", $fixture + ['managed' => [17]]);

            $this->assertStringContainsString('id="cu-portal-sidebar"', $html);
            $this->assertMatchesRegularExpression('~'.preg_quote($path, '~').'"\s+aria-current="page"~', $html);
            $this->assertStringContainsString('/portal/demo-youth/members', $html);
            $this->assertStringContainsString('/portal/demo-sport/expenses', $html);
            $this->assertStringContainsString('<h1>Fixture</h1>', $html);
            $this->assertStringNotContainsString('/portal/demo-sport/registrations', $html);
            $this->assertStringNotContainsString("/$layout/demo-sport/options", $html);
            if ($layout === 'planning') {
                $this->assertStringContainsString('aria-label="Portal navigation"', $html);
                $this->assertStringContainsString('/planning/demo-sport/absences', $html);
                $this->assertStringContainsString('/planning/demo-sport/sfv', $html);
            }
        }
    }

    public function test_sponsor_details_and_material_receipts_keep_their_actions_in_the_shared_layout(): void
    {
        $fixture = $this->trainerFixture();
        $day = CarbonImmutable::parse('2026-11-17');
        $page = fn (array $rows) => new LengthAwarePaginator($rows, count($rows), 20);
        $event = (object) ['id' => 7, 'title' => 'Demo sponsored run', 'event_date' => $day, 'place' => 'Demo ground',
            'registration_deadline' => $day->addDay()];
        $participant = (object) ['id' => 15, 'first_name' => 'Demo', 'last_name' => 'Runner', 'team_label' => 'Demo team',
            'required' => true, 'checked_in_at' => $day, 'draft_laps' => 12, 'final_laps' => null, 'revision' => 2];
        $member = (object) ['display_name' => 'Demo member'];
        $receipt = (object) ['id' => 18, 'tenant_id' => 2, 'member_id' => 2, 'member' => $member, 'title' => 'Demo receipt',
            'state' => 'issued', 'snapshot' => ['confirmation' => 'Demo confirmation'], 'handover_on' => $day, 'revision' => 2,
            'items' => [['description' => 'Demo jersey', 'quantity' => 2]], 'note' => null];
        $fixtures = [
            ['portal.sponsor-runs', ['events' => $page([$event]), 'seasons' => [], 'filters' => ['scope' => 'all']], ['Demo sponsored run', 'name="scope"']],
            ['portal.sponsor-run', ['event' => $event, 'participants' => $page([$participant]), 'selected' => $participant,
                'teams' => [5 => 'Demo team'], 'summaries' => [], 'filters' => ['scope' => 'all'], 'pledges' => $page([]), 'canAdd' => true,
                'canShare' => true, 'requestKey' => 'demo-key', 'rights' => ['checkin' => true, 'laps' => true, 'approve' => true]],
                ['#cu-participation', 'name="confirm_final"', 'name="request_key"', 'name="collector_confirmed"', 'name="consent"']],
            ['material.index', ['receipts' => $page([$receipt]), 'states' => ['issued' => 'Issued'],
                'history' => $page([(object) ['id' => 9, 'title' => 'Demo history', 'member' => $member, 'source_state' => 'Archived']])],
                ['Demo receipt', 'Demo history', '/material-history/9/pdf']],
            ['material.show', ['receipt' => $receipt, 'states' => ['issued' => 'Issued']],
                ['Demo jersey', '/material/18/pdf', '/material/18/confirm', 'name="accepted"', 'Demo confirmation']],
        ];
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($fixtures as [$view, $data, $expected]) {
                $html = view('clubunio.'.$view, $fixture + $data)->render();
                $this->assertStringContainsString('class="cu-portal-list"', $html);
                $this->assertStringContainsString('class="table-scroll"', $html);
                foreach ($expected as $text) {
                    $this->assertStringContainsString($text, $html);
                }
            }
        }
    }
}
