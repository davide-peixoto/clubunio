<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrainingPlanService
{
    public function teams(Account $actor, Tenant $tenant)
    {
        abort_unless($actor->fresh()?->active, 403);
        $teams = app(TeamPlanningService::class)->teams($actor, $tenant, true)->orderBy('label')->get();
        abort_unless($teams->isNotEmpty() || $actor->canAccessTenant($tenant), 403);

        return $teams;
    }

    public function visible(Account $actor, Tenant $tenant)
    {
        $teams = $this->teams($actor, $tenant)->pluck('id');
        $q = DB::connection('clubunio')->table('clubora.training_plans')->where('tenant_id', $tenant->id);
        if (! $actor->canAccessTenant($tenant)) {
            $q->whereIn('team_season_id', $teams)->where(fn ($q) => $q->where('owner_id', $actor->id)->orWhere('shared', true));
        }

        return $q;
    }

    public function get(Account $actor, Tenant $tenant, int $id, bool $edit = false): object
    {
        $row = $this->visible($actor, $tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        abort_if($edit && ! $actor->canAccessTenant($tenant) && $row->owner_id !== $actor->id, 403);
        abort_if($edit && $row->archived_at !== null, 409);
        $row->input = json_decode($row->input_json, true, 64, JSON_THROW_ON_ERROR);
        $row->plan = json_decode($row->plan_json, true, 64, JSON_THROW_ON_ERROR);

        return $row;
    }

    public function input(Account $actor, Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, [
            'team_season_id' => 'required|integer', 'durationMinutes' => 'required|integer|min:30|max:120',
            'playerCount' => 'required|integer|min:4|max:30', 'goal' => 'required|string|max:200',
            'fieldSize' => 'required|string|max:120', 'material' => 'required|string|max:500',
        ])->validate();
        $team = $this->teams($actor, $tenant)->firstWhere('id', (int) $data['team_season_id']);
        abort_unless($team, 403);
        $data['teamName'] = $team->label;

        return $data;
    }

    public function generate(Account $actor, Tenant $tenant, array $input, string $key): int
    {
        $input = $this->input($actor, $tenant, $input);
        $attempt = $this->reserve($actor, $tenant, $key, 'generate');
        if ($attempt->state === 'completed' && $attempt->plan_id) {
            return $this->get($actor, $tenant, $attempt->plan_id)->id;
        }
        $plan = $this->call($actor, $tenant, $attempt, $input);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $input, $plan, $attempt) {
            // Permission may have changed during the provider request.
            $this->input($actor, $tenant, $input);
            $id = DB::connection('clubunio')->table('clubora.training_plans')->insertGetId([
                'tenant_id' => $tenant->id, 'owner_id' => $actor->id, 'team_season_id' => $input['team_season_id'],
                'title' => $plan['title'], 'input_json' => json_encode($input, JSON_THROW_ON_ERROR), 'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            ]);
            $this->finish($attempt, 'completed', $id);
            $this->audit($actor, $tenant, $id, 0, 1, 'created');

            return $id;
        });
    }

    public function review(Account $actor, Tenant $tenant, int $id, int $revision, string $key): array
    {
        $row = $this->get($actor, $tenant, $id, true);
        $this->revision($row, $revision);
        $attempt = $this->reserve($actor, $tenant, $key, 'review');
        $plan = $this->call($actor, $tenant, $attempt, $row->input, $row->plan);
        $this->finish($attempt, 'completed', $id);

        return ['plan' => $plan, 'revision' => $row->revision, 'actor' => $actor->id, 'expires' => time() + 1800];
    }

    public function save(Account $actor, Tenant $tenant, int $id, int $revision, array $plan, bool $shared): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $plan, $shared) {
            DB::connection('clubunio')->table('clubora.training_plans')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            $row = $this->get($actor, $tenant, $id, true);
            $this->revision($row, $revision);
            foreach ($plan['exercises'] ?? [] as $i => $exercise) {
                $plan['exercises'][$i]['markers'] ??= $row->plan['exercises'][$i]['markers'] ?? [];
            }
            $plan = app(TrainingPlanContent::class)->validate($plan, (int) $row->input['durationMinutes']);
            DB::connection('clubunio')->table('clubora.training_plans')->where('id', $id)->where('tenant_id', $tenant->id)->update([
                'title' => $plan['title'], 'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR), 'shared' => $shared,
                'revision' => $revision + 1, 'updated_at' => now()->toIso8601String(),
                'legacy_quality_pending' => false,
            ]);
            $this->audit($actor, $tenant, $id, $revision, $revision + 1, $shared ? 'saved_shared' : 'saved_private');
        });
    }

    private function revision(object $row, int $revision): void
    {
        if ($row->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Der Plan wurde inzwischen geändert. Bitte neu laden.']);
        }
    }

    public function archive(Account $actor, Tenant $tenant, int $id, int $revision, bool $archived): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $archived) {
            DB::connection('clubunio')->table('clubora.training_plans')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            $row = $this->get($actor, $tenant, $id);
            abort_unless($actor->canAccessTenant($tenant) || $row->owner_id === $actor->id, 403);
            $this->revision($row, $revision);
            if (($row->archived_at !== null) === $archived) {
                return;
            }
            if (! $archived && $row->legacy_quality_pending) {
                throw ValidationException::withMessages(['revision' => 'Altplan braucht eine aktuelle Qualitätsprüfung. Zuerst eine private Kopie erstellen und prüfen.']);
            }
            DB::connection('clubunio')->table('clubora.training_plans')->where('tenant_id', $tenant->id)->where('id', $id)
                ->update(['archived_at' => $archived ? now() : null, 'updated_at' => now(), 'revision' => $revision + 1]);
            $this->audit($actor, $tenant, $id, $revision, $revision + 1, $archived ? 'archived' : 'restored');
        });
    }

    public function copy(Account $actor, Tenant $tenant, int $id, int $revision, string $key): int
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $key) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.training_plans')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            $row = $this->get($actor, $tenant, $id);
            $this->revision($row, $revision);
            $this->input($actor, $tenant, $row->input);
            $attempt = $this->reserve($actor, $tenant, $key, 'copy:'.$id.':'.$revision);
            if ($attempt->state === 'completed' && $attempt->plan_id) {
                return $this->get($actor, $tenant, $attempt->plan_id)->id;
            }
            $plan = app(TrainingPlanContent::class)->validate($row->plan, (int) $row->input['durationMinutes'], ! $row->legacy_quality_pending);
            $plan['title'] = mb_substr($plan['title'], 0, 150).' (Kopie)';
            $new = DB::connection('clubunio')->table('clubora.training_plans')->insertGetId([
                'tenant_id' => $tenant->id, 'owner_id' => $actor->id, 'team_season_id' => $row->team_season_id,
                'title' => $plan['title'], 'input_json' => $row->input_json, 'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR), 'shared' => false,
                'legacy_quality_pending' => $row->legacy_quality_pending,
            ]);
            $this->finish($attempt, 'completed', $new);
            $this->audit($actor, $tenant, $new, 0, 1, 'copied');

            return $new;
        });
    }

    private function reserve(Account $actor, Tenant $tenant, string $key, string $kind): object
    {
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $key, $kind) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $q = DB::connection('clubunio')->table('clubora.training_plan_requests')->where('tenant_id', $tenant->id);
            $old = (clone $q)->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($old) {
                if ($old->state === 'completed' && ($kind === 'generate' || str_starts_with($kind, 'copy:')) && $old->kind === $kind) {
                    return $old;
                }
                throw ValidationException::withMessages(['request' => 'Diese Anfrage wurde bereits gesendet. Bitte neu laden.']);
            }
            $copy = str_starts_with($kind, 'copy:');
            $quota = (clone $q)->where('kind', $copy ? 'like' : 'not like', 'copy:%');
            if ((clone $quota)->where('actor_id', $actor->id)->where('created_at', '>', now()->subMinute()->toIso8601String())->count() >= ($copy ? 10 : 1)
                || (clone $quota)->where('actor_id', $actor->id)->where('created_at', '>', now()->subDay()->toIso8601String())->count() >= ($copy ? 100 : 20)
                || (clone $quota)->where('created_at', '>', now()->subDay()->toIso8601String())->count() >= ($copy ? 500 : 100)) {
                throw ValidationException::withMessages(['request' => 'Das Anfragelimit ist erreicht. Bitte später erneut versuchen.']);
            }
            $id = DB::connection('clubunio')->table('clubora.training_plan_requests')->insertGetId([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'request_key' => $key, 'kind' => $kind, 'reference' => (string) Str::uuid(),
            ]);

            return (clone $q)->where('id', $id)->first();
        });
    }

    private function call(Account $actor, Tenant $tenant, object $attempt, array $input, ?array $draft = null): array
    {
        unset($input['team_season_id']);
        try {
            return app(TrainingPlanAi::class)->generate($tenant, $input, $draft);
        } catch (\Throwable $error) {
            $this->finish($attempt, 'failed');
            DB::connection('clubunio')->table('clubora.training_plan_requests')->where('id', $attempt->id)->update(['provider_status' => (int) $error->getCode()]);
            app(SyncWorkService::class)->remember($actor, $tenant, 'training_review', $attempt->id, ['input' => $input]);
            Log::warning('clubunio.training.failed', ['reference' => $attempt->reference, 'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'status' => (int) $error->getCode()]);
            throw ValidationException::withMessages(['request' => 'Der Trainingsplan konnte nicht erstellt werden. Referenz: '.$attempt->reference]);
        }
    }

    private function finish(object $attempt, string $state, ?int $id = null): void
    {
        DB::connection('clubunio')->table('clubora.training_plan_requests')->where('id', $attempt->id)->update(['state' => $state, 'plan_id' => $id]);
    }

    private function audit(Account $actor, Tenant $tenant, int $id, int $before, int $after, string $event): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'training_plan', 'object_id' => $id,
            'before_value' => json_encode(['revision' => $before]), 'after_value' => json_encode(['revision' => $after, 'event' => $event]),
        ]);
    }
}
