<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeasonAssignmentTransferService
{
    public function preview(Account $actor, Tenant $tenant, int $source, int $target, ?array $teamSeasonIds = null): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'view');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'seasons', 'view');
        Season::where('tenant_id', $tenant->id)->findOrFail($source);
        Season::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($target);
        if ($source === $target) {
            throw ValidationException::withMessages(['target_season_id' => 'Bitte eine andere Zielsaison wählen.']);
        }
        $sources = TeamSeason::where('tenant_id', $tenant->id)->where('season_id', $source)->where('active', true)->with('team')->orderBy('id')->get();
        if ($teamSeasonIds !== null) {
            $teamSeasonIds = array_values(array_unique(array_map('intval', $teamSeasonIds)));
            if (! $teamSeasonIds || array_diff($teamSeasonIds, $sources->pluck('id')->all())) {
                throw ValidationException::withMessages(['team_season_ids' => 'Bitte gültige Mannschaften dieser Quellsaison auswählen.']);
            }
            $sources = $sources->whereIn('id', $teamSeasonIds)->values();
        }
        $targets = TeamSeason::where('tenant_id', $tenant->id)->where('season_id', $target)->orderBy('id')->get();
        $targetsByTeam = $targets->groupBy('team_id');
        $relevantTargets = $targets->whereIn('team_id', $sources->pluck('team_id'));
        $assignmentTeamIds = $sources->pluck('id')->merge($relevantTargets->pluck('id'));
        $models = [TeamPlayer::class => 'Spieler', TeamStaff::class => 'Staff', TechnicalLead::class => 'Technische Leitung'];
        $assignmentsByModel = [];
        $memberIds = collect();
        foreach ($models as $model => $label) {
            $assignmentsByModel[$model] = $model::where('tenant_id', $tenant->id)->whereIn('team_season_id', $assignmentTeamIds)->orderBy('id')->get();
            $memberIds = $memberIds->merge($assignmentsByModel[$model]->pluck('member_id'));
        }
        $members = Member::where('tenant_id', $tenant->id)->whereIn('id', $memberIds->unique())->get()->keyBy('id');
        foreach ($assignmentsByModel as $model => $assignments) {
            foreach ($assignments as $assignment) {
                $assignment->setRelation('member', $members->get($assignment->member_id));
            }
            $assignmentsByModel[$model] = $assignments->groupBy('team_season_id');
        }
        $activeMembers = array_fill_keys(app(MemberAccessService::class)->activeMembers($tenant)->whereIn('id', $memberIds->unique())->pluck('id')->all(), true);
        $validFunctions = array_fill_keys(DB::connection('clubunio')->table('clubora.staff_functions')->where('tenant_id', $tenant->id)->where('active', true)->pluck('id')->all(), true);
        $rows = [];
        $seen = [];
        $fingerprint = [$actor->id, $tenant->id, $source, $target, $sources->toArray(), $targets->toArray()];
        foreach ($sources as $team) {
            $matches = $targetsByTeam->get($team->team_id, collect());
            $destination = $matches->first();
            $key = 'team:'.$team->team_id;
            $blocked = ! $team->team?->active || $matches->count() > 1 || ($destination && ! $destination->active);
            $rows[] = ['type' => 'Mannschaft', 'label' => $team->label, 'state' => $blocked ? 'blockiert' : (($destination || isset($seen[$key])) ? 'vorhanden' : 'neu'), 'reason' => $blocked ? 'Mannschaft inaktiv oder Zielzuordnung inaktiv/mehrdeutig' : '', 'model' => TeamSeason::class, 'source_id' => $team->id, 'team_id' => $team->team_id, 'data' => ['team_id' => $team->team_id, 'season_id' => $target, 'active' => true]];
            $seen[$key] = true;
            foreach ($models as $model => $label) {
                $assignments = $assignmentsByModel[$model]->get($team->id, collect())->where('active', true)->values();
                $existing = $destination ? $assignmentsByModel[$model]->get($destination->id, collect()) : collect();
                $existingByMember = $existing->groupBy('member_id');
                $fingerprint[] = [$assignments->toArray(), $existing->toArray()];
                foreach ($assignments as $assignment) {
                    $data = ['member_id' => $assignment->member_id, 'active' => true];
                    $duplicates = $existingByMember->get($assignment->member_id, collect());
                    $invalidFunction = false;
                    if ($model === TeamStaff::class) {
                        $data['staff_function_id'] = $assignment->staff_function_id;
                        $duplicates = $duplicates->where('staff_function_id', $assignment->staff_function_id);
                        $invalidFunction = ! isset($validFunctions[$assignment->staff_function_id]);
                    }
                    $key = $model.':'.$team->team_id.':'.$assignment->member_id.':'.($data['staff_function_id'] ?? '');
                    $state = $blocked || $invalidFunction || ! isset($activeMembers[$assignment->member_id]) ? 'blockiert' : ($duplicates->isNotEmpty() || isset($seen[$key]) ? 'vorhanden' : 'neu');
                    $reason = $blocked ? 'Mannschaft blockiert' : ($invalidFunction ? 'Staff-Funktion fehlt oder ist inaktiv' : (! isset($activeMembers[$assignment->member_id]) ? 'Mitglied ausgetreten oder archiviert' : ($duplicates->where('active', false)->isNotEmpty() ? 'Inaktive Zielzuordnung bleibt unverändert' : '')));
                    $rows[] = ['type' => $label, 'label' => $team->label.' / '.$assignment->member?->display_name, 'state' => $state, 'reason' => $reason, 'model' => $model, 'source_id' => $assignment->id, 'team_id' => $team->team_id, 'data' => $data];
                    $seen[$key] = true;
                }
            }
        }
        if (count($rows) > 1000) {
            throw ValidationException::withMessages(['target_season_id' => 'Maximal 1000 Zuordnungen pro Saisonübernahme.']);
        }

        return ['rows' => $rows, 'token' => hash_hmac('sha256', json_encode([$fingerprint, $rows], JSON_THROW_ON_ERROR), config('app.key'))];
    }

    public function apply(Account $actor, Tenant $tenant, int $source, int $target, string $token, ?array $teamSeasonIds = null): int
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'transfer');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'seasons', 'transfer');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $source, $target, $token, $teamSeasonIds) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'transfer');
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'seasons', 'transfer');
            $plan = $this->preview($actor, $tenant, $source, $target, $teamSeasonIds);
            if (! hash_equals($plan['token'], $token)) {
                throw ValidationException::withMessages(['target_season_id' => 'Die Daten haben sich geändert. Bitte Vorschau erneut erstellen.']);
            }
            if (collect($plan['rows'])->contains('state', 'blockiert')) {
                throw ValidationException::withMessages(['target_season_id' => 'Blockierte Zuordnungen zuerst bereinigen. Es wurde nichts übernommen.']);
            }
            $teams = TeamSeason::where('tenant_id', $tenant->id)->where('season_id', $target)->where('active', true)->pluck('id', 'team_id')->all();
            $count = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['state'] !== 'neu') {
                    continue;
                }
                $data = $row['data'];
                if ($row['model'] !== TeamSeason::class) {
                    $data['team_season_id'] = $teams[$row['team_id']];
                }
                $record = app(TeamAssignmentService::class)->save($actor->fresh(), $tenant, $row['model'], null, $data);
                if ($row['model'] === TeamSeason::class) {
                    $teams[$row['team_id']] = $record->id;
                }
                $count++;
            }

            return $count;
        });
    }
}
