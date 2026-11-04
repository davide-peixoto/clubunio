<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobilePushFcm, MobilePushOutbox};
use App\ClubUnio\Services\PitchPlanningService;
use Illuminate\Support\Facades\{Http, Mail};
use Illuminate\Support\Str;
use Tests\Support\PitchConcurrencyDatabase as Fixture;
use Tests\TestCase;

class PitchSelectedPushIsolationTest extends TestCase
{
    public function test_selected_push_never_claims_or_cleans_an_unrelated_row(): void
    {
        if (getenv('CLUBUNIO_TEST_PITCH_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt in explicitly to disposable-schema PostgreSQL tests.');
        }
        $schema = 'pitch_race_'.bin2hex(random_bytes(12));
        $db = Fixture::connect($schema);
        try {
            Fixture::create($db, $schema);
            $f = Fixture::seed($db);
            app(PitchPlanningService::class)->requestFriendly($f['actor'], $f['tenant'], [
                'team_season_id' => $f['team'], 'opponent' => 'Synthetic selected push', 'field_id' => $f['field'],
                'date' => '2026-11-06', 'starts_at' => '18:00', 'duration_minutes' => 120,
            ]);
            $row = (array) $db->table('clubora.mobile_push_outbox')->sole();
            $selected = $row['id'];
            $otherPending = (string) Str::uuid();
            $otherExpired = (string) Str::uuid();
            $selectedExpired = (string) Str::uuid();
            foreach ([$otherPending, $otherExpired, $selectedExpired] as $index => $id) {
                $db->table('clubora.mobile_push_outbox')->insert(array_replace($row, [
                    'id' => $id, 'event_key' => 'synthetic:selected:'.$index,
                    'state' => $index === 0 ? 'pending' : 'sending',
                    'created_at' => now()->subHour()->toIso8601String(),
                    'lease_until' => $index === 0 ? null : now()->subMinute()->toIso8601String(),
                ]));
            }
            // No provider path is reachable, even if a future regression claims the wrong row.
            $db->table('clubora.mobile_push_devices')->update(['revoked_at' => now()->toIso8601String()]);
            $this->app->instance(MobilePushFcm::class, new class {
                public function send(...$arguments): never { throw new \LogicException('Provider forbidden in isolation proof'); }
            });
            config(['clubunio-mobile-push.delivery_enabled' => true]);
            $read = fn ($id) => (array) $db->table('clubora.mobile_push_outbox')->where('id', $id)->first();
            $pendingBefore = $read($otherPending);
            $expiredBefore = $read($otherExpired);
            $worker = app(MobilePushOutbox::class);
            $this->assertTrue($worker->deliverOne($selected));
            $this->assertSame('discarded', $read($selected)['state']);
            $this->assertSame('authorization_changed', $read($selected)['outcome_code']);
            $this->assertSame($pendingBefore, $read($otherPending));
            $this->assertSame($expiredBefore, $read($otherExpired));
            $this->assertFalse($worker->deliverOne((string) Str::uuid()));
            $this->assertFalse($worker->deliverOne($selectedExpired));
            $this->assertSame('attention', $read($selectedExpired)['state']);
            $this->assertSame('delivery_uncertain', $read($selectedExpired)['outcome_code']);
            $this->assertFalse($worker->deliverOne($selected));
            $this->assertSame($pendingBefore, $read($otherPending));
            $this->assertSame($expiredBefore, $read($otherExpired));
            Mail::assertNothingSent();
            Http::assertNothingSent();
        } finally {
            $this->assertTrue(Fixture::drop($db, $schema));
        }
    }
}
