<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ModulePermissionService, TrainingPlanAi, TrainingPlanService};
use Illuminate\Support\Facades\DB;

/** Personal-only policy. The web and integration policies remain unchanged. */
final class MobileTrainingPlanService extends TrainingPlanService
{
    private bool $providerStarted = false;
    private ?string $providerFingerprint = null;
    public function providerStarted(): bool { return $this->providerStarted; }
    public function forWork(string $fingerprint): void { $this->providerFingerprint = $fingerprint; }
    public function teams(Account $actor, Tenant $tenant)
    {
        $season = app(MobileSeasonScope::class)->current($tenant);
        return parent::teams($actor, $tenant)->filter(fn ($team) => $season && (int) $team->season_id === (int) $season->id)->values();
    }

    public function visible(Account $actor, Tenant $tenant)
    {
        $this->authorizeOperation($actor, $tenant, 'view');
        $current = app(MobileSeasonScope::class)->teams(DB::connection('clubunio')->table('clubunio.team_seasons')
            ->where('tenant_id', $tenant->id)->where('active', true), $tenant)->select('id');
        $query = DB::connection('clubunio')->table('clubunio.training_plans')->where('tenant_id', $tenant->id)->whereIn('team_season_id', $current);
        if (! app(ModulePermissionService::class)->allows($actor, $tenant, 'training', 'view')) {
            $teams = $this->teams($actor, $tenant)->pluck('id');
            $query->where(function ($query) use ($actor, $tenant, $teams) {
                $query->where(fn ($q) => $q->where('owner_id', $actor->id)->whereIn('team_season_id', $teams))
                    ->orWhere(fn ($q) => $q->where('shared', true)->where(function ($q) use ($tenant, $teams) {
                        $q->whereExists(fn ($s) => $s->selectRaw('1')->from('clubunio.mobile_training_plan_shares as share')
                            ->where('share.tenant_id', $tenant->id)->whereColumn('share.plan_id', 'training_plans.id')->whereIn('share.team_season_id', $teams))
                            ->orWhere(fn ($s) => $s->whereIn('team_season_id', $teams)->whereNotExists(fn ($x) => $x->selectRaw('1')
                                ->from('clubunio.mobile_training_plan_shares as share')->where('share.tenant_id', $tenant->id)->whereColumn('share.plan_id', 'training_plans.id')));
                    }));
            });
        }
        return $query;
    }

    public function get(Account $actor, Tenant $tenant, int $id, bool $edit = false, string $function = 'update'): object
    {
        $row = parent::get($actor, $tenant, $id);
        if ($edit) {
            abort_unless(in_array($function, ['update', 'approve'], true), 403);
            $this->authorizeOperation($actor, $tenant, $function);
            abort_if($row->archived_at !== null, 409);
            $target = $this->sharedTeam($tenant, $row);
            abort_unless(app(ModulePermissionService::class)->allows($actor, $tenant, 'training', $function)
                || (int) $row->owner_id === (int) $actor->id
                || ((bool) $row->shared && $target && $this->teams($actor, $tenant)->contains('id', $target)), 403);
        }
        return $row;
    }

    public function sharedTeam(Tenant $tenant, object $row): ?int
    {
        if (! $row->shared) { return null; }
        $id = DB::connection('clubunio')->table('clubunio.mobile_training_plan_shares')->where('tenant_id', $tenant->id)->where('plan_id', $row->id)->value('team_season_id');
        return (int) ($id ?? $row->team_season_id);
    }

    public function can(Account $actor, Tenant $tenant, string $function, ?object $row = null): bool
    {
        try {
            $this->authorizeOperation($actor, $tenant, $function);
            if ($row && in_array($function, ['update', 'approve'], true)) { $this->get($actor, $tenant, $row->id, true, $function); }
            if ($row && $function === 'send') {
                $this->get($actor, $tenant, $row->id, true);
                // Sharing is an owner's decision; collaborators cannot delegate access.
                abort_unless((int) $row->owner_id === (int) $actor->id, 403);
            }
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404, 409], true)) { return false; }
            throw $error;
        }
    }

    protected function providerCall(Tenant $tenant, array $input, ?array $draft): array
    {
        abort_unless($this->providerFingerprint !== null, 503);
        $configuration = app(MobileTrainingWork::class)->providerSnapshot($tenant, $this->providerFingerprint);
        $this->providerStarted = true;
        return app(TrainingPlanAi::class)->generate($tenant, $input, $draft, [
            'max_output_tokens' => (int) config('clubunio-mobile.training_ai.max_output_tokens', 0), 'max_tool_calls' => 1,
        ], $configuration);
    }
}
