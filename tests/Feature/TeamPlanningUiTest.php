<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\PlanningUi;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeamPlanningUiTest extends TestCase
{
    private function planningRequest(array $query = [], int $actor = 81, string $page = 'index'): Request
    {
        $request = Request::create('/planning', 'GET', $query);
        $request->attributes->set('clubunioWorkspace', true);
        $request->setRouteResolver(fn () => (new Route('GET', '/planning', fn () => null))->name('clubunio.workspace.planning.'.$page));
        $user = new Account;
        $user->id = $actor;
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());
        config(['clubunio-planning-ui.enabled' => true]);

        return $request;
    }

    public function test_switch_is_scoped_to_account_and_tenant_and_preserves_query(): void
    {
        $request = $this->planningRequest(['planning_design' => 'classic', 'date' => '2026-11-24', 'view' => 'week', 'team' => 394]);
        $this->assertTrue(PlanningUi::select($request, 1));
        $this->assertFalse(PlanningUi::modern($request, 1));
        $this->assertTrue(PlanningUi::modern($request, 2));
        $this->assertTrue(PlanningUi::modern($this->planningRequest(actor: 82), 1));
        $this->assertSame('http://localhost/planning?date=2026-11-24&view=week&team=394', $request->fullUrlWithoutQuery('planning_design'));
    }

    public function test_gate_and_scope_leave_classic_flows_untouched(): void
    {
        $request = $this->planningRequest(['planning_design' => 'modern']);
        config(['clubunio-planning-ui.enabled' => false]);
        $this->assertFalse(PlanningUi::select($request, 1));
        $this->assertFalse(PlanningUi::modern($request, 1));
        $this->assertFalse(PlanningUi::available($this->planningRequest(page: 'show')));
        $request = $this->planningRequest();
        $request->attributes->set('clubunioWorkspace', false);
        $this->assertFalse(PlanningUi::available($request));
    }

    public function test_unknown_design_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        PlanningUi::select($this->planningRequest(['planning_design' => 'external']), 1);
    }

    public function test_agenda_escapes_event_data_and_translates_summary_in_both_languages(): void
    {
        $event = (object) ['id' => 1, 'team_season_id' => 2, 'title' => '<script>bad</script>', 'starts_at' => '2026-11-24T18:00:00Z', 'ends_at' => '2026-11-24T19:00:00Z', 'location' => '', 'status' => 'draft'];
        $tenant = (object) ['slug' => 'test', 'id' => 1];
        foreach (['de' => 'Termine im Zeitraum', 'en' => 'Events in this period'] as $locale => $label) {
            app()->setLocale($locale);
            $html = view('clubunio.planning.overview', ['events' => collect([$event]), 'teams' => collect(), 'conflicts' => [], 'tenant' => $tenant, 'options' => [], 'colors' => []])->render();
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $html);
            $this->assertStringContainsString('24.11. 19:00', $html);
            $this->assertStringNotContainsString('<script>bad</script>', $html);
        }
    }
}
