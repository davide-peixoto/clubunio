<?php

namespace Tests\Feature;

use App\ClubUnio\Services\{SfvPlanningService, SocialJobRenderer, SocialJobService};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

final class SocialJobsTest extends TestCase
{
    public function test_zurich_clock_uses_a_bounded_matchday_window_and_night_only_for_results(): void
    {
        $now = fn ($time) => CarbonImmutable::parse('2026-11-13T'.$time.':00Z');
        self::assertFalse(SocialJobService::due($now('06:59'), '08:00:00', 'day'));
        self::assertTrue(SocialJobService::due($now('07:00'), '08:00:00', 'day'));
        self::assertFalse(SocialJobService::due($now('07:20'), '08:00:00', 'day'));
        self::assertTrue(SocialJobService::due($now('01:15'), '02:00:00', 'result'));
        self::assertFalse(SocialJobService::due($now('01:08'), '02:00:00', 'result'));
        self::assertFalse(SocialJobService::due($now('05:00'), '02:00:00', 'result'));
    }

    public function test_only_explicitly_ended_sfv_matches_with_both_scores_are_accepted(): void
    {
        $match = (object) ['match_id' => 123, 'starts_at' => '2026-11-13T19:00:00+01:00'];
        $detail = ['matchId' => 123, 'matchDate' => '2026-11-13T18:00:00Z',
            'hasMatchEnded' => true, 'scoreTeamA' => 0, 'scoreTeamB' => 0];
        self::assertSame([0, 0], SfvPlanningService::finalScore($match, $detail));
        foreach ([['hasMatchEnded' => false], ['scoreTeamA' => null], ['scoreTeamB' => '0'], ['matchId' => 124], ['matchDate' => '2026-11-14']] as $bad) {
            self::assertNull(SfvPlanningService::finalScore($match, array_replace($detail, $bad)));
        }
    }

    public function test_worker_renders_a_real_result_image_without_a_browser(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->statement("ATTACH DATABASE ':memory:' AS clubora");
        DB::connection('clubunio')->statement('CREATE TABLE clubora.social_media (tenant_id INTEGER, id TEXT, path TEXT)');
        Storage::fake('local');
        $path = app(SocialJobRenderer::class)->render(7, [
            'format' => 'square', 'background' => '#153559', 'layers' => [[
                'type' => 'field', 'field' => 'score', 'x' => 150, 'y' => 150, 'w' => 400, 'h' => 180,
                'angle' => 0, 'size' => 85, 'fill' => '#ffffff', 'bold' => true,
            ]],
        ], [['home' => 'SV Beispiel', 'away' => 'FC Test', 'time' => '19:00', 'place' => 'Platz 1', 'score' => [3, 1]]],
            'Synthetic', '2026-11-13');
        $bytes = Storage::disk('local')->get($path);
        $size = getimagesizefromstring($bytes);
        self::assertSame([1080, 1080, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        $image = imagecreatefromstring($bytes);
        $colours = [];
        for ($y = 150; $y < 300; $y += 4) {
            for ($x = 150; $x < 450; $x += 4) {
                $colours[imagecolorat($image, $x, $y)] = true;
            }
        }
        self::assertGreaterThan(15, count($colours));
    }

    public function test_schedule_scores_require_the_observed_final_state_and_matching_teams(): void
    {
        $match = (object) ['match_id' => 123, 'team_a_id' => 11, 'team_b_id' => 12, 'starts_at' => '2026-11-13T19:00:00+01:00'];
        $row = ['matchId' => 123, 'matchDate' => '2026-11-13T19:00:00', 'teamAId' => 11, 'teamBId' => 12,
            'matchState' => 2, 'matchStateName' => 'ausgetragen', 'scoreTeamA' => 0, 'scoreTeamB' => 2];
        self::assertSame([0, 2], SfvPlanningService::scheduleScore($match, $row));
        foreach ([['matchState' => 1], ['matchStateName' => 'abgesagt'], ['teamAId' => 12], ['scoreTeamA' => null]] as $bad) {
            self::assertNull(SfvPlanningService::scheduleScore($match, array_replace($row, $bad)));
        }
    }
}
