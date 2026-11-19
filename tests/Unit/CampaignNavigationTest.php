<?php

namespace Tests\Unit;

use App\ClubUnio\NavigationStructure;
use PHPUnit\Framework\TestCase;

final class CampaignNavigationTest extends TestCase
{
    public function test_campaigns_are_grouped_with_communication_not_hidden_in_settings(): void
    {
        $groups = (new \ReflectionClass(NavigationStructure::class))->getConstant('PRIMARY');

        self::assertContains('MemberCampaigns', $groups['Anlagen & Kommunikation']);
        self::assertSame(1, count(array_keys(array_merge(...array_values($groups)), 'MemberCampaigns', true)));
    }

    public function test_recipient_filters_keep_readable_columns_inside_the_scroll_container(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/clubunio/member-campaigns.blade.php');
        $css = file_get_contents($root.'/public/css/clubunio-campaigns.css');

        self::assertStringContainsString('cu-admin-journal cu-campaign-recipients', $view);
        self::assertStringContainsString("clubunio-campaigns.css') }}?v=3", $view);
        self::assertStringContainsString('.cu-campaign-recipients table { min-width: 1150px; }', $css);
        self::assertStringContainsString('min-width: 150px; word-break: normal;', $css);
    }
}
