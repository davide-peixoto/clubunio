<?php

namespace App\ClubUnio\Api\Providers;

final class ProviderApiDocumentation
{
    public function __construct(private readonly ?ProviderApiRegistry $registry = null) {}

    public function extend(array $paths, array $schemas): array
    {
        $definitions = ($this->registry ?? app(ProviderApiRegistry::class))->definitions();
        $scopes = [];
        foreach ($definitions as $name => $definition) {
            $scopes[$name] = array_keys($definition['scopes']);
            $operation = $this->operation('provider_action_'.str_replace('.', '_', $name), $definition['title'], 202);
            $operation['x-required-scopes'] = $scopes[$name];
            $operation['x-approval'] = $definition['approval'];
            $operation['description'] = 'Stage local SQL work only; no provider execution occurs in the idempotency transaction. All listed scopes and current domain permissions are required. No query parameters or unknown JSON fields are accepted. '
                .($definition['approval'] === 'admin'
                    ? 'Admin approval is required: a real admin must inspect and explicitly approve this specific request in the web API integrations administration. Bexio remains admin-only; a technical API account cannot execute this work, including through /provider-work/{id}/process.'
                    : 'Explicit processing is required after staging; staging does not execute or schedule the action.');
            if (str_starts_with($name, 'training.')) {
                $operation['description'] .= ' Paid AI attempts use a stable persisted UUID assigned by the server, never a client request key. No automatic retry; existing quality checks and quota limits remain unchanged.';
            }
            foreach ($definition['conditional_scopes'] ?? [] as $scope => $condition) {
                $operation['x-conditional-scopes'][] = ['scope' => $scope, 'when' => $condition];
                $operation['description'] .= ' Additionally requires '.$scope.' with current domain permission when '.$condition.'.';
            }
            $operation['parameters'] = [[
                'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                'description' => 'Local staging only. Reuse only for the identical operation and JSON payload; changed or unfinished requests return 409. A replay does not execute the provider.',
                'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 120, 'pattern' => '^[A-Za-z0-9._:-]{8,120}$'],
            ]];
            $operation['requestBody'] = $this->body([
                'type' => 'object', 'additionalProperties' => false, 'required' => ['object_id', 'input'],
                'properties' => [
                    'object_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999999999999999999,
                        'example' => in_array($name, ['sfv.refresh', 'training.generate'], true) ? 0 : 1,
                        'description' => 'Tenant-owned domain object ID; use 0 only for actions without an existing object. Action-specific validation still applies.'],
                    'input' => $this->inputSchema($definition['fields']),
                ],
            ]);
            $operation['responses'][202]['description'] = 'Durable work staged, not executed; data.state identifies pending work or required admin approval.';
            $operation['responses'][202]['headers']['Idempotency-Replayed'] = [
                'description' => 'Present and true when the original staging response is replayed.',
                'schema' => ['type' => 'string', 'enum' => ['true']],
            ];
            $paths['/provider-actions/'.$name]['post'] = $operation;
        }

