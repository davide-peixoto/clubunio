<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\PlanningUi;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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

        return $request;
    }

    public function test_workspace_scope_excludes_details_and_portal(): void
    {
        $this->assertTrue(PlanningUi::available($this->planningRequest()));
        $this->assertFalse(PlanningUi::available($this->planningRequest(page: 'show')));
        $request = $this->planningRequest();
        $request->attributes->set('clubunioWorkspace', false);
        $this->assertFalse(PlanningUi::available($request));
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
