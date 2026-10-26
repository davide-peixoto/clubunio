<?php

namespace App\ClubUnio\Api\Providers;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SfvApiActions
{
    public function definitions(): array
    {
        $selection = ['target_id' => 'sometimes|nullable|integer|min:1', 'separate' => 'sometimes|boolean'];

        return [
            'sfv.refresh' => ['title' => 'SFV-Spielplan aktualisieren', 'approval' => 'direct',
                'scopes' => ['competitions:sync' => ['competitions', 'sync']],
                'fields' => ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']],
            'sfv.competition.preview' => ['title' => 'SFV-Wettkampf prüfen', 'approval' => 'direct',
                'scopes' => ['competitions:read' => ['competitions', 'view']], 'fields' => $selection],
            'sfv.competition.apply' => ['title' => 'Geprüften SFV-Wettkampf übernehmen', 'approval' => 'direct',
                'scopes' => ['competitions:read' => ['competitions', 'view'], 'competitions:sync' => ['competitions', 'sync'],
                    'signage:send' => ['signage', 'send']],
                'fields' => $selection + ['fingerprint' => 'required|string|regex:/\A[a-f0-9]{64}\z/']],
            'sfv.planning-import' => ['title' => 'SFV-Spiel in Teamplanung übernehmen', 'approval' => 'direct',
                // import() resolves manageable teams with the default update operation, even on create.
                'scopes' => ['competitions:read' => ['competitions', 'view'], 'planning:read' => ['planning', 'view'],
                    'planning:sync' => ['planning', 'sync'], 'planning:update' => ['planning', 'update'],
                    'planning:send' => ['planning', 'send']],
                'conditional_scopes' => ['planning:create' => 'New event only',
                    'planning:cancel' => 'Newly cancelled status', 'planning:restore' => 'Restoring cancelled event'],
                'fields' => ['team_season_id' => 'required|integer|min:1', 'sfv_revision' => 'required|integer|min:1',
                    'event_revision' => 'required|integer|min:0', 'title' => 'required|string|max:255',
                    'kind' => 'required|string|max:100', 'mode' => 'required|string|max:100', 'status' => 'required|string|max:100',
                    'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
                    'deadline_at' => 'required|date_format:Y-m-d\TH:i|before_or_equal:starts_at',
                    'capacity' => 'present|nullable|integer|min:1|max:1000', 'location' => 'sometimes|nullable|string|max:255',
                    'description' => 'sometimes|nullable|string|max:4000']],
        ];
    }

    public function validate(ApiContext $c, string $action, int $objectId, array $input): array
    {
        return $this->fresh(fn () => $this->validateInput($c, $action, $objectId, $input));
    }

    private function validateInput(ApiContext $c, string $action, int $objectId, array $input): array
    {
        $definition = $this->definitions()[$action] ?? abort(404);
        foreach ($definition['scopes'] as $scope => [$module, $function]) {
            $c->authorize($scope, $module, $function);
        }
        if (array_diff(array_keys($input), array_keys($definition['fields']))) {
            throw ValidationException::withMessages(['input' => 'Unknown or protected fields.']);
        }
        abort_unless($action === 'sfv.refresh' ? $objectId === 0 : $objectId > 0, 422);
        $data = Validator::make($input, $definition['fields'])->validate();
        if ($action === 'sfv.refresh') {
            if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 186) {
                throw ValidationException::withMessages(['to' => 'Maximum 186 days per refresh.']);
            }

            return $data;
        }
        if ($action === 'sfv.planning-import') {
            return $this->planningInput($c, $objectId, $data);
        }
        $data['target_id'] = isset($data['target_id']) ? (int) $data['target_id'] : null;
        $data['separate'] = (bool) ($data['separate'] ?? false);
        $preview = $this->preview($c, $objectId, $data);
        if ($action === 'sfv.competition.apply' && ! hash_equals($preview['fingerprint'], $data['fingerprint'])) {
            throw ValidationException::withMessages(['fingerprint' => 'SFV data or competition changed. Request a new preview.']);
        }

        return $data;
    }

    public function execute(ApiContext $c, string $action, int $objectId, array $input, ?Account $executor = null): array
    {
        if ($action === 'sfv.refresh') {
            abort_if(DB::connection('clubunio')->transactionLevel() !== 0, 409, 'Provider execution requires a committed work request.');
        }

        return $this->fresh(fn () => $this->run($c, $action, $objectId, $input));
    }

