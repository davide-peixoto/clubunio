<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class TrainerPortalShellTest extends TestCase
{
    public function test_trainer_pages_share_navigation_without_backoffice_actions(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'app.url' => 'http://localhost', 'clubunio-support.enabled' => false]);
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared("CREATE TABLE clubora.tenants (id integer PRIMARY KEY); INSERT INTO clubora.tenants VALUES (2);
            CREATE TABLE clubora.tenant_accounts (account_id integer, tenant_id integer, role text);
            INSERT INTO clubora.tenant_accounts VALUES (1, 2, 'trainer')");
        $actor = new Account;
        $actor->forceFill(['id' => 1, 'display_name' => 'Demo Trainer', 'active' => true]);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 2, 'slug' => 'demo-sport', 'name' => 'Demo Sport']);
        $other = new Tenant;
        $other->forceFill(['id' => 3, 'slug' => 'demo-youth', 'name' => 'Demo Youth']);
        $this->mock(MemberAccessService::class, function ($access) use ($actor, $tenant) {
            $access->shouldReceive('canEnter')->with($actor, $tenant)->andReturnTrue();
            $access->shouldReceive('membership')->with($actor, $tenant)->andReturn((object) ['role' => 'trainer']);
        });
        $this->actingAs($actor, 'portal');

        foreach ([
            ['portal', 'clubunio.portal.members', '/portal/demo-sport/members'],
            ['planning', 'clubunio.planning.calendar', '/planning/demo-sport/calendar'],
            ['bus', 'clubunio.bus.index', '/bus/demo-sport'],
        ] as [$layout, $routeName, $path]) {
            request()->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));
            app()->setLocale($layout === 'planning' ? 'en' : 'de');
            $html = Blade::render("@extends('clubunio.$layout.layout') @section('content') <h1>Fixture</h1> @endsection", [
                'actor' => $actor, 'tenant' => $tenant, 'tenants' => collect([$tenant, $other]),
                'managed' => [17], 'errors' => new ViewErrorBag,
            ]);

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
}
