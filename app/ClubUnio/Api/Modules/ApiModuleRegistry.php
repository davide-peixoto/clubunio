<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Services\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class ApiModuleRegistry
{
    public function definitions(): array
    {
        $core = array_map(fn ($definition) => (new ApiModule($definition))->definition(), ApiModuleDefinitions::all());
        $workflow = app(WorkflowApiModules::class)->definitions();
        $auxiliary = app(AuxiliaryApiModules::class)->definitions();
        if (array_intersect_key($core, $workflow) || array_intersect_key($core + $workflow, $auxiliary)) {
            throw new \LogicException('Duplicate API resource ownership.');
        }
        foreach ($workflow as &$definition) {
            foreach ($definition['actions'] as &$action) {
                if (is_string($action)) {
                    $action = ['function' => $action, 'fields' => []];
                }
            }
            unset($action);
        }
        unset($definition);
        $definitions = $core + $workflow + $auxiliary;
        foreach ($definitions as &$definition) {
            foreach (['create', 'update'] as $operation) {
                if (! isset(PermissionCatalog::functions($definition['permission_module'])[$operation])) {
                    $definition[$operation.'_fields'] = [];
                }
                $definition[$operation] = $definition[$operation.'_fields'] !== [];
            }
        }
        unset($definition);

        return $definitions;
    }

    public function unsupported(): array
    {
        $covered = ['reports', ...array_column($this->definitions(), 'permission_module')];

        return array_diff_key(ApiModuleDefinitions::unsupported(), array_flip($covered))
            + app(WorkflowApiModules::class)->unsupported() + app(AuxiliaryApiModules::class)->unsupported();
    }

    public function authorize(ApiContext $context, string $module, string $operation): void
    {
        $definition = $this->definition($module);
        if (in_array($operation, ['create', 'update'], true)) {
            abort_if($definition[$operation.'_fields'] === [], 405, 'Unsupported module operation.');
        }
        if ($this->isCore($module)) {
            $this->module($module)->authorizeOperation($context, $operation);
        } else {
            $this->adapter($module)->authorize($context, $module, $operation);
        }
    }

    public function module(string $key): ApiModule
    {
        $definition = ApiModuleDefinitions::all()[$key] ?? null;
        abort_unless($definition, 404, 'Unsupported API module.');

        return new ApiModule($definition);
    }

    public function list(ApiContext $context, string $module, array $query = []): array
    {
        $this->definition($module);

        return $this->isCore($module) ? $this->module($module)->list($context, $query)
            : $this->adapter($module)->list($context, $module, $query);
    }

    public function query(ApiContext $context, string $resource): Builder|QueryBuilder
    {
        $this->definition($resource);

        return $this->isCore($resource) ? $this->module($resource)->query($context)
            : $this->adapter($resource)->query($context, $resource);
    }

    public function show(ApiContext $context, string $module, int $id): array
    {
        $this->definition($module);

        return $this->isCore($module) ? $this->module($module)->show($context, $id)
            : $this->adapter($module)->show($context, $module, $id);
    }

    public function create(ApiContext $context, string $module, array $input): array
    {
        $this->authorize($context, $module, 'create');

        return $this->isCore($module) ? $this->module($module)->create($context, $input)
            : $this->adapter($module)->create($context, $module, $input);
    }

    public function update(ApiContext $context, string $module, int $id, array $input): array
    {
        $this->authorize($context, $module, 'update');

        return $this->isCore($module) ? $this->module($module)->update($context, $id, $input)
            : $this->adapter($module)->update($context, $module, $id, $input);
    }

    public function action(ApiContext $context, string $module, int $id, string $action, array $input = []): array
    {
        $this->definition($module);

        return $this->isCore($module) ? $this->module($module)->action($context, $id, $action, $input)
            : $this->adapter($module)->action($context, $module, $id, $action, $input);
    }

    private function definition(string $resource): array
    {
        return $this->definitions()[$resource] ?? abort(404, 'Unsupported API module.');
    }

    private function isCore(string $resource): bool
    {
        return array_key_exists($resource, ApiModuleDefinitions::all());
    }

    private function adapter(string $resource): WorkflowApiModules|AuxiliaryApiModules
    {
        return array_key_exists($resource, app(AuxiliaryApiModules::class)->definitions())
            ? app(AuxiliaryApiModules::class) : app(WorkflowApiModules::class);
    }
}
