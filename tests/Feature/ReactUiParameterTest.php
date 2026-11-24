<?php

namespace Tests\Feature;

use App\ClubUnio\Pages\Dashboard;
use App\ClubUnio\Pages\PitchPlanning;
use App\ClubUnio\Services\PlanningUi;
use App\ClubUnio\Services\ReactUi;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ReactUiParameterTest extends TestCase
{
    public function test_parameter_is_tenant_scoped_cached_per_request_and_fails_closed(): void
    {
        $service = new class extends ReactUi {
            public array $values = [];
            public int $reads = 0;
            protected function encryptedValue(int $tenantId): ?string { $this->reads++; return $this->values[$tenantId] ?? null; }
        };
        $service->values = [1 => Crypt::encryptString('1'), 2 => Crypt::encryptString('0'), 3 => 'corrupted'];
        $this->assertTrue($service->enabled(1));
        $this->assertFalse($service->enabled(2));
        $this->assertFalse($service->enabled(3));
        $this->assertFalse($service->enabled(4));
        $service->values[1] = Crypt::encryptString('0');
        $this->assertTrue($service->enabled(1));
        $this->assertSame(4, $service->reads);
        $service->forget(1);
        $this->assertFalse($service->enabled(1));
    }

    public function test_one_parameter_controls_every_upgraded_module_and_ignores_old_sessions(): void
    {
        $service = new class extends ReactUi {
            public bool $value = true;
            public function enabled(?int $tenantId = null): bool { return $this->value; }
        };
        app()->instance(ReactUi::class, $service);
        $request = Request::create('/planning/calendar?planning_design=classic');
        $request->attributes->set('clubunioWorkspace', true);
        $request->setRouteResolver(fn () => (new Route('GET', '/planning/calendar', fn () => null))->name('clubunio.workspace.planning.calendar'));
        session()->put('clubunio.dashboard.design.701', 'classic');
        session()->put('clubunio.facility.design.701', 'classic');
        foreach ([true, false, true] as $value) {
            $service->value = $value;
            $this->assertSame($value, app(Dashboard::class)->glassSelected());
            $this->assertSame($value, app(PitchPlanning::class)->modernSelected());
            $this->assertSame($value, PlanningUi::modern($request, 1));
        }
        $this->assertFalse(method_exists(Dashboard::class, 'selectDesign'));
        $this->assertFalse(method_exists(PitchPlanning::class, 'selectPlanningDesign'));
    }
}
