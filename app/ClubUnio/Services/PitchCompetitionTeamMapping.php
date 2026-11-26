<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Additive historical mapping only. Never import a match or infer identity from names. */
final class PitchCompetitionTeamMapping
{
    public function __construct(private PitchPlanningProjection $projection) {}

    public function preview(Account $actor, Tenant $tenant, string $from, string $to): array
    {
        $this->authorize($actor, $tenant);

        return $this->report($tenant, $from, $to);
    }

    public function apply(Account $actor, Tenant $tenant, string $from, string $to, string $fingerprint, bool $confirmed = false): array
    {
        abort_unless($confirmed, 422, trans('pitch_mapping.confirmation_required'));

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $from, $to, $fingerprint) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant, true);
            $report = $this->report($tenant, $from, $to, true);
            abort_unless(hash_equals($report['fingerprint'], $fingerprint), 409, trans('pitch_mapping.stale'));
            abort_if($report['summary']['error'] > 0, 422, trans('pitch_mapping.errors_present'));

            foreach ($report['entries'] as $entry) {
                if ($entry['status'] !== 'ready') continue;
                foreach ($entry['team_season_ids'] as $team) {
                    $this->table('pitch_competition_teams', $tenant)->insert([
                        'tenant_id' => $tenant->id, 'competition_id' => $entry['competition_id'], 'team_season_id' => $team,
                    ]);
                }
                // Invalidate open pitch editors without touching the competition or its rooms.
                $this->table('pitch_competition_settings', $tenant)->insert([
                    'tenant_id' => $tenant->id, 'competition_id' => $entry['competition_id'], 'revision' => 1,
                ]);
                $this->table('object_changes', $tenant)->insert([
                    'tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => 'pitch_competition_teams', 'object_id' => $entry['competition_id'],
                    'before_value' => json_encode(['team_season_ids' => []], JSON_THROW_ON_ERROR),
                    'after_value' => json_encode($entry + ['action' => 'historical_sfv_mapping', 'fingerprint' => $fingerprint], JSON_THROW_ON_ERROR),
                ]);
            }

            return $report + ['applied' => $report['summary']['ready']];
        });
    }

    private function authorize(Account $actor, Tenant $tenant, bool $lock = false): void
    {
        // Query fresh facts directly: neither cached GET permissions nor simulated access may apply mappings.
        abort_if(request()->attributes->get('clubunio_impersonation'), 403);
        $account = Account::whereKey($actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $membership = $this->table('tenant_accounts', $tenant)->where('account_id', $actor->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($account?->active && ! $account->api_only && $membership?->role === 'admin', 403);
        abort_unless($this->projection->ready(), 503, trans('pitch_mapping.not_ready'));
    }

    private function table(string $table, Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubunio.'.$table)->where('tenant_id', $tenant->id);
    }

    private function report(Tenant $tenant, string $from, string $to, bool $lock = false): array
    {
        Validator::make(compact('from', 'to'), [
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->validate();
        $competitions = $this->table('competitions', $tenant)->whereBetween('plays_on', [$from, $to])
            ->orderBy('id')->limit(1001)->when($lock, fn ($q) => $q->lockForUpdate())->get();
        abort_if($competitions->count() > 1000, 422, trans('pitch_mapping.limit'));
        $ids = $competitions->pluck('id');
        $matchIds = $competitions->pluck('association_match_id')->filter()->unique();
        $facts = ['competitions' => $competitions];
        foreach (['sfv_schedule', 'pitch_competition_teams', 'pitch_competition_settings', 'teams', 'team_seasons', 'seasons'] as $table) {
            $query = $this->table($table, $tenant);
            if ($table === 'sfv_schedule') $query->whereIn('match_id', $matchIds);
            if (str_starts_with($table, 'pitch_competition_')) $query->whereIn('competition_id', $ids);
            $facts[$table] = $query->orderBy($table === 'pitch_competition_teams' ? 'competition_id' : 'id')
                ->when($table === 'pitch_competition_teams', fn ($q) => $q->orderBy('team_season_id'))
                ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        }
        // Include archived/out-of-range duplicate links; never silently choose one source target.
        $facts['linked_competitions'] = $this->table('competitions', $tenant)->whereIn('association_match_id', $matchIds)
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get(['id', 'association_match_id']);
        $sources = $facts['sfv_schedule']->groupBy('match_id');
        $links = $facts['linked_competitions']->groupBy('association_match_id');
        $mapped = $facts['pitch_competition_teams']->groupBy('competition_id');
        $settings = $facts['pitch_competition_settings']->keyBy('competition_id');
        $teams = $facts['teams']->groupBy('sfv_team_id');
        $seasons = $facts['seasons']->keyBy('id');
        $assignments = $facts['team_seasons']->groupBy('team_id');
        $entries = [];
        foreach ($competitions as $competition) {
            $entry = ['competition_id' => (int) $competition->id, 'match_id' => $competition->association_match_id === null ? null : (int) $competition->association_match_id,
                'date' => $competition->plays_on, 'source_id' => null, 'source_season_id' => null, 'sfv_team_ids' => [], 'team_season_ids' => [],
                'status' => 'skipped', 'reason' => 'no_match_id'];
            if ($mapped->has($competition->id)) {
                $entry['reason'] = 'existing_mapping';
                $entry['team_season_ids'] = $mapped[$competition->id]->pluck('team_season_id')->map(fn ($id) => (int) $id)->all();
            } elseif ($settings->has($competition->id)) {
                // Also protects an explicitly cleared manual mapping: it leaves settings but no link rows.
                $entry['reason'] = 'local_configuration';
            } elseif (! $competition->active) {
                $entry['reason'] = 'inactive_competition';
            } elseif ($entry['match_id'] > 0) {
                $matches = $sources->get($entry['match_id'], collect());
                if ($links[$entry['match_id']]->count() !== 1 || $matches->count() > 1) {
                    $entry = array_replace($entry, ['status' => 'error', 'reason' => 'ambiguous_match']);
                } elseif ($matches->isEmpty()) {
                    $entry['reason'] = 'missing_source';
                } else {
                    $source = $matches->first();
                    $entry['source_id'] = (int) $source->id;
                    // SFV's season ID is not a ClubUnio season ID. Retain as evidence only.
                    $entry['source_season_id'] = (int) $source->season_id;
                    $entry['sfv_team_ids'] = array_values(array_filter([(int) $source->team_a_id, (int) $source->team_b_id], fn ($id) => $id > 0));
                    if (! $source->starts_at || CarbonImmutable::parse($source->starts_at)->tz('Europe/Zurich')->toDateString() !== $competition->plays_on) {
                        $entry = array_replace($entry, ['status' => 'error', 'reason' => 'source_date_mismatch']);
                    } elseif (count(array_unique($entry['sfv_team_ids'])) !== 2) {
                        $entry = array_replace($entry, ['status' => 'error', 'reason' => 'invalid_source_teams']);
                    } else {
                        $entry['reason'] = 'no_local_team';
                        foreach ($entry['sfv_team_ids'] as $sfvId) {
                            $local = $teams->get($sfvId, collect());
                            if ($local->isEmpty()) continue; // An external opponent has no local staff.
                            $candidates = [];
                            foreach ($local as $team) {
                                if (! $team->active) continue;
                                foreach ($assignments->get($team->id, collect()) as $assignment) {
                                    $season = $seasons->get($assignment->season_id);
                                    if ($assignment->active && $season?->active && $season->starts_on && $season->ends_on
                                        && $season->starts_on <= $competition->plays_on && $season->ends_on >= $competition->plays_on) {
                                        $candidates[] = (int) $assignment->id;
                                    }
                                }
                            }
                            if (count($candidates) !== 1) {
                                $entry['status'] = 'error';
                                $entry['reason'] = count($candidates) > 1 ? 'ambiguous_team' : 'missing_team_season';
                                break;
                            }
                            $entry['team_season_ids'][] = $candidates[0];
                        }
                        if ($entry['status'] !== 'error' && $entry['team_season_ids'] !== []) {
                            $entry['status'] = 'ready';
                            $entry['reason'] = 'deterministic_match';
                        }
                    }
                }
            }
            sort($entry['team_season_ids'], SORT_NUMERIC);
            $entries[] = $entry;
        }
        $summary = ['ready' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($entries as $entry) $summary[$entry['status']]++;
        $scope = ['tenant_id' => (int) $tenant->id, 'from' => $from, 'to' => $to];

        return $scope + ['entries' => $entries, 'summary' => $summary,
            'fingerprint' => hash('sha256', json_encode(['version' => 1, 'scope' => $scope, 'facts' => $facts], JSON_THROW_ON_ERROR))];
    }
}
