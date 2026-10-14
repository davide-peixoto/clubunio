<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LegacyTrainingPlanImporter
{
    public function convert(object $source): array
    {
        if ($source->Status !== 'success') {
            return ['state' => 'excluded', 'error_code' => 'not_success'];
        }
        try {
            $input = json_decode($source->RequestJson, true, 64, JSON_THROW_ON_ERROR);
            $plan = json_decode($source->PlanJson, true, 64, JSON_THROW_ON_ERROR);
            Validator::make($input, ['durationMinutes' => 'required|integer|min:30|max:120', 'playerCount' => 'required|integer|min:4|max:30',
                'goal' => 'required|string|max:200', 'fieldSize' => 'required|string|max:120', 'material' => 'required|string|max:500'])->validate();
            foreach ($plan['exercises'] ?? [] as $index => $exercise) {
                foreach (['easier', 'harder'] as $variant) {
                    $value = $exercise[$variant] ?? null;
                    if (is_array($value) && array_is_list($value) && collect($value)->every(fn ($item) => is_string($item))) {
                        $plan['exercises'][$index][$variant] = implode("\n", $value);
                    }
                }
                $plan['exercises'][$index]['diagramDescription'] = $exercise['diagram']['description'] ?? '';
                // Schematic replacement only. Legacy SVG/HTML and scripts are never imported or executed.
                $plan['exercises'][$index]['markers'] = [['kind' => 'cone', 'x' => 50, 'y' => 50]];
                unset($plan['exercises'][$index]['diagram']);
            }
            $plan = app(TrainingPlanContent::class)->validate($plan, (int) $input['durationMinutes'], false);
            $alignment = implode(' ', [...$plan['principles'], ...$plan['qualityChecks']]);
            $pending = ! preg_match('/SV\s*Sissach/iu', $alignment) || ! preg_match('/\bSFV\b/iu', $alignment);

            return ['state' => 'review', 'error_code' => null, 'title' => $plan['title'], 'input_json' => array_intersect_key($input, array_flip(['durationMinutes', 'playerCount', 'goal', 'fieldSize', 'material'])),
                'plan_json' => $plan, 'requires_quality_review' => $pending];
        } catch (ValidationException $error) {
            return ['state' => 'excluded', 'error_code' => 'invalid_contract:'.implode(',', array_keys($error->errors()))];
        } catch (\Throwable) {
            return ['state' => 'excluded', 'error_code' => 'invalid_contract'];
        }
    }

    public function stage(Account $actor, Tenant $tenant): array
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        $source = app(LegacyTrainingPlanSource::class);
        $rows = $source->rows($tenant);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $source, $rows) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $counts = ['added' => 0, 'unchanged' => 0, 'changed' => 0, 'reclassified' => 0];
            foreach ($rows as $row) {
                $old = LegacyTrainingPlan::where('tenant_id', $tenant->id)->where('source_id', $row->TrainingPlannerAuditLogID)->first();
                $hash = $source->hash($row);
                if ($old) {
                    if (! hash_equals($old->source_hash, $hash)) {
                        $counts['changed']++;

                        continue;
                    }
                    $converted = $this->convert($row);
                    if ($old->state !== 'imported' && ($old->state !== $converted['state'] || $old->error_code !== ($converted['error_code'] ?? null))) {
                        $old->forceFill($converted + ['revision' => $old->revision + 1])->save();
                        $counts['reclassified']++;
                    } else {
                        $counts['unchanged']++;
                    }

                    continue;
                }
                $owners = Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id))
                    ->whereRaw('lower(username)=lower(?)', [$row->Username ?? ''])->get();
                $team = isset($row->SharedTeamSaisonID) ? TeamSeason::where('tenant_id', $tenant->id)->where('legacy_id', $row->SharedTeamSaisonID)->value('id') : null;
                $record = new LegacyTrainingPlan;
                $record->forceFill($this->convert($row) + ['tenant_id' => $tenant->id, 'source_id' => $row->TrainingPlannerAuditLogID, 'source_hash' => $hash,
                    'source_status' => $row->Status, 'source_username' => $row->Username, 'source_team_name' => $row->TeamName,
                    'suggested_owner_id' => $owners->count() === 1 ? $owners->first()->id : null, 'suggested_team_season_id' => $team])->save();
                $counts['added']++;
            }
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'legacy_training_plans', 'object_id' => 0,
                'after_value' => json_encode(['action' => 'stage', 'counts' => $counts], JSON_THROW_ON_ERROR)]);

            return $counts;
        });
    }

    public function owners(Account $actor, Tenant $tenant): array
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);

        return Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)->whereIn('role', ['admin', 'office', 'trainer', 'technical_lead']))->orderBy('display_name')->pluck('display_name', 'id')->all();
    }

    public function teams(Account $actor, Tenant $tenant, ?int $owner): array
    {
        $options = $this->owners($actor, $tenant);
        if (! $owner || ! isset($options[$owner])) {
            return [];
        }
        $account = Account::findOrFail($owner);

        return app(TrainingPlanService::class)->teams($account, $tenant)->pluck('label', 'id')->all();
    }

    public function import(Account $actor, Tenant $tenant, int $id, array $input): int
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'owner_id' => 'required|integer|min:1', 'team_season_id' => 'required|integer|min:1',
            'decision_note' => 'required|string|min:3|max:1000', 'schematic_confirmed' => 'accepted'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $row = LegacyTrainingPlan::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ($row->state === 'imported') {
                abort_unless((int) $row->owner_id === (int) $data['owner_id'] && (int) $row->team_season_id === (int) $data['team_season_id'], 409);

                return $row->plan_id;
            }
            if ($row->state !== 'review' || (int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Der Altplan ist geändert oder nicht übernahmefähig.']);
            }
            $source = app(LegacyTrainingPlanSource::class);
            $current = $source->rows($tenant)->firstWhere('TrainingPlannerAuditLogID', $row->source_id);
            abort_unless($current && hash_equals($row->source_hash, $source->hash($current)), 409, 'Altbestand wurde geändert. Übernahme gesperrt.');
            $teams = $this->teams($actor, $tenant, (int) $data['owner_id']);
            abort_unless(isset($teams[$data['team_season_id']]), 403);
            $plan = app(TrainingPlanContent::class)->validate($row->plan_json, $row->input_json['durationMinutes'], false);
            $values = $row->input_json + ['team_season_id' => (int) $data['team_season_id'], 'teamName' => $teams[$data['team_season_id']]];
            $planId = DB::connection('clubunio')->table('clubora.training_plans')->insertGetId(['tenant_id' => $tenant->id, 'owner_id' => $data['owner_id'], 'team_season_id' => $data['team_season_id'],
                'title' => $plan['title'], 'input_json' => json_encode($values, JSON_THROW_ON_ERROR), 'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR), 'shared' => false,
                'archived_at' => now()->toIso8601String(), 'legacy_source_id' => $row->id, 'legacy_quality_pending' => $row->requires_quality_review]);
            $row->forceFill(['state' => 'imported', 'plan_id' => $planId, 'owner_id' => $data['owner_id'], 'team_season_id' => $data['team_season_id'], 'imported_by' => $actor->id,
                'imported_at' => now(), 'decision_note' => $data['decision_note'], 'revision' => $row->revision + 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'training_plans', 'object_id' => $planId,
                'after_value' => json_encode(['action' => 'legacy_import', 'source_id' => $row->source_id, 'owner_id' => $row->owner_id, 'team_season_id' => $row->team_season_id, 'private' => true, 'archived' => true],JSON_THROW_ON_ERROR)]);

            return $planId;
        });
    }
}
