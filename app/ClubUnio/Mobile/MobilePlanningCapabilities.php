<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\TeamPlanningService;

/** Presentation hints only; each write still performs its own fresh authorization. */
final class MobilePlanningCapabilities
{
    private array $managed = [];

    public function allows(Account $actor, Tenant $tenant, int $team, string $function): bool
    {
        $key = $actor->id.':'.$tenant->id.':'.$function;
        $this->managed[$key] ??= array_fill_keys(app(TeamPlanningService::class)->teams($actor, $tenant, true, $function)->pluck('id')->all(), true);
        return isset($this->managed[$key][$team]);
    }

    public function event(Account $actor, Tenant $tenant, object $event): array
    {
        $active = $event->status === 'AKTIV';
        $can = fn ($function) => $this->allows($actor, $tenant, (int) $event->team_season_id, $function);
        return ['CanEdit' => $active && $can('update'), 'CanAddPeople' => $active && $can('create'),
            'CanCancel' => $active && $can('cancel'), 'CanRemove' => $can('deactivate'),
            'CanShareSquad' => $active && $can('update') && $can('export'),
            // Native delivery is still unavailable; no false "sent" action in personal views.
            'CanSendSquadPush' => false];
    }
}
