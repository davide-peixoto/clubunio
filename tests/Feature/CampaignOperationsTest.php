<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\CampaignOperations;
use Illuminate\Http\Request;
use Tests\TestCase;

class CampaignOperationsTest extends TestCase
{
    public function test_operation_gate_is_exact_and_never_enables_production(): void
    {
        $this->app->instance('env', 'staging');
        foreach (['https://dev.clubunio.ch', 'https://stage.clubunio.com'] as $url) {
            config(['app.url' => $url]);
            self::assertTrue(app(CampaignOperations::class)->enabled());
        }
        config(['app.url' => 'https://clubunio.com']);
        self::assertFalse(app(CampaignOperations::class)->enabled());
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://stage.clubunio.com']);
        self::assertFalse(app(CampaignOperations::class)->enabled());
    }

    public function test_synthetic_member_and_routed_address_are_both_required(): void
    {
        $member = new Member;
        $member->setRawAttributes(['email' => 'campaign-test-child@example.invalid']);
        $ops = new CampaignOperations;
        self::assertTrue($ops->testMember($member, 'campaign-test-parent@example.invalid'));
        self::assertFalse($ops->testMember($member, 'real@example.org'));
        $member->setRawAttributes(['email' => 'real@example.org']);
        self::assertFalse($ops->testMember($member, 'campaign-test-parent@example.invalid'));
    }

    public function test_feedback_without_a_signature_fails_before_database_access(): void
    {
        $this->app->instance('env', 'staging');
        config(['app.url' => 'https://stage.clubunio.com', 'clubunio-campaign-mail.feedback_secret' => str_repeat('a', 32)]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\ClubUnio\Http\CampaignFeedbackController)(Request::create('/campaign-feedback', 'POST'), new CampaignOperations);
    }

    public function test_guardian_scope_ends_on_the_eighteenth_birthday(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-19 12:00:00', 'Europe/Zurich'));
        $member = new Member;
        $member->setRawAttributes(['birth_date' => '2008-11-19']);
        self::assertFalse(\App\ClubUnio\Services\CampaignContact::isMinor($member));
        $member->setRawAttributes(['birth_date' => '2008-11-20']);
        self::assertTrue(\App\ClubUnio\Services\CampaignContact::isMinor($member));
        $this->travelBack();
    }
}
