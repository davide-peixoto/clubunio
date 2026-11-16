<?php

namespace Tests\Feature;

use App\ClubUnio\Services\EmergencyPasswordLogin;
use Illuminate\Http\Request;
use Tests\TestCase;

final class EmergencyPasswordLoginTest extends TestCase
{
    public function test_window_requires_exact_origin_auditable_incident_and_short_explicit_interval(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0)->utc()->setTime(12, 0));
        $request = Request::create('https://dev.example.test/portal/login');
        $service = app(EmergencyPasswordLogin::class);
        config(['clubunio-verein-oidc.only' => true, 'clubunio-verein-oidc.origin' => 'https://dev.example.test']);
        $this->assertNull($service->expires($request));
        config(['clubunio-verein-oidc.emergency' => ['accounts' => '17,19', 'incident' => 'DEV-123',
            'from' => '2026-11-16T11:45:00Z', 'until' => '2026-11-16T12:30:00Z']]);
        $this->assertSame(now()->getTimestamp() + 1800, $service->expires($request));
        $this->assertNull($service->expires(Request::create('http://dev.example.test/portal/login')));
        $this->assertNull($service->expires(Request::create('https://stage.example.test/portal/login')));
        config(['clubunio-verein-oidc.emergency.until' => '2026-11-16T13:30:00Z']);
        $this->assertNull($service->expires($request));
        config(['clubunio-verein-oidc.emergency.until' => '2026-11-16T12:00:00Z']);
        $this->assertNull($service->expires($request));
        config(['clubunio-verein-oidc.emergency.until' => '2026-11-16T12:30:00Z',
            'clubunio-verein-oidc.emergency.accounts' => '*']);
        $this->assertNull($service->expires($request));
    }
}
