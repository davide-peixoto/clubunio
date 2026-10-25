<?php

namespace App\ClubUnio\Api;

use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Http\ApiFileController;
use App\ClubUnio\Http\ApiReportController;
use App\ClubUnio\Http\ApiUploadController;
use App\ClubUnio\Services\PermissionCatalog;
use App\ClubUnio\Services\TrainingPlanContent;

final class ApiDocumentation
{
    /** Only explicitly registered API capabilities belong in this catalog. */
    public function specification(?array $resources = null): array
    {
        $resources ??= app(ApiModuleRegistry::class)->definitions();
        $schemas = $this->schemas();
        $paths = [
            '/context' => ['get' => $this->operation('context', 'Context', 'Read token context', 'ContextEnvelope')],
        ];
        $websiteFields = [
            'teams' => ['id', 'team_id', 'season_id', 'name', 'label', 'season'],
            'fixtures' => ['id', 'date', 'time', 'host', 'guest', 'host_club_number', 'guest_club_number', 'field', 'host_room', 'guest_room'],
            'training-times' => ['id', 'team_season_id', 'team', 'weekday', 'period', 'starts_at', 'ends_at', 'field', 'room'],
            'facility-status' => ['id', 'field', 'status', 'blocks_training'],
        ];
        foreach ($websiteFields as $resource => $fields) {
            $schema = 'website_'.str_replace('-', '_', $resource);
            $schemas[$schema] = $schemas['CollectionEnvelope'];
            $schemas[$schema]['properties']['data']['items'] = $this->fieldSchema($fields, 'website_'.$resource, true);
            $schemas[$schema]['properties']['meta']['properties'] = [
                'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0], 'last_page' => ['type' => 'integer', 'minimum' => 1],
            ];
            $paths['/website/'.$resource]['get'] = $this->operation(
                $schema, 'Website', 'Read '.$resource, $schema,
                ['website:'.$resource.':read'],
            );
            $parameters = $this->listParameters(['search_fields' => [], 'filter_fields' => []]);
            $parameters[1]['schema']['default'] = 30;
            if (in_array($resource, ['teams', 'training-times'], true)) {
                $parameters[] = ['name' => 'season_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]];
            }
            if ($resource === 'fixtures') {
                foreach (['from', 'to'] as $field) {
                    $parameters[] = ['name' => $field, 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']];
                }
            }
            $paths['/website/'.$resource]['get']['parameters'] = $parameters;
        }
        foreach (ApiReportController::REPORTS as $kind => $module) {
            $reportFields = match ($kind) {
                'members', 'passive', 'unassigned' => ['id', 'member_number', 'first_name', 'last_name', 'company', 'status_id', 'type_id'],
                'players' => ['id', 'member_id', 'team_season_id'],
                'staff' => ['id', 'member_id', 'team_season_id', 'staff_function_id'],
                'families' => ['id', 'member_id', 'family_id'], 'keys' => ['id', 'member_id'],
            };
            $schemas['report_'.$kind] = $schemas['CollectionEnvelope'];
            $schemas['report_'.$kind]['properties']['data']['items'] = $this->fieldSchema($reportFields, $kind, true);
            $operation = $this->operation('report_'.$kind, 'Reports', 'Read '.$kind.' report', 'report_'.$kind, ['reports:read', $module.':read']);
            $operation['description'] = 'Tenant-scoped JSON report. Both reports:read and the domain read scope are required with current permissions. Only page, per_page and season_id query parameters are accepted. Season filtering applies where supported by the report. Unknown report kinds return 404.';
            $operation['parameters'] = $this->listParameters(['search_fields' => [], 'filter_fields' => []]);
            $operation['parameters'][1]['schema']['default'] = 30;
            $operation['parameters'][] = ['name' => 'season_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]];
            unset($operation['responses']['200']['headers']['Idempotency-Replayed']);
            $paths['/reports/'.$kind] = ['get' => $operation];
        }
        foreach (ApiReportController::REPORTS + ['invoices' => 'invoices', 'payments' => 'expenses'] as $kind => $module) {
            $exportScope = in_array($kind, ['invoices', 'payments'], true) ? $module.':export' : 'reports:export';
            $operation = $this->operation('export_'.$kind, 'Exports', 'Export '.$kind.' CSV', 'RecordEnvelope', [$exportScope, $module.':read']);
            $operation['description'] = 'Read-only CSV download through the existing export service. Both listed scopes and current domain permissions are required. Exports the full filtered selection, not a page, with at most 5000 rows; larger selections return the standard 422 JSON error before CSV headers. season_id is the only documented filter and applies where supported. No payment, bank transfer, provider write or paid-status action is performed. Unknown export kinds return 404.';
            $operation['parameters'] = [['name' => 'season_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]]];
            $operation['x-max-rows'] = 5000;
            $operation['responses']['200']['description'] = 'UTF-8 CSV attachment, semicolon-separated, with a byte-order mark; at most 5000 rows.';
            $operation['responses']['200']['content'] = ['text/csv' => ['schema' => ['type' => 'string', 'format' => 'binary']]];
            $operation['responses']['200']['headers']['Content-Disposition'] = ['schema' => ['type' => 'string'], 'description' => 'Attachment with a generated CSV filename.'];
            unset($operation['responses']['200']['headers']['Idempotency-Replayed']);
            $paths['/exports/'.$kind] = ['get' => $operation];
        }
        foreach (ApiFileController::TYPES as $kind => $module) {
            $operation = $this->operation('file_'.str_replace('-', '_', $kind), 'Files', 'Read '.$kind, 'RecordEnvelope', [$module.':download']);
            $operation['description'] = 'Read-only private file access in the token tenant using current download permissions. Returns bytes, never a storage URL or external redirect. Unknown file kinds return 404.';
            $operation['parameters'] = [
                $this->idParameter(),
                ['name' => 'download', 'in' => 'query', 'description' => 'Request attachment disposition where supported. Use 0 or 1 as the query-string boolean value.', 'schema' => ['type' => 'boolean', 'default' => false]],
                ['name' => 'index', 'in' => 'query', 'description' => 'Zero-based attachment index for expense-document; ignored by other kinds.', 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'default' => 0]],
            ];
            $mediaTypes = in_array($kind, ['member-document', 'registration-document', 'expense-document'], true)
                ? ['application/pdf', 'image/*', 'application/octet-stream'] : ['application/pdf'];
            $operation['responses']['200']['content'] = array_fill_keys($mediaTypes, ['schema' => ['type' => 'string', 'format' => 'binary']]);
            $operation['responses']['200']['description'] = 'Private file bytes; content type and disposition are determined by the authorized file service.';
            unset($operation['responses']['200']['headers']['Idempotency-Replayed']);
            $operation['responses']['200']['headers']['Content-Disposition'] = ['schema' => ['type' => 'string'], 'description' => 'Inline or attachment with a safe filename.'];
            $paths['/files/'.$kind.'/{id}'] = ['get' => $operation];
        }
        $uploadFields = [
            'member' => ['type' => ['type' => 'integer', 'minimum' => 1], 'portal_visible' => ['type' => 'boolean', 'description' => 'Boolean form value 0 or 1. True additionally requires documents:send.']],
            'registration' => ['kind' => ['type' => 'string', 'enum' => ['portrait', 'identity'], 'description' => 'Portrait requires an image; PDF is allowed only for identity.']],
            'expense' => ['revision' => ['type' => 'integer', 'minimum' => 1], 'expense_revision' => ['type' => 'integer', 'minimum' => 1]],
        ];
        $uploadScopes = [];
        foreach (ApiUploadController::TYPES as $kind => $module) {
            $uploadScopes[$kind] = [$module.':upload'];
            $operation = $this->operation('upload_stage_'.$kind, 'Uploads', 'Stage '.$kind.' upload', 'UploadEnvelope', [$module.':upload']);
            $operation['description'] = 'Stage a private file of at most 20 MiB through the existing upload service. Unknown kinds return 404. The path id identifies the target '.($kind === 'expense' ? 'expense line' : $kind).'. No query parameters are accepted. Replays require identical metadata, file digest and filename.';
            $operation['parameters'] = [$this->idParameter(), $this->idempotencyParameter()];
            $operation['parameters'][1]['description'] = 'Required staging idempotency key. Reuse only for the identical target, metadata, file digest and filename.';
            $types = ['application/pdf', 'image/png', 'image/jpeg'];
            if ($kind !== 'expense') {
                $types[] = 'image/webp';
            }
            $operation['requestBody'] = ['required' => true, 'content' => ['multipart/form-data' => [
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['file', ...array_keys($uploadFields[$kind])],
                    'properties' => ['file' => ['type' => 'string', 'format' => 'binary', 'maxLength' => 20971520, 'x-max-bytes' => 20971520, 'description' => 'Maximum 20 MiB; accepted media types: '.implode(', ', $types)], ...$uploadFields[$kind]]],
                'encoding' => ['file' => ['contentType' => implode(', ', $types)]],
            ]]];
            $operation['responses']['202'] = $operation['responses']['200'];
            $operation['responses']['202']['description'] = 'Staged upload accepted; status identifies the durable upload.';
            unset($operation['responses']['200']);
            if ($kind === 'member') {
                $operation['x-conditional-scopes'] = [['scope' => 'documents:send', 'when' => 'portal_visible is true']];
            }
            $paths['/uploads/'.$kind.'/{id}'] = ['post' => $operation];
        }
        foreach (['show' => 'get', 'process' => 'post'] as $action => $method) {
            $operation = $this->operation('upload_'.$action, 'Uploads', $action === 'show' ? 'Read staged upload status' : 'Process staged upload', 'UploadEnvelope');
            $operation['parameters'] = [$this->idParameter()];
            $operation['x-required-scopes-by-kind'] = $uploadScopes;
            $operation['description'] = 'Only the creating integration may access this upload in its tenant. The stored upload kind selects the required module:upload scope. No query parameters are accepted.';
            unset($operation['responses']['200']['headers']['Idempotency-Replayed']);
            if ($action === 'process') {
                $operation['description'] .= ' Empty request body. No API Idempotency-Key is required: the existing service uses durable provider intent and deduplication by upload ID, without an outer API SQL transaction. Member uploads with portal_visible require documents:send before unpublished content is processed.';
                $operation['x-conditional-scopes'] = [['scope' => 'documents:send', 'when' => 'an unpublished member upload has portal_visible=true']];
                $operation['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'properties' => (object) [], 'additionalProperties' => false, 'maxProperties' => 0,
                ]]]];
            }
            $paths['/uploads/{id}'.($action === 'process' ? '/process' : '')] = [$method => $operation];
        }
        foreach ($resources as $name => $definition) {
            $module = $definition['permission_module'];
            $tag = PermissionCatalog::MODULES[$module] ?? $module;
            $collection = '/'.$name;
            $item = $collection.'/{id}';
            $record = $this->fieldSchema($definition['read_fields'], $name, true, $definition['read_field_schemas'] ?? $definition['field_schemas'] ?? []);
            if ($definition['version']) {
                $record['properties'][$definition['version']] = $this->versionSchema($definition['version']);
                $record['required'] = array_values(array_unique([...($record['required'] ?? []), $definition['version']]));
            }
            $record = $this->mergeSchema($record, $definition['read_schema'] ?? []);
            $writeFields = [...($definition['create_fields'] ?? []), ...($definition['update_fields'] ?? [])];
            foreach ($record['properties'] as $field => &$property) {
                if (! in_array($field, $writeFields, true)) {
                    $property['readOnly'] = true;
                }
            }
            unset($property);
            $schemas[$name.'_record'] = $record;
            $schemas[$name.'_envelope'] = ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$name.'_record']]];
            $schemas[$name.'_collection'] = $schemas['CollectionEnvelope'];
            $schemas[$name.'_collection']['properties']['data']['items'] = ['$ref' => '#/components/schemas/'.$name.'_record'];
            $paths[$collection]['get'] = $this->operation($name.'_index', $tag, 'List '.$name, $name.'_collection', [$module.':read']);
            $paths[$collection]['get']['parameters'] = $this->listParameters($definition);
            $paths[$item]['parameters'] = [$this->idParameter()];
            $paths[$item]['get'] = $this->operation($name.'_show', $tag, 'Read '.$name, $name.'_envelope', [$module.':read']);
            foreach (['create' => 'post', 'update' => 'patch'] as $function => $method) {
                if (empty($definition[$function.'_fields']) || ! isset(PermissionCatalog::functions($module)[$function])) {
                    continue;
                }
                $path = $function === 'create' ? $collection : $item;
                $responseSchema = $function === 'create' && $name === 'contributions'
                    ? (isset($resources['invoices']) ? 'invoices_envelope' : 'RecordEnvelope') : $name.'_envelope';
                $paths[$path][$method] = $this->operation($name.'_'.$function, $tag, ucfirst($function).' '.$name, $responseSchema, [$module.':'.$function]);
                if ($name === 'contributions' && $function === 'create') {
                    $paths[$path][$method]['x-required-scopes'] = ['contributions:create', 'contributions:read', 'invoices:create'];
                }
                if ($name === 'signage_notices') {
                    $paths[$path][$method]['x-required-scopes'][] = 'signage:send';
                }
                $paths[$path][$method]['parameters'] = [$this->idempotencyParameter()];
                if ($function === 'create') {
                    $paths[$path][$method]['responses']['201'] = $paths[$path][$method]['responses']['200'];
                    $paths[$path][$method]['responses']['201']['description'] = 'Created';
                    unset($paths[$path][$method]['responses']['200']);
                }
                $body = $this->fieldSchema($definition[$function.'_fields'], $name, false, $definition[$function.'_field_schemas'] ?? $definition['field_schemas'] ?? []);
                $required = $definition[$function.'_required'] ?? $this->requiredFields($name, $function);
                if ($function === 'update' && $definition['version']) {
                    $body['properties'][$definition['version']] = $this->versionSchema($definition['version']);
                    $required[] = $definition['version'];
                }
                $required = array_values(array_intersect(array_unique($required), $definition[$function.'_fields']));
                if ($required) {
                    $body['required'] = $required;
                }
                if ($name === 'members' && $function === 'create' && count(array_intersect(['first_name', 'last_name', 'company'], $definition['create_fields'])) === 3) {
                    $nonempty = fn ($fields) => ['required' => $fields, 'properties' => array_fill_keys($fields, ['type' => 'string', 'minLength' => 1])];
                    $body['anyOf'] = [$nonempty(['company']), $nonempty(['first_name', 'last_name'])];
                    $body['description'] = 'For a new member supply a nonempty company or both nonempty first_name and last_name. Other name fields may be omitted or null. Recipient selection may require the corresponding email address.';
                }
                $body = $this->mergeSchema($body, $definition[$function.'_schema'] ?? []);
                $example = $this->requestExample($name, $function);
                if ($example !== null && ! array_diff(array_keys($example), $definition[$function.'_fields'])) {
                    $body['example'] = $example;
                }
                $paths[$path][$method]['requestBody'] = $this->jsonBody($body);
                $paths[$path][$method]['description'] = 'Only listed fields are accepted. Domain validation and current permissions are checked by the service. Side effects may require additional scopes as well as matching current permissions: module:deactivate or module:restore for active/exit/status transitions; signage:send for public notices and published competitions; training:send when editing or publishing shared training plans; planning:cancel or planning:restore for event cancellation/reopening; bus:approve when creating an approved reservation. Contribution invoice creation also requires contributions:read and invoices:create. A scope alone does not enable an unsupported workflow.';
                if (! empty($definition['validation_notes'])) {
                    $paths[$path][$method]['description'] .= ' '.implode(' ', $definition['validation_notes']);
                }
            }
            foreach (($definition['actions'] ?? []) as $action => $configuration) {
                // Workflow providers may expose a permission string before adding field metadata.
                $configuration = is_string($configuration) ? ['function' => $configuration] : $configuration;
                $path = $item.'/actions/'.$action;
                $paths[$path]['parameters'] = [$this->idParameter()];
                $function = $configuration['function'] === 'view' ? 'read' : $configuration['function'];
                $paths[$path]['post'] = $this->operation($name.'_action_'.$action, $tag, $action.' '.$name, 'RecordEnvelope', [$module.':'.$function]);
                $paths[$path]['post']['description'] = 'The action scope and current domain permissions are checked together. Side effects can additionally require publication scopes (signage:send, training:send or documents:send), planning:cancel for series cancellation, or cross-module write scopes. Approving a member change also requires members:update. Only the implemented action and its validated inputs are allowed; unavailable token scopes do not grant additional workflows.';
                if ($name === 'member_changes' && $action === 'approve') {
                    $paths[$path]['post']['x-required-scopes'][] = 'members:update';
                }
                $paths[$path]['post']['parameters'] = [$this->idempotencyParameter()];
                $body = isset($configuration['fields']) ? $this->fieldSchema($configuration['fields'], $name) : $this->objectSchema();
                if (empty($configuration['fields']) && ! isset($configuration['schema'])) {
                    $body['additionalProperties'] = true;
                    $body['description'] = 'Action-specific input is validated by the workflow. The registry does not currently declare a field schema for this action.';
                }
                if (in_array('_version', $configuration['fields'] ?? [], true)) {
                    $body['properties']['_version'] = $this->versionSchema('_version');
                    $body['required'] = ['_version'];
                }
                if (! empty($configuration['required'])) {
                    $body['required'] = $configuration['required'];
                } elseif (in_array('revision', $configuration['fields'] ?? [], true)) {
                    $body['required'] = ['revision'];
                }
                $body = $this->mergeSchema($body, $configuration['schema'] ?? []);
                if (isset($body['required']) && $body['required'] === []) {
                    unset($body['required']);
                }
                if (! empty($configuration['description'])) {
                    $paths[$path]['post']['description'] .= ' '.$configuration['description'];
                }
                if ($name === 'invoices' && $action === 'add_line') {
                    $body['example'] = ['revision' => 1, 'description' => 'Annual contribution', 'gross_amount' => '150.0000', 'discount' => '0.0000'];
                }
                $paths[$path]['post']['requestBody'] = $this->jsonBody($body);
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'ClubUnio API', 'version' => '1.0.0', 'description' => 'Tenant-bound bearer API. Token scopes and current account permissions must both allow each operation. Website responses use separate safe DTOs.'],
            'servers' => [['url' => '/api/v1']],
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Manually supplied API token. Web sessions do not authorize API requests.']],
                'schemas' => array_map(fn ($schema) => $this->openApiSchema($schema), $schemas),
                'responses' => $this->errorResponses(),
            ],
        ];
    }

    private function operation(string $id, string $tag, string $summary, string $schema, array $scopes = []): array
    {
        $responses = ['200' => ['description' => 'Success', 'headers' => [
            'X-Request-ID' => ['schema' => ['type' => 'string', 'format' => 'uuid']],
            'Idempotency-Replayed' => ['description' => 'Present and true when a completed write is replayed.', 'schema' => ['type' => 'string', 'enum' => ['true']]],
        ], 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]]];
        foreach (array_keys($this->errorResponses()) as $status) {
            $responses[$status] = ['$ref' => '#/components/responses/'.$status];
        }

        return ['operationId' => $id, 'tags' => [$tag], 'summary' => $summary, 'x-required-scopes' => $scopes, 'responses' => $responses];
    }

    private function listParameters(array $definition): array
    {
        $parameters = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
        ];
        if ($definition['search_fields']) {
            $parameters[] = ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 200], 'description' => 'Search: '.implode(', ', $definition['search_fields'])];
        }
        if (! empty($definition['sort_fields'])) {
            $fields = array_values($definition['sort_fields']);
            $parameters[] = ['name' => 'sort', 'in' => 'query',
                'description' => 'Allowed sort field; prefix with - for descending order.',
                'schema' => ['type' => 'string', 'enum' => [...$fields, ...array_map(fn ($field) => '-'.$field, $fields)]]];
        }
        foreach ($definition['filter_fields'] as $field) {
            $schema = $this->fieldType($field, $definition['key'] ?? '', false);
            if (($schema['type'] ?? null) === 'boolean') {
                $schema = ['type' => 'integer', 'enum' => [0, 1]];
            }
            $parameters[] = ['name' => 'filters['.$field.']', 'in' => 'query', 'schema' => $schema, 'description' => 'Explicitly allowed equality filter. Boolean query flags use 0 or 1.'];
        }

        return $parameters;
    }

    private function idParameter(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]];
    }

    private function idempotencyParameter(): array
    {
        return ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
            'description' => 'Unique per integration and write intent. Reuse only with the identical method, path and JSON payload. Changed payloads or unfinished attempts return 409.',
            'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 120, 'pattern' => '^[A-Za-z0-9._:-]{8,120}$']];
    }

    private function objectSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => true,
            'description' => 'Action-specific result object. Its shape depends on the explicitly selected workflow; this is not an unrestricted resource write schema.'];
    }

    private function fieldSchema(array $fields, string $resource = '', bool $read = false, array $overrides = []): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $schema = $overrides[$field] ?? $this->fieldType($field, $resource, $read);
            if ($read && $field !== 'id' && ! in_array($field, ['_version', 'revision'], true)) {
                $schema['nullable'] ??= true;
            }
            $properties[$field] = $schema;
        }
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties ?: (object) []];
        if ($read && $fields) {
            $schema['required'] = array_values($fields);
        }

        return $schema;
    }

    /** Wire types are explicit; a new unclassified field must supply registry schema metadata. */
    private function fieldType(string $field, string $resource, bool $read): array
    {
        if (in_array($field, ['_version', 'member_version', 'fingerprint'], true)) {
            return $this->versionSchema('_version');
        }
        if (in_array($field, ['request_id', 'request_key', 'series_key'], true)) {
            return ['type' => 'string', 'format' => 'uuid'];
        }
        if ($field === 'id' || str_ends_with($field, '_id') || str_ends_with($field, '_cents') || str_ends_with($field, '_revision')
            || in_array($field, ['revision', 'member_number', 'club_number', 'host_club_number', 'guest_club_number', 'sort_order', 'file_size', 'year_from', 'year_to', 'repeat_count', 'shift_minutes', 'capacity'], true)) {
            return ['type' => 'integer', 'format' => 'int64'];
        }
        if (in_array($field, ['active', 'all_fields', 'without_main', 'synthetic_only', 'website_visible', 'blocks_training',
            'show_on_signage', 'logo_enabled', 'ends_membership', 'restores_membership', 'spots_eligible', 'team_required',
            'passive_membership', 'receipt_required', 'cash_rounding', 'contribution', 'portal_visible', 'shared',
            'legacy_quality_pending', 'series_exception', 'cancel', 'change_location', 'change_title', 'change_capacity', 'suggestion_accepted'], true)) {
            return ['type' => 'boolean'];
        }
        if (in_array($field, ['amount', 'gross_amount', 'net_amount', 'discount', 'laundry_allowance', 'included_tax', 'suggested_amount'], true)) {
            $precision = $field === 'included_tax' ? 6 : (in_array($resource, ['teams', 'family_members'], true) ? 2 : 4);

            return ['type' => 'string', 'format' => 'decimal', 'pattern' => '^-?[0-9]+(?:\\.[0-9]{1,'.$precision.'})?$',
                'description' => 'Exact CHF decimal string, never a binary floating-point number. Integer *_cents fields use CHF cents instead.',
                'x-currency' => 'CHF', 'example' => $precision === 6 ? '2.310000' : ($precision === 2 ? '25.00' : '150.0000')];
        }
        if (in_array($field, ['birth_date', 'exit_date', 'starts_on', 'ends_on', 'plays_on', 'shows_on', 'handover_on', 'from', 'date'], true)) {
            return ['type' => 'string', 'format' => 'date', 'example' => $field === 'birth_date' ? '2000-05-14' : '2026-10-25'];
        }
        if (in_array($field, ['starts_at', 'ends_at', 'time'], true)
            && in_array($resource, ['training_slots', 'competitions', 'website_training-times', 'website_fixtures'], true)) {
            return ['type' => 'string', 'pattern' => '^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$', 'description' => 'Local time, HH:mm:ss.', 'example' => '18:30:00'];
        }
        if (in_array($field, ['starts_at', 'ends_at', 'deadline_at', 'archived_at', 'signed_at'], true)) {
            if (! $read && in_array($field, ['starts_at', 'ends_at', 'deadline_at'], true)) {
                return ['type' => 'string', 'format' => 'local-date-time', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$',
                    'description' => 'Europe/Zurich local date/time without an offset, YYYY-MM-DDTHH:mm.', 'example' => '2026-10-25T18:30'];
            }

            return ['type' => 'string', 'format' => 'date-time', 'description' => 'RFC3339 timestamp including the UTC offset.', 'example' => '2026-10-25T18:30:00+01:00'];
        }
        if (in_array($field, ['email', 'mother_email', 'father_email'], true)) {
            return ['type' => 'string', 'format' => 'email', 'maxLength' => 254, 'example' => 'alex@example.test'];
        }
        if ($field === 'website') {
            return ['type' => 'string', 'format' => 'uri', 'example' => 'https://example.test'];
        }
        if ($field === 'target_ids') {
            return ['type' => 'array', 'maxItems' => 500, 'uniqueItems' => true,
                'items' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'maxLength' => 30]]],
                'description' => 'Integer object IDs, or role identifiers when target_type=roles. Do not mix identifier kinds.', 'example' => [1, 2]];
        }
        if (in_array($field, ['lines', 'items'], true)) {
            return ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['invoice_line_id', 'amount'], 'properties' => [
                    'invoice_line_id' => ['type' => 'integer', 'minimum' => 1],
                    'amount' => ['type' => 'string', 'format' => 'decimal', 'pattern' => '^[0-9]+(?:\\.[0-9]{1,2})?$',
                        'description' => 'Positive CHF credit amount, at least 0.01.', 'example' => '25.00'],
                ]], 'description' => 'Credit allocations against existing invoice lines; each invoice_line_id must be distinct.'];
        }
        if ($field === 'plan') {
            return app(TrainingPlanContent::class)->schema();
        }
        if (in_array($field, ['doc', 'document'], true)) {
            return $this->fieldSchema(['id', 'document_type_id', 'original_filename', 'content_type', 'file_size', 'portal_visible'], 'documents', $read);
        }
        $enums = ['tax_mode' => ['exempt', 'inclusive'], 'channel' => ['email', 'push'],
            'amount_source' => ['manual', 'receipt_ai', 'team_laundry']];
        if (isset($enums[$field])) {
            return ['type' => 'string', 'enum' => $enums[$field]];
        }
        if (in_array($field, ['first_name', 'last_name', 'company', 'name', 'label', 'title', 'description', 'notes', 'note', 'reason',
            'street', 'house_number', 'postal_code', 'city', 'phone_private', 'phone_business', 'mobile', 'pass_number',
            'mother_name', 'mother_mobile', 'father_name', 'father_mobile', 'birth_place', 'previous_club', 'previous_club_country_type',
            'has_previous_club', 'code', 'category', 'iso3', 'email_field', 'opponent', 'hall', 'host_name', 'guest_name', 'host_category',
            'guest_category', 'remarks', 'location', 'kind', 'mode', 'status', 'state', 'source_state', 'attendance', 'comment', 'body',
            'target_type', 'event_key', 'workflow_key', 'position_code', 'color', 'locale', 'original_filename', 'content_type', 'mime_type', 'team', 'season', 'field', 'room',
            'weekday', 'period', 'host', 'guest', 'host_room', 'guest_room'], true)) {
            $schema = ['type' => 'string'];
            if ($resource === 'members' && in_array($field, ['first_name', 'last_name', 'company'], true)) {
                $schema['nullable'] = true;
                $schema['maxLength'] = 255;
            }

            return $schema;
        }

        throw new \LogicException('No API field schema for '.$resource.'.'.$field.'. Add explicit registry metadata or a documented wire type.');
    }

    private function mergeSchema(array $base, array $metadata): array
    {
        $result = array_replace($base, $metadata);
        if (array_key_exists('properties', $metadata)) {
            $result['properties'] = array_replace((array) ($base['properties'] ?? []), (array) $metadata['properties']);
            if (! $result['properties']) {
                $result['properties'] = (object) [];
            }
        }
        foreach ((array) ($metadata['properties'] ?? []) as $field => $schema) {
            if (isset($base['properties'][$field]) && is_array($schema)) {
                $result['properties'][$field] = $this->mergeSchema($base['properties'][$field], $schema);
            }
        }

        return $result;
    }

    /** Confirmed service requirements; provider create_required/update_required take precedence. */
    private function requiredFields(string $resource, string $mode): array
    {
        if ($mode === 'update') {
            return match ($resource) {
                'teams', 'seasons', 'families' => ['name', 'active'],
                'team_seasons' => ['team_id', 'season_id', 'active'],
                'team_players', 'technical_leads' => ['member_id', 'team_season_id', 'active'],
                'team_staff' => ['member_id', 'team_season_id', 'active'],
                'family_members' => ['member_id', 'family_id', 'discount', 'active'],
                'bus_reservations' => ['bus_id', 'starts_at', 'ends_at', 'reason'],
                'announcements' => ['title', 'body', 'target_type', 'target_ids'],
                default => [],
            };
        }

        return match ($resource) {
            'members' => ['first_name', 'last_name', 'company', 'status_id', 'type_id', 'billing_recipient_id'],
            'teams', 'seasons', 'families' => ['name', 'active'],
            'team_seasons' => ['team_id', 'season_id', 'active'],
            'team_players', 'technical_leads' => ['member_id', 'team_season_id', 'active'],
            'team_staff' => ['member_id', 'team_season_id', 'staff_function_id', 'active'],
            'invoices' => ['member_id', 'invoice_category_id', 'tax_mode'],
            'expenses' => ['member_id', 'team_season_id', 'bank_account_id', 'round_id'],
            'expense_lines' => ['expense_id', 'expense_revision', 'expense_type_id'],
            'registrations' => ['first_name', 'last_name', 'email', 'birth_date', 'city', 'street', 'postal_code', 'country_id', 'nationality_id', 'gender_id', 'billing_recipient_id'],
            'family_members' => ['member_id', 'family_id', 'discount', 'active'],
            'bus_reservations' => ['bus_id', 'team_season_id', 'member_id', 'starts_at', 'ends_at', 'status'],
            'announcements' => ['title', 'body', 'target_type', 'target_ids'],
            'message_templates' => ['event_key', 'channel', 'locale', 'title', 'body', 'active'],
            'credit_notes' => ['invoice_id', 'request_id', 'revision', 'reason', 'lines'],
            'contributions' => ['member_id', 'season_id', 'fingerprint'],
            'contribution_prices' => ['name', 'amount', 'active'],
            'contribution_rules' => ['season_id', 'price_id', 'gender_id', 'year_from', 'year_to', 'active'],
            'signage_notices' => ['shows_on', 'title', 'body', 'active'],
            default => [],
        };
    }

    private function requestExample(string $resource, string $mode): ?array
    {
        if ($mode !== 'create') {
            return null;
        }

        return match ($resource) {
            'members' => ['first_name' => 'Alex', 'last_name' => 'Example', 'company' => null, 'email' => 'alex@example.test',
                'status_id' => 1, 'type_id' => 1, 'billing_recipient_id' => 1],
            'teams' => ['name' => 'Juniors A', 'active' => true, 'description' => 'Example team', 'laundry_allowance' => '25.00'],
            'invoices' => ['member_id' => 1, 'invoice_category_id' => 1, 'tax_mode' => 'exempt', 'notes' => 'Example internal draft'],
            default => null,
        };
    }

    private function versionSchema(string $field): array
    {
        return $field === '_version'
            ? ['type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'description' => 'Unchanged version from the latest record read.']
            : ['type' => 'integer', 'minimum' => 0, 'description' => 'Unchanged revision from the latest record read.'];
    }

    private function jsonBody(array $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $this->openApiSchema($schema)]]];
    }

    /** Normalize provider JSON Schema constructs without changing the document's 3.0 dialect. */
    private function openApiSchema(array $schema): array
    {
        foreach (['$defs', 'if', 'then', 'else', 'unevaluatedProperties', 'prefixItems', 'dependentSchemas', 'contains', 'patternProperties'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw new \LogicException('Provider schema keyword '.$keyword.' needs an explicit OpenAPI 3.0 conversion.');
            }
        }
        unset($schema['$schema']);
        if (array_key_exists('const', $schema)) {
            $schema['enum'] = [$schema['const']];
            unset($schema['const']);
        }
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $limit) {
            if (isset($schema[$exclusive]) && ! is_bool($schema[$exclusive])) {
                $schema[$limit] = $schema[$exclusive];
                $schema[$exclusive] = true;
            }
        }
        if (is_array($schema['type'] ?? null)) {
            $types = array_values(array_unique($schema['type']));
            $nullable = in_array('null', $types, true) || ($schema['nullable'] ?? false);
            $types = array_values(array_diff($types, ['null']));
            if (count($types) === 1) {
                $schema['type'] = $types[0];
                if ($nullable) {
                    $schema['nullable'] = true;
                }
            } else {
                unset($schema['type'], $schema['nullable']);
                $variants = array_map(fn ($type) => ['type' => $type], $types);
                if ($nullable) {
                    $variants[] = ['type' => 'string', 'nullable' => true, 'enum' => [null]];
                }
                $union = ['anyOf' => $variants];
                $schema = isset($schema['anyOf']) ? ['allOf' => [$schema, $union]] : $schema + $union;
            }
        } elseif (($schema['type'] ?? null) === 'null') {
            $schema['type'] = 'string';
            $schema['nullable'] = true;
            $schema['enum'] = [null];
        }
        if (isset($schema['properties'])) {
            $properties = [];
            foreach ((array) $schema['properties'] as $name => $property) {
                $properties[$name] = $this->openApiSchema($property);
            }
            $schema['properties'] = $properties ?: (object) [];
        }
        foreach (['items', 'not', 'additionalProperties'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $schema[$keyword] = $this->openApiSchema($schema[$keyword]);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                $schema[$keyword] = array_map(fn ($child) => $this->openApiSchema($child), $schema[$keyword]);
            }
        }
        if (($schema['required'] ?? null) === []) {
            unset($schema['required']);
        }

        return $schema;
    }

    private function schemas(): array
    {
        $record = $this->objectSchema();
        $envelope = fn (array $data): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $data]];

        return [
            'ContextEnvelope' => $envelope(['type' => 'object', 'additionalProperties' => false,
                'required' => ['integration_id', 'name', 'tenant', 'scopes', 'expires_at', 'api_version'], 'properties' => [
                    'integration_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'tenant' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'], 'properties' => [
                        'id' => ['type' => 'integer'], 'name' => ['type' => 'string'],
                    ]],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Intersection of integration scopes and token abilities.'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'api_version' => ['type' => 'string', 'enum' => ['v1']],
                ]]),
            'RecordEnvelope' => $envelope($record),
            'UploadEnvelope' => $envelope(['type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'kind', 'object_id', 'state', 'result_id'], 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'kind' => ['type' => 'string', 'enum' => array_keys(ApiUploadController::TYPES)],
                    'object_id' => ['type' => 'integer', 'minimum' => 1],
                    'state' => ['type' => 'string', 'description' => 'Durable upload state, for example pending or published.'],
                    'result_id' => ['type' => 'integer', 'nullable' => true],
                ]]),
            'CollectionEnvelope' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'array', 'items' => $record],
                'meta' => ['type' => 'object', 'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1],
                    'has_more' => ['type' => 'boolean'],
                ]],
            ]],
            'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => [
                'error' => ['type' => 'object', 'required' => ['code', 'message', 'request_id'], 'properties' => [
                    'code' => ['type' => 'string', 'description' => 'Stable machine-readable error code.'],
                    'message' => ['type' => 'string'],
                    'request_id' => ['type' => 'string', 'format' => 'uuid'],
                    'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ]],
            ]],
        ];
    }

    private function errorResponses(): array
    {
        $responses = [];
        foreach ([400 => 'Invalid request', 401 => 'Missing, invalid, expired or revoked bearer token', 403 => 'Scope or current tenant permission denied', 404 => 'Resource not found in token tenant', 405 => 'Operation unsupported for this resource', 409 => 'Conflict, stale revision or idempotency conflict', 415 => 'Content-Type must be application/json', 422 => 'Validation failed or invalid request', 429 => 'Rate limit exceeded', 500 => 'Internal request failure', 503 => 'Service unavailable'] as $status => $description) {
            $responses[$status] = ['description' => $description,
                'headers' => ['X-Request-ID' => ['schema' => ['type' => 'string', 'format' => 'uuid']]],
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        $responses[429]['headers']['Retry-After'] = ['description' => 'Seconds until retry', 'schema' => ['type' => 'integer', 'minimum' => 0]];

        return $responses;
    }
}
