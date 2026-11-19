<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use Tests\TestCase;

final class SupportLauncherTest extends TestCase
{
    public function test_platform_and_club_use_separate_support_targets(): void
    {
        config(['clubunio-support.enabled' => true]);
        $tenant = (new Tenant)->forceFill(['id' => 4]);
        $platform = view('clubunio.support.launcher', ['supportPlatform' => true, 'supportTenant' => $tenant])->render();
        $club = view('clubunio.support.launcher', ['supportTenant' => $tenant])->render();
        $this->assertStringContainsString(route('clubunio.platform.support'), $platform);
        $this->assertStringNotContainsString('/support/club/4', $platform);
        $this->assertStringContainsString(route('clubunio.support.center', ['club' => 4]), $club);
        $this->assertStringContainsString('data-close-label=', $club);
    }

    public function test_disabled_support_has_no_launcher(): void
    {
        config(['clubunio-support.enabled' => false]);
        $this->assertStringNotContainsString('<script', view('clubunio.support.launcher')->render());
    }
}
