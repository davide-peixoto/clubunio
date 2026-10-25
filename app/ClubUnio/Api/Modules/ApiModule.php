<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\CatalogService;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\DossierMaintenanceService;
use App\ClubUnio\Services\FacilityService;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\TeamAssignmentService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ApiModule
{
    public function __construct(private readonly array $definition) {}

    public function definition(): array
    {
        return array_diff_key($this->definition, ['service' => true]) + [
            'module' => $this->definition['permission_module'], 'fields' => $this->definition['read_fields'],
            'search' => $this->definition['search_fields'], 'filters' => $this->definition['filter_fields'],
            'write_fields' => array_values(array_unique([...$this->definition['create_fields'], ...$this->definition['update_fields']])),
            'create' => $this->definition['create_fields'] !== [], 'update' => $this->definition['update_fields'] !== [],
        ];
    }

    public function query(ApiContext $context): Builder
    {
        return $this->fresh(function () use ($context) {
            $this->authorize($context, 'read', 'view');

            return $this->recordQuery($context)->select($this->definition['read_fields']);
        });
    }

    public function authorizeOperation(ApiContext $context, string $operation): void
    {
        if (in_array($operation, ['create', 'update'], true)) {
            abort_if($this->definition[$operation.'_fields'] === [], 405, 'Unsupported module operation.');
        }
        $this->fresh(function () use ($context, $operation) {
            [$scope, $function] = match ($operation) {
                'list', 'show', 'read' => ['read', 'view'],
                'create' => ['create', 'create'], 'update' => ['update', 'update'],
                'archive' => $this->definition['key'] === 'members' ? ['deactivate', 'deactivate'] : abort(404),
                default => abort(404, 'Unsupported module operation.'),
            };
            $this->authorize($context, $scope, $function);
        });
    }

    public function list(ApiContext $context, array $input = []): array
    {
        return $this->fresh(function () use ($context, $input) {
            $this->authorize($context, 'read', 'view');
            $this->allowKeys($input, ['page', 'per_page', 'search', 'filters', 'sort']);
            $data = Validator::make($input, ['page' => 'sometimes|required|integer|min:1|max:10000',
                'per_page' => 'sometimes|required|integer|min:1|max:100', 'search' => 'sometimes|string|max:200',
                'filters' => 'sometimes|array', 'sort' => ['sometimes', 'required', 'string', Rule::in([
                    ...$this->definition['sort_fields'], ...array_map(fn ($field) => '-'.$field, $this->definition['sort_fields']),
                ])]])->validate();
            $query = $this->recordQuery($context);
            $filters = $data['filters'] ?? [];
            $this->allowKeys($filters, $this->definition['filter_fields']);
            foreach ($filters as $field => $value) {
                $rules = match (true) {
                    in_array($field, ['active', 'blocks_training', 'website_visible', 'show_on_signage'], true) => 'required|boolean',
                    str_ends_with($field, '_id'), $field === 'club_number' => 'required|integer|min:1',
                    in_array($field, ['plays_on', 'shows_on'], true) => 'required|date_format:Y-m-d',
                    $field === 'category' => ['required', Rule::in(['period', 'weekday', 'field', 'room', 'side'])],
                    default => 'required|string|max:80',
                };
                Validator::make([$field => $value], [$field => $rules])->validate();
                $query->where($field, $value);
            }
            $search = trim($data['search'] ?? '');
            if ($search !== '') {
                if ($this->definition['search_fields'] === []) {
                    throw ValidationException::withMessages(['search' => 'Search is unsupported for this module.']);
                }
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function ($q) use ($pattern) {
                    foreach ($this->definition['search_fields'] as $field) {
                        $q->orWhere($field, 'ilike', $pattern);
                    }
                });
            }
            $page = (int) ($data['page'] ?? 1);
            $perPage = (int) ($data['per_page'] ?? 25);
            $sort = $data['sort'] ?? 'id';
            $descending = str_starts_with($sort, '-');
            $field = $descending ? substr($sort, 1) : $sort;
            $query->orderBy($field, $descending ? 'desc' : 'asc');
            if ($field !== 'id') {
                $query->orderBy('id');
            }
            $rows = $query->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

            return ['data' => $rows->take($perPage)->map(fn ($row) => $this->serialize($row))->values()->all(),
                'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $rows->count() > $perPage]];
        });
    }

    public function show(ApiContext $context, int $id): array
    {
        return $this->fresh(function () use ($context, $id) {
            $this->authorize($context, 'read', 'view');

            return $this->serialize($this->recordQuery($context)->findOrFail($id));
        });
    }

    public function create(ApiContext $context, array $input): array
    {
        return $this->write($context, null, $input);
    }

    public function update(ApiContext $context, int $id, array $input): array
    {
        return $this->write($context, $id, $input);
    }

    public function action(ApiContext $context, int $id, string $action, array $input = []): array
    {
        abort_unless(isset($this->definition['actions'][$action]), 404, 'Unsupported module action.');

        return $this->fresh(fn () => DB::connection('clubunio')->transaction(function () use ($context, $id, $input) {
            $this->authorize($context, 'deactivate', 'deactivate');
            $this->allowKeys($input, ['_version']);
            $data = Validator::make($input, ['_version' => 'required|string|size:64'])->validate();
            $this->recordQuery($context)->findOrFail($id);
            app(MemberService::class)->archive($context->actor, $context->tenant, $id, $data['_version']);

            return ['id' => $id, 'archived' => true];
        }));
    }

    private function write(ApiContext $context, ?int $id, array $input): array
    {
        abort_if($this->definition[$id === null ? 'create_fields' : 'update_fields'] === [], 405, 'Unsupported module operation.');

        return $this->fresh(fn () => DB::connection('clubunio')->transaction(function () use ($context, $id, $input) {
            $this->authorize($context, $id === null ? 'create' : 'update', $id === null ? 'create' : 'update');
            $this->allowKeys($input, $this->definition[$id === null ? 'create_fields' : 'update_fields']);
            // Keep scope-sensitive status checks and service writes under the same lock.
            Tenant::whereKey($context->tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id === null ? null : $this->recordQuery($context)->lockForUpdate()->findOrFail($id);
            if ($id !== null && $this->definition['service'] === 'catalog') {
                $validated = Validator::make($input, ['_version' => 'required|string|size:64'])->validate();
                if (! hash_equals(FamilyService::version($record), $validated['_version'])) {
                    throw ValidationException::withMessages(['_version' => 'Record changed. Reload before updating.']);
                }
                unset($input['_version']);
            }
            // Services check these permissions as well; token scopes must also cover the extra effects.
            if ($record && array_key_exists('active', $input) && (bool) $record->active !== (bool) $input['active']) {
                $effect = $input['active'] ? 'restore' : 'deactivate';
                $this->authorize($context, $effect, $effect);
            }
            if ($this->definition['service'] === 'member' && $record) {
                $oldExit = $record->exit_date?->format('Y-m-d');
                if (array_key_exists('exit_date', $input) && $oldExit !== $input['exit_date']) {
                    $effect = $input['exit_date'] ? 'deactivate' : 'restore';
                    $this->authorize($context, $effect, $effect);
                }
                if (array_key_exists('status_id', $input)) {
                    Validator::make($input, ['status_id' => 'nullable|integer|min:1'])->validate();
                }
                if (array_key_exists('status_id', $input) && (string) $record->status_id !== (string) $input['status_id']) {
                    // Status transitions can implicitly change exit_date in MemberService.
                    $status = MemberStatus::where('tenant_id', $context->tenant->id)->find($input['status_id']);
                    if ($status?->ends_membership || $status?->restores_membership) {
                        $effect = $status->ends_membership ? 'deactivate' : 'restore';
                        $this->authorize($context, $effect, $effect);
                    }
                }
            }
            if ($this->definition['key'] === 'competitions'
                && (($record?->show_on_signage ?? false) || ($input['show_on_signage'] ?? false))) {
                $context->authorize('signage:send', 'signage', 'send');
            }
            $actor = $context->actor;
            $tenant = $context->tenant;
            $model = $this->definition['model'];
            $saved = match ($this->definition['service']) {
                'member' => $id === null ? app(MemberService::class)->create($actor, $tenant, $input)
                    : app(MemberService::class)->update($actor, $tenant, $id, $input),
                'catalog' => app(CatalogService::class)->save($actor, $tenant, $model, $id, $input),
                'assignment' => app(TeamAssignmentService::class)->save($actor, $tenant, $model, $id, $input),
                'training' => app(ContributionTrainingService::class)->save($actor, $tenant, $model, $id, $input),
                'facility' => app(FacilityService::class)->save($actor, $tenant, $model, $id, $input),
                'dossier' => app(DossierMaintenanceService::class)->save($actor, $tenant, $model, null, $id, $input),
            };

            return $this->serialize($saved->refresh());
        }));
    }

    private function authorize(ApiContext $context, string $scope, string $function): void
    {
        abort_unless(app(MemberAccessService::class)->membership($context->actor, $context->tenant)?->role === 'profile', 403);
        $context->authorize($this->definition['permission_module'].':'.$scope, $this->definition['permission_module'], $function);
    }

    private function recordQuery(ApiContext $context): Builder
    {
        // Profile module rights cover the tenant, never a personal role's represented members.
        abort_unless(app(MemberAccessService::class)->membership($context->actor, $context->tenant)?->role === 'profile', 403);
        $model = $this->definition['model'];
        $query = $model::withoutGlobalScopes()->where('tenant_id', $context->tenant->id);

        return $model === Member::class ? $query->whereNull('archived_at') : $query;
    }

    public function serialize(Model $record): array
    {
        $data = [];
        foreach ($this->definition['read_fields'] as $field) {
            $value = $record->getAttribute($field);
            $data[$field] = $value instanceof \DateTimeInterface
                ? $value->format(str_ends_with($field, '_at') ? DATE_ATOM : 'Y-m-d') : $value;
        }
        if ($this->definition['version'] === '_version') {
            $data['_version'] = $record instanceof Member ? MemberService::version($record) : FamilyService::version($record);
        }

        return $data;
    }

    private function allowKeys(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields)) {
            throw ValidationException::withMessages(['input' => 'Unknown or protected fields.']);
        }
    }

    private function fresh(Closure $operation): mixed
    {
        // BackofficeReadContext::withoutSnapshot only handles POST; API GET/PATCH need this too.
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
