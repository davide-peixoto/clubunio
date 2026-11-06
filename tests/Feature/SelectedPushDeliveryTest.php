<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileAdminNoticeService, MobilePushOutbox, MobilePushSources};
use Tests\TestCase;

class SelectedPushDeliveryTest extends TestCase
{
    public function test_resident_worker_polls_more_than_once_without_recollecting_or_leaking_request_facts(): void
    {
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => true]);
        $trace = new class { public int $collections = 0; public int $polls = 0; };
        $original = request();
        $this->instance(MobilePushSources::class, new class($trace) {
            public function __construct(private object $trace) {}
            public function existingNotifications(): void { $this->trace->collections++; }
            public function challengeResets(): void {}
        });
        $this->instance(MobileAdminNoticeService::class, new class { public function pending(): void {} });
        $this->instance(\App\ClubUnio\Mobile\ChatPushPublications::class, new class($trace) {
            public function __construct(private object $trace) {}
            public function processOne(): bool {
                \PHPUnit\Framework\Assert::assertFalse(request()->attributes->has('mobile.synthetic_previous_cycle'));
                request()->attributes->set('mobile.synthetic_previous_cycle', true);
                $this->trace->polls++; return false;
            }
        });
        $this->instance(MobilePushOutbox::class, new class { public function deliverOne(): bool { return false; } });
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--watch' => 1])->assertSuccessful();
        self::assertGreaterThanOrEqual(2, $trace->polls);
        self::assertSame(1, $trace->collections);
        self::assertSame($original, request());
    }

    public function test_watch_rejects_unbounded_duration_and_selected_delivery_combination(): void
    {
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => true]);
        foreach (['0', '-1', '3601', 'invalid'] as $duration)
            $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--watch' => $duration])->assertFailed();
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--watch' => 1,
            '--outbox' => '329785b2-0c70-46eb-8710-d5316b573179'])->assertFailed();
    }

    public function test_worker_delivers_each_fanout_before_calculating_the_next_and_respects_budget(): void
    {
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => true]);
        $trace = new class { public array $calls = []; public int $queued = 0; public int $fanouts = 0; };
        $this->instance(MobilePushSources::class, new class {
            public function existingNotifications(): void {}
            public function challengeResets(): void {}
        });
        $this->instance(MobileAdminNoticeService::class, new class { public function pending(): void {} });
        $this->instance(\App\ClubUnio\Mobile\ChatPushPublications::class, new class($trace) {
            public function __construct(private object $trace) {}
            public function processOne(): bool {
                $this->trace->calls[] = 'fanout'; $this->trace->queued++; $this->trace->fanouts++; return true;
            }
        });
        $this->instance(MobilePushOutbox::class, new class($trace) {
            public function __construct(private object $trace) {}
            public function deliverOne(): bool {
                if (!$this->trace->queued) return false;
                $this->trace->calls[] = 'send'; $this->trace->queued--; return true;
            }
        });
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--limit' => 2])->assertSuccessful();
        self::assertSame(['fanout', 'send', 'fanout', 'send'], $trace->calls);
        self::assertSame(2, $trace->fanouts);
    }

    public function test_selected_delivery_does_not_collect_or_drain_other_work(): void
    {
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => true]);
        $id = '329785b2-0c70-46eb-8710-d5316b573179';
        $guard = new class {
            public function __call(string $name, array $arguments): never
            {
                throw new \LogicException('Unrelated work must not be collected: '.$name);
            }
        };
        $this->instance(MobilePushSources::class, $guard);
        $this->instance(MobileAdminNoticeService::class, $guard);
        $this->instance(MobilePushOutbox::class, new class($id) {
            public function __construct(private string $expected) {}
            public function deliverOne(?string $id = null): bool
            {
                \PHPUnit\Framework\Assert::assertSame($this->expected, $id);
                return true;
            }
        });
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--outbox' => $id])
            ->expectsOutput('1 selected outbox record processed; provider acceptance is not device delivery.')
            ->assertSuccessful();
    }

    public function test_selected_delivery_still_requires_provider_flags_and_valid_identity(): void
    {
        config(['clubunio-mobile-push.enabled' => false, 'clubunio-mobile-push.delivery_enabled' => false]);
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--outbox' => 'invalid'])->assertFailed();
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => true]);
        $this->artisan('clubunio:deliver-mobile-push', ['--dispatch' => true, '--outbox' => 'invalid'])->assertFailed();
        $this->expectException(\InvalidArgumentException::class);
        (new MobilePushOutbox)->deliverOne('invalid');
    }
}
