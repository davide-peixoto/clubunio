<?php

namespace Tests\Unit;

use App\ClubUnio\Services\CampaignMailQueue;
use PHPUnit\Framework\TestCase;

final class CampaignMailOutcomeTest extends TestCase
{
    public function test_only_explicit_temporary_smtp_rejections_are_retried(): void
    {
        self::assertSame('queued', CampaignMailQueue::outcome(451, 1, 3));
        self::assertSame('failed', CampaignMailQueue::outcome(451, 3, 3));
        self::assertSame('failed', CampaignMailQueue::outcome(550, 1, 3));
        self::assertSame('attention', CampaignMailQueue::outcome(null, 1, 3));
    }
}