    private function run(ApiContext $c, string $action, int $objectId, array $input): array
    {
        // The worker is not a substitute identity for the original integration.
        unset($input['_request_key']);
        if ($action === 'sfv.competition.apply') {
            return DB::connection('clubunio')->transaction(function () use ($c, $action, $objectId, $input) {
                app(TeamPlanningService::class)->lock($c->tenant);
                $data = $this->validate($c, $action, $objectId, $input);
                $preview = $this->preview($c, $objectId, $data);
                // Keep the reviewed fingerprint; apply() performs its own nested revision check.
                $preview['fingerprint'] = $data['fingerprint'];

                return ['competition_id' => app(SfvCompetitionService::class)->apply($c->actor, $c->tenant, $preview)];
            });
        }
        $data = $this->validate($c, $action, $objectId, $input);
        if ($action === 'sfv.refresh') {
            $authorization = function () use ($c, $action): void {
                foreach ($this->definitions()[$action]['scopes'] as $scope => [$module, $function]) {
                    $c->authorize($scope, $module, $function);
                }
            };

            return ['refreshed' => app(SfvPlanningService::class)->refreshNow($c->actor, $c->tenant, $data, $authorization)];
        }
        if ($action === 'sfv.planning-import') {
            // This action is entirely local: serialize identity, conditional rights and revisions with import().
            return DB::connection('clubunio')->transaction(function () use ($c, $action, $objectId, $data) {
                $planning = app(TeamPlanningService::class);
                $planning->lock($c->tenant);
                $data = $this->validate($c, $action, $objectId, $data);
                $matchId = $planning->table($c->tenant, 'sfv_schedule')->where('id', $objectId)->value('match_id');
                $old = $planning->table($c->tenant, 'team_events')->where('team_season_id', $data['team_season_id'])
                    ->where('sfv_match_id', $matchId)->first();
                $scopes = $this->planningScopes($old, $data);
                $eventId = app(SfvPlanningService::class)->import($c->actor, $c->tenant, $objectId, $data);
                // Recheck the original transition, not the now-updated event, before committing local writes.
                foreach ($scopes as $scope => [$module, $function]) {
                    $c->authorize($scope, $module, $function);
                }

                return ['event_id' => $eventId];
            });
        }

        return $this->preview($c, $objectId, $data);
    }

    private function preview(ApiContext $c, int $id, array $data): array
    {
        return app(SfvCompetitionService::class)->review($c->actor, $c->tenant, $id, $data['target_id'], $data['separate']);
    }

    private function planningInput(ApiContext $c, int $id, array $data): array
    {
        foreach (['team_season_id', 'sfv_revision', 'event_revision', 'capacity'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = (int) $data[$field];
            }
        }
        $planning = app(TeamPlanningService::class);
        $match = $planning->table($c->tenant, 'sfv_schedule')->where('id', $id)->first();
        abort_unless($match, 404);
        $planning->conflict($match, ['revision' => $data['sfv_revision']]);
        $team = $planning->teams($c->actor, $c->tenant, true)->where('id', $data['team_season_id'])->first();
        abort_unless($team, 403);
        $sfvTeam = (int) $planning->table($c->tenant, 'teams')->where('id', $team->team_id)->value('sfv_team_id');
        if (! $sfvTeam || ! in_array($sfvTeam, [(int) $match->team_a_id, (int) $match->team_b_id], true)) {
            throw ValidationException::withMessages(['team_season_id' => 'The team SFV identity does not match this schedule entry.']);
        }
        $events = $planning->table($c->tenant, 'team_events')->where('team_season_id', $team->id)
            ->where('sfv_match_id', $match->match_id)->limit(2)->get();
        abort_if($events->count() > 1, 409);
        $old = $events->first();
        $planning->conflict($old, ['revision' => $data['event_revision']]);
        abort_if(! $old && $data['event_revision'] !== 0, 409);
        foreach ($this->planningScopes($old, $data) as $scope => [$module, $function]) {
            $c->authorize($scope, $module, $function);
        }
        foreach (['kind', 'mode', 'status'] as $category) {
            $known = $planning->table($c->tenant, 'planning_options')->where('category', $category)
                ->where('active', true)->where('code', $data[$category])->exists();
            if (! $known) {
                throw ValidationException::withMessages([$category => 'Unknown or inactive planning option.']);
            }
        }

        return $data;
    }

    private function planningScopes(?object $old, array $data): array
    {
        $scopes = $this->definitions()['sfv.planning-import']['scopes'];
        if (! $old) {
            $scopes['planning:create'] = ['planning', 'create'];
        }
        if ($data['status'] === 'ABGESAGT' && $old?->status !== 'ABGESAGT') {
            $scopes['planning:cancel'] = ['planning', 'cancel'];
        }
        if ($old?->status === 'ABGESAGT' && $data['status'] === 'AKTIV') {
            $scopes['planning:restore'] = ['planning', 'restore'];
        }

        return $scopes;
    }

    private function fresh(Closure $operation): array
    {
        $request = request();
        $previous = $request->attributes->get(BackofficeReadContext::class);
        $request->attributes->remove(BackofficeReadContext::class);
        try {
            return $operation();
        } finally {
            $request->attributes->remove(BackofficeReadContext::class);
            if ($previous !== null) {
                $request->attributes->set(BackofficeReadContext::class, $previous);
            }
        }
    }
}
