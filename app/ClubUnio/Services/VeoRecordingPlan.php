<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class VeoRecordingPlan
{
    private const TIMEZONE = 'Europe/Zurich';

    public function __construct(private PitchPlanningProjection $projection) {}

    public function cameras(Tenant $tenant): Collection
    {
        return $this->projection->table('veo_cameras', $tenant)->orderBy('field_id')->get()->keyBy('field_id');
    }

    public function enabledTeams(Tenant $tenant): Collection
    {
        return $this->projection->table('veo_recording_teams', $tenant)->orderBy('team_season_id')
            ->pluck('team_season_id')->map(fn ($id) => (int) $id);
    }

    public function saveCamera(Tenant $tenant, int $fieldId, ?string $name): void
    {
        $camera = $this->projection->table('veo_cameras', $tenant)->where('field_id', $fieldId);
        if ($name === null || trim($name) === '') {
            $camera->delete();

            return;
        }
        $name = trim($name);
        abort_unless($this->projection->table('training_options', $tenant)
            ->where('id', $fieldId)->where('category', 'field')->where('active', true)->exists(), 404);
        Validator::make(compact('name'), ['name' => 'required|string|max:120'])->validate();
        $camera->upsert([
            'tenant_id' => $tenant->id, 'field_id' => $fieldId, 'name' => $name,
        ], ['tenant_id', 'field_id'], ['name']);
    }

    public function setTeamEnabled(Tenant $tenant, int $teamSeasonId, bool $enabled): void
    {
        $setting = $this->projection->table('veo_recording_teams', $tenant)->where('team_season_id', $teamSeasonId);
        if (! $enabled) {
            $setting->delete();

            return;
        }
        abort_unless($this->projection->table('team_seasons', $tenant)
            ->where('id', $teamSeasonId)->where('active', true)->exists(), 404);
        $setting->insertOrIgnore(['tenant_id' => $tenant->id, 'team_season_id' => $teamSeasonId]);
    }

    /** Projection only: recording adjustments never change fixtures or send camera commands. */
    public function entries(Tenant $tenant, string $from, string $to): array
    {
        Validator::make(compact('from', 'to'), [
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->validate();
        $fromDate = Time::createFromFormat('!Y-m-d', $from, self::TIMEZONE);
        $toDate = Time::createFromFormat('!Y-m-d', $to, self::TIMEZONE);
        abort_if($fromDate->diffInDays($toDate) > 31, 422);

        $adjustedIds = $this->projection->table('veo_recording_adjustments', $tenant)
            ->where('starts_at', '<', $toDate->addDay()->toIso8601String())
            ->where('ends_at', '>', $fromDate->toIso8601String())->pluck('match_id');
        $matches = $this->projection->table('sfv_schedule', $tenant)->where(fn ($query) => $query->whereBetween('starts_at', [
            $fromDate->subDays(8)->toIso8601String(), $toDate->addDays(9)->toIso8601String(),
        ])->orWhereIn('match_id', $adjustedIds))->orderBy('starts_at')->orderBy('match_id')->get();
        $enabled = $this->enabledTeams($tenant);
        if ($matches->isEmpty() || $enabled->isEmpty()) {
            return [];
        }

        $links = $this->projection->table('competitions', $tenant)
            ->whereIn('association_match_id', $matches->pluck('match_id'))->get()->groupBy('association_match_id');
        $competitionIds = $links->collapse()->pluck('id');
        $explicitTeams = $this->projection->table('pitch_competition_teams', $tenant)
            ->whereIn('competition_id', $competitionIds)->get()->groupBy('competition_id');
        $settings = $this->projection->table('pitch_competition_settings', $tenant)
            ->whereIn('competition_id', $competitionIds)->get()->keyBy('competition_id');
        $teams = $this->eligibleTeams($tenant, $enabled);
        $fields = $this->projection->table('training_options', $tenant)
            ->where('category', 'field')->where('active', true)->pluck('id')->flip();
        $cameras = $this->cameras($tenant);
        $adjustments = $this->projection->table('veo_recording_adjustments', $tenant)
            ->whereIn('match_id', $matches->pluck('match_id'))->get()->keyBy('match_id');

        $entries = [];
        foreach ($matches as $match) {
            $startsAt = Time::parse($match->starts_at)->tz(self::TIMEZONE);
            $date = $startsAt->toDateString();
            $linked = $links->get($match->match_id, collect());
            $competition = $linked->count() === 1 ? $linked->first() : null;
            $override = $competition && SfvLocalOverride::recorded($tenant, $competition);
            if (! $override && in_array(mb_strtolower(trim((string) $match->state)), ['abgesagt', 'annulliert', 'abgebrochen', 'verschoben'], true)) {
                continue;
            }
            if ($override) {
                $startsAt = Time::parse($competition->plays_on.' '.$competition->starts_at, self::TIMEZONE);
                $date = $startsAt->toDateString();
            }
            $eligible = $teams->filter(fn ($team) => $team->starts_on <= $date && $team->ends_on >= $date);
            if (! $this->isEligible($match, $linked, $explicitTeams, $eligible)) {
                continue;
            }

            $setting = $settings->get($competition?->id);
            if ($competition && (! $competition->active || $setting?->withdrawn)) {
                continue;
            }
            $fieldId = $competition?->plays_on === $date
                && substr((string) $competition->starts_at, 0, 8) === $startsAt->format('H:i:s')
                && $fields->has($competition->field_id) ? (int) $competition->field_id : null;
            $camera = $cameras->get($fieldId);
            $entry = [
                'match_id' => (int) $match->match_id,
                'title' => $match->title,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($setting?->duration_minutes ?? 120),
                'field_id' => $fieldId,
                'camera' => $camera?->name,
                'status' => match (true) {
                    $fieldId === null => 'unplaced',
                    $camera === null => 'no_camera',
                    default => 'planned',
                },
            ];
            $entry['fixture_starts_at'] = $startsAt;
            $entry['basis_hash'] = hash('sha256', json_encode([
                $match->starts_at, $match->state, $competition?->id, $competition?->plays_on,
                $competition?->starts_at, $fieldId, $setting?->duration_minutes, $camera?->name,
            ], JSON_THROW_ON_ERROR));
            $adjustment = $adjustments->get($match->match_id);
            $entry['revision'] = hash('sha256', $entry['basis_hash'].'|'.json_encode($adjustment, JSON_THROW_ON_ERROR));
            $entry['adjusted'] = $adjustment !== null;
            if ($adjustment && hash_equals($entry['basis_hash'], $adjustment->basis_hash)) {
                $entry['starts_at'] = Time::parse($adjustment->starts_at)->tz(self::TIMEZONE);
                $entry['ends_at'] = Time::parse($adjustment->ends_at)->tz(self::TIMEZONE);
                $entry['field_id'] = (int) $adjustment->field_id;
                $entry['camera'] = $fields->has($adjustment->field_id) ? $cameras->get($adjustment->field_id)?->name : null;
                $entry['status'] = $entry['camera'] === null ? 'no_camera' : 'planned';
            } elseif ($adjustment) {
                // A changed fixture owns the camera again; only relative lead-in and duration follow it.
                // Older rows have no trustworthy anchor: retain duration, never guess an old kickoff.
                $leadIn = isset($adjustment->fixture_starts_at)
                    ? Time::parse($adjustment->fixture_starts_at)->diffInSeconds(Time::parse($adjustment->starts_at)) : 0;
                $duration = Time::parse($adjustment->starts_at)->diffInSeconds(Time::parse($adjustment->ends_at));
                $entry['starts_at'] = $startsAt->addSeconds($leadIn);
                $entry['ends_at'] = $entry['starts_at']->addSeconds($duration);
                $entry['followed_fixture'] = true;
            }
            $entries[] = $entry;
        }

        usort($entries, fn ($a, $b) => [$a['starts_at'], $a['match_id']] <=> [$b['starts_at'], $b['match_id']]);
        $conflicts = $this->conflicts($entries);

        return collect($entries)->filter(fn ($entry) => $entry['ends_at']->gt($fromDate)
            && $entry['starts_at']->lt($toDate->addDay()))
            ->map(fn ($entry) => isset($conflicts[$entry['match_id']])
                ? array_replace($entry, ['status' => 'conflict']) : $entry)->values()->all();
    }

    private function eligibleTeams(Tenant $tenant, Collection $enabled): Collection
    {
        return DB::connection('clubunio')->table('clubora.team_seasons as assignments')
            ->join('clubora.teams as teams', fn ($join) => $join
                ->on('teams.id', '=', 'assignments.team_id')->on('teams.tenant_id', '=', 'assignments.tenant_id'))
            ->join('clubora.seasons as seasons', fn ($join) => $join
                ->on('seasons.id', '=', 'assignments.season_id')->on('seasons.tenant_id', '=', 'assignments.tenant_id'))
            ->where('assignments.tenant_id', $tenant->id)->whereIn('assignments.id', $enabled)->where('assignments.active', true)
            ->where('teams.active', true)->where('seasons.active', true)
            ->get(['assignments.id', 'teams.sfv_team_id', 'seasons.starts_on', 'seasons.ends_on']);
    }

    private function isEligible(object $match, Collection $linked, Collection $explicitTeams, Collection $eligible): bool
    {
        $mapped = $linked->flatMap(fn ($competition) => $explicitTeams->get($competition->id, collect()))
            ->pluck('team_season_id');
        if ($mapped->isNotEmpty()) {
            return $eligible->pluck('id')->intersect($mapped)->isNotEmpty();
        }

        return $match->home && $eligible->pluck('sfv_team_id')->filter()
            ->intersect([$match->team_a_id, $match->team_b_id])->isNotEmpty();
    }

    private function conflicts(array $entries): array
    {
        $lastEnding = [];
        $conflicts = [];
        foreach ($entries as $entry) {
            if ($entry['status'] !== 'planned') {
                continue;
            }
            $field = $entry['field_id'];
            $previous = $lastEnding[$field] ?? null;
            if ($previous && $previous['ends_at'] > $entry['starts_at']) {
                $conflicts[$previous['match_id']] = $conflicts[$entry['match_id']] = true;
            }
            if (! $previous || $previous['ends_at'] < $entry['ends_at']) {
                $lastEnding[$field] = $entry;
            }
        }

        return $conflicts;
    }
}
