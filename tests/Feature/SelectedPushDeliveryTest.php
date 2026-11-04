<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileAdminNoticeService, MobilePushOutbox, MobilePushSources};
use Tests\TestCase;

class SelectedPushDeliveryTest extends TestCase
{
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
