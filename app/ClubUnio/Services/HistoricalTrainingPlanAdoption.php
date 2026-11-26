<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Make private native archive copies; immutable source evidence and delta baselines stay intact. */
final class HistoricalTrainingPlanAdoption
{
    public function available(Tenant $tenant): bool
    {
        return $tenant->slug === 'sv-sissach'
            && Schema::connection('clubunio')->hasColumn('clubunio.legacy_training_plans', 'legacy_read_only')
            && LegacyTrainingPlan::where('tenant_id', $tenant->id)->where('legacy_read_only', true)->exists();
    }

    private function rules(Tenant $tenant): array
    {
        $rules = json_decode(file_get_contents(base_path('deploy/svs-training-adoption-rules-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        if ($rules['tenant_slug'] !== $tenant->slug) {
            throw new LogicException('Training archive rules belong to another tenant');
        }

        return $rules;
    }

    private function assignment(LegacyTrainingPlan $row, Tenant $tenant, array $rules): ?array
    {
        $db = DB::connection('clubunio');
        $mapper = app(LegacyTrainingPlanImporter::class);
        $matches = [];
        $created = \Carbon\CarbonImmutable::parse($row->created_at)->timezone('Europe/Zurich')->toDateTimeString();
        foreach ($db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->get() as $team) {
            if ($mapper->historicalCandidate($row->source_team_name, $created, [$team->id => $team->label])) {
                $matches[(int) $team->id] = $team;
            }
        }
        $exact = function (string $name) use ($matches): ?int {
            $ids = [];
            foreach ($matches as $id => $team) {
                if (explode(' / ', $team->label, 2)[0] === $name) {
                    $ids[] = $id;
                }
            }

            return count($ids) === 1 ? $ids[0] : null;
        };
        if (isset($rules['category_teams'][$row->source_team_name])) {
            $id = $exact($rules['category_teams'][$row->source_team_name]);

            return $id ? [$id, 'operator_category_rule'] : null;
        }
        if ($row->suggested_team_season_id !== null) {
            $id = (int) $row->suggested_team_season_id;

            return isset($matches[$id]) ? [$id, 'explicit_source_team'] : null;
        }
        if (count($matches) === 1) {
            return [array_key_first($matches), 'unique_category_season'];
        }
        $members = $db->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)
            ->where('account_id', $row->suggested_owner_id)->where('kind', 'self')->where('state', 'approved')->pluck('member_id');
        $assigned = $db->table('clubunio.team_staff')->where('tenant_id', $tenant->id)->whereIn('member_id', $members)
            ->whereIn('team_season_id', array_keys($matches))->where('active', true)->distinct()->pluck('team_season_id');
        if ($assigned->count() === 1) {
            return [(int) $assigned->sole(), 'unique_owner_staff_category_season'];
        }
        $override = $rules['source_overrides'][(string) $row->source_id] ?? null;
        if ($override && hash_equals($override['source_hash'], $row->legacy_source_hash)) {
            $id = $exact($override['team_name']);

            return $id ? [$id, 'operator_source_rule'] : null;
        }

        return null;
    }

    public function run(Account $actor, Tenant $tenant, bool $apply = false): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'training', $apply ? 'sync' : 'view');
        abort_unless($this->available($tenant), 404);
        $db = DB::connection('clubunio');

        return $db->transaction(function () use ($actor, $tenant, $apply, $db) {
            $db->table('clubunio.tenants')->where('id', $tenant->id)->lockForUpdate()->sole();
            app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'training', $apply ? 'sync' : 'view');
            $rules = $this->rules($tenant);
            $result = ['eligible' => 0, 'imported' => 0, 'already_imported' => 0, 'excluded' => 0, 'unresolved' => 0,
                'inactive_owners_preserved' => 0, 'quality_pending' => 0, 'assignments' => []];
            $latest = LegacyTrainingPlan::where('tenant_id', $tenant->id)->where('legacy_read_only', true)
                ->selectRaw('max(id)')->groupBy('legacy_source_table', 'source_id');
            foreach (LegacyTrainingPlan::where('tenant_id', $tenant->id)->whereIn('id', $latest)->orderBy('id')->get() as $row) {
                if ($db->table('clubunio.training_plans')->where('tenant_id', $tenant->id)->where('legacy_source_id', $row->id)->exists()) {
                    $result['already_imported']++;
                    continue;
                }
                if ($row->state !== 'review') {
                    $result['excluded']++;
                    continue;
                }
                // An inactive historical owner is preserved, never activated or replaced by the operator.
                $owner = $row->suggested_owner_id ? Account::whereKey($row->suggested_owner_id)
                    ->whereHas('tenants', fn ($q) => $q->where('clubunio.tenants.id', $tenant->id))->first() : null;
                $assignment = $owner && ! $owner->api_only ? $this->assignment($row, $tenant, $rules) : null;
                if (! $assignment) {
                    $result['unresolved']++;
                    continue;
                }
                [$teamId, $reason] = $assignment;
                $team = $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->where('id', $teamId)->sole();
                $plan = app(TrainingPlanContent::class)->validate($row->plan_json, (int) $row->input_json['durationMinutes'], false);
                $result['eligible']++;
                $result['inactive_owners_preserved'] += (int) ! $owner->active;
                $result['quality_pending'] += (int) $row->requires_quality_review;
                $result['assignments'][$reason] = ($result['assignments'][$reason] ?? 0) + 1;
                if (! $apply) {
                    continue;
                }
                $values = array_replace($row->input_json, ['team_season_id' => $teamId, 'teamName' => $team->label]);
                $id = $db->table('clubunio.training_plans')->insertGetId(['tenant_id' => $tenant->id, 'owner_id' => $owner->id,
                    'team_season_id' => $teamId, 'title' => $plan['title'], 'input_json' => json_encode($values, JSON_THROW_ON_ERROR),
                    'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR), 'shared' => false, 'archived_at' => now(),
                    'legacy_source_id' => $row->id, 'legacy_quality_pending' => (bool) $row->requires_quality_review]);
                $db->table('clubunio.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => 'training_plans', 'object_id' => $id, 'after_value' => json_encode([
                        'action' => 'historical_archive_adoption', 'legacy_source_id' => $row->id, 'owner_id' => $owner->id,
                        'team_season_id' => $teamId, 'assignment_rule' => $reason, 'rules_version' => $rules['version'],
                        'private' => true, 'archived' => true, 'quality_pending' => (bool) $row->requires_quality_review,
                    ], JSON_THROW_ON_ERROR)]);
                $result['imported']++;
            }

            return $result;
        });
    }
}
