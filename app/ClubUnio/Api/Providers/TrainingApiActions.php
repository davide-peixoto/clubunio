<?php

namespace App\ClubUnio\Api\Providers;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\TrainingPlanContent;
use App\ClubUnio\Services\TrainingPlanService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TrainingApiActions
{
    public function definitions(): array
    {
        return [
            'training.generate' => ['title' => 'KI-Trainingsplan erstellen', 'approval' => 'direct',
                'scopes' => ['training:read' => ['training', 'view'], 'training:create' => ['training', 'create']],
                'fields' => ['team_season_id' => 'required|integer|min:1', 'durationMinutes' => 'required|integer|min:30|max:120',
                    'playerCount' => 'required|integer|min:4|max:30', 'goal' => 'required|string|max:200',
                    'fieldSize' => 'required|string|max:120', 'material' => 'required|string|max:500']],
            'training.review' => ['title' => 'KI-Trainingsplan prüfen', 'approval' => 'direct',
                'scopes' => ['training:read' => ['training', 'view'], 'training:approve' => ['training', 'approve']],
                'fields' => ['revision' => 'required|integer|min:1']],
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
        abort_unless($action === 'training.generate' ? $objectId === 0 : $objectId > 0, 422);
        $data = Validator::make($input, $definition['fields'])->validate();
        $service = app(TrainingPlanService::class);
        if ($action === 'training.generate') {
            foreach (['team_season_id', 'durationMinutes', 'playerCount'] as $field) {
                $data[$field] = (int) $data[$field];
            }
            // Resolves the active, tenant-owned team without accepting a client teamName.
            $service->input($c->actor, $c->tenant, $data);
        } else {
            $data['revision'] = (int) $data['revision'];
            $row = $service->get($c->actor, $c->tenant, $objectId, true, 'approve');
            $this->revision($row, $data['revision']);
        }

        return $data;
    }

    public function execute(ApiContext $c, string $action, int $objectId, array $input, ?Account $executor = null): array
    {
        abort_if(DB::connection('clubunio')->transactionLevel() !== 0, 409, 'Provider execution requires a committed work request.');

        // Always retain the integration identity, even if a worker supplies an executor.
        return $this->fresh(fn () => $this->run($c, $action, $objectId, $input));
    }

    private function run(ApiContext $c, string $action, int $objectId, array $input): array
    {
        // Persisted by the parent queue, never generated here or accepted from a client.
        $key = $input['_request_key'] ?? null;
        unset($input['_request_key']);
        $data = $this->validate($c, $action, $objectId, $input);
        Validator::make(['_request_key' => $key], ['_request_key' => 'required|string|uuid'])->validate();
        $service = app(TrainingPlanService::class);
        $authorization = function () use ($c, $action): void {
            foreach ($this->definitions()[$action]['scopes'] as $scope => [$module, $function]) {
                $c->authorize($scope, $module, $function);
            }
        };
        // reserve() enforces quotas and blocks failed/running/review replays before calling AI.
        if ($action === 'training.generate') {
            return ['plan_id' => $service->generate($c->actor, $c->tenant, $data, $key, $authorization)];
        }
        $review = $service->review($c->actor, $c->tenant, $objectId, $data['revision'], $key, $authorization);
        $row = $service->get($c->actor, $c->tenant, $objectId, true, 'approve');
        $this->revision($row, $data['revision']);

        return ['plan_id' => $objectId, 'revision' => $data['revision'], 'expires' => (int) $review['expires'],
            'plan' => app(TrainingPlanContent::class)->validate($review['plan'], (int) $row->input['durationMinutes'])];
    }

    private function revision(object $row, int $revision): void
    {
        if ((int) $row->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'The training plan changed. Reload before reviewing.']);
        }
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