        foreach (['show' => 'get', 'process' => 'post'] as $action => $method) {
            $operation = $this->operation('provider_work_'.$action, $action === 'show' ? 'Read provider work status' : 'Process staged provider work', 200);
            $operation['parameters'] = [['name' => 'id', 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1]]];
            $operation['x-required-scopes-by-operation'] = $scopes;
            $operation['description'] = 'Only the creating integration and the same technical account in the token tenant may access this work. All scopes and current permissions for the stored operation are rechecked. No query parameters are accepted.';
            if ($action === 'process') {
                $operation['requestBody'] = $this->body(['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false, 'maxProperties' => 0, 'example' => (object) []]);
                $operation['description'] .= ' Send JSON {}. No Idempotency-Key is required. Only direct actions can be processed: Bexio/admin-approval work always returns 409, even after approval. Running, attention or rejected work is not retried (409); completed work returns its recorded result without re-execution. Provider execution occurs outside the staging SQL transaction, at most once per work item. After a timeout or uncertain result, inspect GET /provider-work/{id} and reconcile the provider outcome; do not blindly retry or stage replacement work. No new scheduler or automatic retry is introduced. Paid AI attempts retain their persisted UUID and existing quality/quota checks.';
                $operation['responses'][409] = $this->error('Admin approval required, work running/attention/rejected, changed authorization or stale work; inspect status and reconcile instead of blindly retrying.');
            } else {
                unset($operation['responses'][415]);
            }
            $paths['/provider-work/{id}'.($action === 'process' ? '/process' : '')][$method] = $operation;
        }

        $properties = [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'operation' => ['type' => 'string', 'enum' => array_keys($definitions)],
            'object_id' => ['type' => 'integer', 'minimum' => 0],
            'state' => ['type' => 'string', 'enum' => ['awaiting_approval', 'pending', 'running', 'succeeded', 'attention', 'rejected']],
            'revision' => ['type' => 'integer', 'minimum' => 1],
            'error_code' => ['type' => 'string', 'nullable' => true],
            'approved_by' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'result' => ['type' => 'object', 'additionalProperties' => true, 'nullable' => true,
                'description' => 'Recorded action-specific result, or null before a confirmed result. No common provider result structure is guaranteed; inspect state and error_code, not only result.'],
        ];
        $schemas['ProviderWork'] = ['type' => 'object', 'additionalProperties' => false,
            'required' => array_keys($properties), 'properties' => $properties];
        $schemas['ProviderWorkEnvelope'] = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProviderWork']]];

        $scheduleProperties = [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'match_id' => ['type' => 'integer', 'minimum' => 1],
            'season_id' => ['type' => 'integer'],
            'team_a_id' => ['type' => 'integer', 'nullable' => true],
            'team_b_id' => ['type' => 'integer', 'nullable' => true],
            'host_name' => ['type' => 'string', 'nullable' => true],
            'guest_name' => ['type' => 'string', 'nullable' => true],
            'title' => ['type' => 'string'],
            'starts_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'location' => ['type' => 'string', 'nullable' => true],
            'state' => ['type' => 'string', 'nullable' => true],
            'match_type' => ['type' => 'string', 'nullable' => true],
            'home' => ['type' => 'boolean'],
            'revision' => ['type' => 'integer', 'minimum' => 1],
            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
        ];
        $schemas['SfvScheduleRecord'] = ['type' => 'object', 'additionalProperties' => false,
            'required' => array_keys($scheduleProperties), 'properties' => $scheduleProperties];
        $schemas['SfvScheduleCollection'] = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SfvScheduleRecord']],
                'meta' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['page', 'per_page', 'total', 'last_page'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'total' => ['type' => 'integer', 'minimum' => 0],
                        'last_page' => ['type' => 'integer', 'minimum' => 1],
                    ]],
            ]];
        $schedule = $this->operation('sfv_schedule_index', 'Read local SFV schedule', 200);
        $schedule['x-required-scopes'] = ['competitions:read'];
        $schedule['description'] = 'Read-only, tenant-scoped local SFV source records with current competitions view permission. Does not refresh SFV, execute provider work or return provider authentication tokens. Only page, per_page, from and to query parameters are accepted; no other filters are supported. Optional dates use YYYY-MM-DD; when both are supplied, to must be on or after from.';
        $schedule['parameters'] = [
            ['name' => 'page', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30]],
            ['name' => 'from', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$']],
            ['name' => 'to', 'in' => 'query', 'required' => false,
                'description' => 'Must be on or after from when from is supplied.',
                'schema' => ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                'x-validation' => ['after_or_equal:from']],
        ];
        $schedule['responses'][200]['description'] = 'Paginated safe local SFV source fields, without provider credentials.';
        $schedule['responses'][200]['content']['application/json']['schema'] = ['$ref' => '#/components/schemas/SfvScheduleCollection'];
        unset($schedule['responses'][413], $schedule['responses'][415]);
        $paths['/sfv/schedule']['get'] = $schedule;

        return ['paths' => $paths, 'schemas' => $schemas];
    }

    private function inputSchema(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $field => $validation) {
            $rules = is_array($validation) ? $validation : explode('|', $validation);
            $type = match (true) {
                in_array('integer', $rules, true) => 'integer',
                in_array('numeric', $rules, true) => 'number',
                in_array('boolean', $rules, true) => 'boolean',
                in_array('string', $rules, true), in_array('date', $rules, true),
                in_array('date_format:Y-m-d', $rules, true), in_array('date_format:Y-m-d\\TH:i', $rules, true) => 'string',
                default => throw new \LogicException('No provider API schema type for '.$field),
            };
            $schema = ['type' => $type, 'x-laravel-validation' => $rules];
            if (in_array('nullable', $rules, true)) {
                $schema['nullable'] = true;
            }
            if (in_array('required', $rules, true) || in_array('present', $rules, true)) {
                $required[] = $field;
            }
            if ($type === 'string' && in_array('required', $rules, true)) {
                $schema['minLength'] = 1;
            }
            foreach ($rules as $rule) {
                [$name, $value] = array_pad(explode(':', $rule, 2), 2, null);
                if (in_array($name, ['min', 'max'], true) && is_numeric($value)) {
                    $key = in_array($type, ['integer', 'number'], true)
                        ? ($name === 'min' ? 'minimum' : 'maximum') : ($name === 'min' ? 'minLength' : 'maxLength');
                    $schema[$key] = $value + 0;
                } elseif ($name === 'date_format' && $value === 'Y-m-d') {
                    $schema['format'] = 'date';
                } elseif ($name === 'date_format' && $value === 'Y-m-d\\TH:i') {
                    $schema['format'] = 'local-date-time';
                    $schema['pattern'] = '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$';
                    $schema['description'] = 'Europe/Zurich local date/time without an offset, YYYY-MM-DDTHH:mm.';
                } elseif ($name === 'regex') {
                    $delimiter = $value[0];
                    $end = strrpos($value, $delimiter);
                    $schema['pattern'] = str_replace(['\\A', '\\z'], ['^', '$'], substr($value, 1, $end - 1));
                } elseif (in_array($name, ['after', 'after_or_equal', 'before_or_equal'], true)) {
                    $constraint = match ($name) {
                        'after' => 'Must be after ',
                        'after_or_equal' => 'Must be on or after ',
                        'before_or_equal' => 'Must be on or before ',
                    };
                    $schema['description'] = trim(($schema['description'] ?? '').' '.$constraint.$value.'.');
                } elseif ($name === 'in') {
                    $schema['enum'] = array_map(fn ($item) => $type === 'integer' ? (int) $item : $item, explode(',', $value));
                }
            }
            $properties[$field] = $schema;
        }
        $schema = ['type' => 'object', 'additionalProperties' => false,
            'properties' => $properties ?: (object) [],
            'description' => 'Only these exact fields are accepted. Domain validation, ownership, freshness and provider prerequisites remain authoritative.'];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function operation(string $id, string $summary, int $status): array
    {
        $responses = [$status => ['description' => 'Recorded provider work',
            'headers' => ['X-Request-ID' => ['schema' => ['type' => 'string', 'format' => 'uuid']]],
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProviderWorkEnvelope']]]]];
        foreach ([400, 401, 403, 404, 405, 409, 415, 422, 429, 500, 503] as $code) {
            $responses[$code] = ['$ref' => '#/components/responses/'.$code];
        }
        if ($id !== 'provider_work_show') {
            $responses[413] = $this->error('JSON request exceeds the 1 MiB limit.');
        }

        return ['operationId' => $id, 'tags' => ['Providers'], 'summary' => $summary,
            'x-required-scopes' => [], 'security' => [['bearerAuth' => []]], 'responses' => $responses];
    }

    private function body(array $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    private function error(string $description): array
    {
        return ['description' => $description,
            'headers' => ['X-Request-ID' => ['schema' => ['type' => 'string', 'format' => 'uuid']]],
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }
}
