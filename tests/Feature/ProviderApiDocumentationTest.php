<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiDocumentation;
use App\ClubUnio\Api\Providers\ProviderApiDocumentation;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderApiDocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
    }

    public function test_every_registered_action_has_a_literal_path_and_exact_validation_schema(): void
    {
        $definitions = app(ProviderApiRegistry::class)->definitions();
        $this->assertNotEmpty($definitions);
        $docs = (new ProviderApiDocumentation)->extend([], []);
        $this->assertCount(count($definitions) + 3, $docs['paths']);
        $this->assertArrayNotHasKey('/provider-actions/{operation}', $docs['paths']);

        foreach ($definitions as $name => $definition) {
            $operation = $docs['paths']['/provider-actions/'.$name]['post'];
            $this->assertSame($definition['title'], $operation['summary']);
            $this->assertSame(array_keys($definition['scopes']), $operation['x-required-scopes']);
            $this->assertSame($definition['approval'], $operation['x-approval']);
            $conditional = [];
            foreach ($definition['conditional_scopes'] ?? [] as $scope => $condition) {
                $conditional[] = ['scope' => $scope, 'when' => $condition];
                $this->assertStringContainsString($scope, $operation['description']);
                $this->assertStringContainsString($condition, $operation['description']);
            }
            $this->assertSame($conditional, $operation['x-conditional-scopes'] ?? []);
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $this->assertTrue($operation['requestBody']['required']);
            $body = $operation['requestBody']['content']['application/json']['schema'];
            $this->assertFalse($body['additionalProperties']);
            $this->assertSame(['object_id', 'input'], $body['required']);
            $this->assertSame(['object_id', 'input'], array_keys($body['properties']));
            $this->assertSame('integer', $body['properties']['object_id']['type']);
            $this->assertSame(0, $body['properties']['object_id']['minimum']);
            $this->assertSame(in_array($name, ['sfv.refresh', 'training.generate'], true) ? 0 : 1, $body['properties']['object_id']['example']);
            $input = $body['properties']['input'];
            $this->assertSame('object', $input['type']);
            $this->assertFalse($input['additionalProperties']);
            $this->assertSame(array_keys($definition['fields']), array_keys((array) $input['properties']));
            $required = [];
            foreach ($definition['fields'] as $field => $validation) {
                $rules = is_array($validation) ? $validation : explode('|', $validation);
                $property = $input['properties'][$field];
                $this->assertSame($rules, $property['x-laravel-validation']);
                if (in_array('required', $rules, true) || in_array('present', $rules, true)) {
                    $required[] = $field;
                }
                foreach (['integer' => 'integer', 'string' => 'string', 'boolean' => 'boolean'] as $rule => $type) {
                    if (in_array($rule, $rules, true)) {
                        $this->assertSame($type, $property['type']);
                    }
                }
                $this->assertSame(in_array('nullable', $rules, true), $property['nullable'] ?? false);
                foreach ($rules as $rule) {
                    [$key, $value] = array_pad(explode(':', $rule, 2), 2, null);
                    if (in_array($key, ['min', 'max'], true)) {
                        $bound = $property['type'] === 'integer'
                            ? ($key === 'min' ? 'minimum' : 'maximum') : ($key === 'min' ? 'minLength' : 'maxLength');
                        $this->assertSame($value + 0, $property[$bound]);
                    }
                    if ($rule === 'date_format:Y-m-d') {
                        $this->assertSame('date', $property['format']);
                    }
                }
            }
            $this->assertSame($required, $input['required'] ?? []);
            if ($definition['fields'] === []) {
                $this->assertInstanceOf(\stdClass::class, $input['properties']);
                $this->assertStringContainsString('"properties":{}', json_encode($input, JSON_THROW_ON_ERROR));
            }
            $this->assertArrayHasKey(202, $operation['responses']);
            $this->assertArrayNotHasKey(200, $operation['responses']);
            $this->assertSame('#/components/schemas/ProviderWorkEnvelope', $operation['responses'][202]['content']['application/json']['schema']['$ref']);
            $parameter = $operation['parameters'][0];
            $this->assertSame('Idempotency-Key', $parameter['name']);
            $this->assertSame('header', $parameter['in']);
            $this->assertTrue($parameter['required']);
            $this->assertSame(8, $parameter['schema']['minLength']);
            $this->assertSame(120, $parameter['schema']['maxLength']);
            if ($definition['approval'] === 'admin') {
                $this->assertStringContainsString('real admin', $operation['description']);
                $this->assertStringContainsString('technical API account cannot execute', $operation['description']);
            }
        }
    }

    public function test_sfv_fingerprint_and_training_limits_are_explicit_without_client_ai_keys(): void
    {
        $docs = (new ProviderApiDocumentation)->extend([], []);
        $input = fn (string $name) => $docs['paths']['/provider-actions/'.$name]['post']['requestBody']['content']['application/json']['schema']['properties']['input'];
        $fingerprint = $input('sfv.competition.apply')['properties']['fingerprint'];
        $this->assertSame('^[a-f0-9]{64}$', $fingerprint['pattern']);
        $this->assertSame(1, preg_match('~'.$fingerprint['pattern'].'~', str_repeat('a', 64)));
        $this->assertSame(0, preg_match('~'.$fingerprint['pattern'].'~', 'stale'));
        $this->assertStringContainsString('on or after from', $input('sfv.refresh')['properties']['to']['description']);
        $training = $input('training.generate');
        $this->assertSame(30, $training['properties']['durationMinutes']['minimum']);
        $this->assertSame(120, $training['properties']['durationMinutes']['maximum']);
        $this->assertSame(200, $training['properties']['goal']['maxLength']);
        $this->assertArrayNotHasKey('_request_key', $training['properties']);
        $description = $docs['paths']['/provider-actions/training.generate']['post']['description'];
        foreach (['stable persisted UUID', 'No automatic retry', 'quality checks and quota limits remain unchanged'] as $text) {
            $this->assertStringContainsString($text, $description);
        }
    }

    public function test_work_status_process_and_nullable_result_do_not_promise_a_provider_shape(): void
    {
        $docs = (new ProviderApiDocumentation)->extend([], []);
        $show = $docs['paths']['/provider-work/{id}']['get'];
        $process = $docs['paths']['/provider-work/{id}/process']['post'];
        $this->assertSame([], $show['x-required-scopes']);
        $this->assertSame([], $process['x-required-scopes']);
        $this->assertStringContainsString('same technical account', $show['description']);
        $this->assertStringContainsString('creating integration', $show['description']);
        foreach (app(ProviderApiRegistry::class)->definitions() as $name => $definition) {
            $this->assertSame(array_keys($definition['scopes']), $show['x-required-scopes-by-operation'][$name]);
        }
        $this->assertSame($show['x-required-scopes-by-operation'], $process['x-required-scopes-by-operation']);
        $this->assertSame(['id'], array_column($process['parameters'], 'name'));
        $this->assertArrayHasKey(200, $process['responses']);
        $this->assertTrue($process['requestBody']['required']);
        $body = $process['requestBody']['content']['application/json']['schema'];
        $this->assertFalse($body['additionalProperties']);
        $this->assertSame(0, $body['maxProperties']);
        $this->assertSame('{}', json_encode($body['example'], JSON_THROW_ON_ERROR));
        foreach (['Bexio/admin-approval work always returns 409', 'at most once', 'outside the staging SQL transaction', 'After a timeout', 'No new scheduler'] as $text) {
            $this->assertStringContainsString($text, $process['description']);
        }
        $this->assertStringContainsString('running/attention', $process['responses'][409]['description']);
        $record = $docs['schemas']['ProviderWork'];
        $this->assertSame(['id', 'operation', 'object_id', 'state', 'revision', 'error_code', 'approved_by', 'created_at', 'started_at', 'finished_at', 'result'], $record['required']);
        $this->assertFalse($record['additionalProperties']);
        foreach (['error_code', 'approved_by', 'started_at', 'finished_at', 'result'] as $field) {
            $this->assertTrue($record['properties'][$field]['nullable']);
        }
        $result = $record['properties']['result'];
        $this->assertSame('object', $result['type']);
        $this->assertTrue($result['additionalProperties']);
        $this->assertArrayNotHasKey('properties', $result);
        $this->assertSame('#/components/schemas/ProviderWork', $docs['schemas']['ProviderWorkEnvelope']['properties']['data']['$ref']);
    }

    public function test_planning_import_documents_local_dates_cross_field_rules_and_present_nullable_capacity(): void
    {
        $docs = (new ProviderApiDocumentation)->extend([], []);
        $operation = $docs['paths']['/provider-actions/sfv.planning-import']['post'];
        $input = $operation['requestBody']['content']['application/json']['schema']['properties']['input'];
        $this->assertFalse($input['additionalProperties']);
        foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
            $schema = $input['properties'][$field];
            $this->assertSame('string', $schema['type']);
            $this->assertSame('local-date-time', $schema['format']);
            $this->assertStringContainsString('Europe/Zurich', $schema['description']);
            $this->assertContains($field, $input['required']);
            $this->assertSame(1, preg_match('~'.$schema['pattern'].'~', '2026-10-26T18:30'));
            $this->assertSame(0, preg_match('~'.$schema['pattern'].'~', '2026-10-26T18:30:00Z'));
        }
        $this->assertStringContainsString('Must be after starts_at.', $input['properties']['ends_at']['description']);
        $this->assertStringContainsString('Must be on or before starts_at.', $input['properties']['deadline_at']['description']);
        $this->assertContains('capacity', $input['required']);
        $this->assertTrue($input['properties']['capacity']['nullable']);
        $this->assertSame(1, $input['properties']['capacity']['minimum']);
        $this->assertSame(1000, $input['properties']['capacity']['maximum']);
        $this->assertSame(0, $input['properties']['event_revision']['minimum']);
    }

    public function test_registry_conditional_scopes_are_rendered_without_becoming_unconditional(): void
    {
        $conditions = [
            'planning:create' => 'a new planning event is imported',
            'planning:cancel' => 'the import cancels an existing planning event',
            'planning:restore' => 'the import restores an existing planning event',
        ];
        $registry = new class($conditions) extends ProviderApiRegistry
        {
            public function __construct(private array $conditions) {}

            public function definitions(): array
            {
                return ['sfv.planning-import' => ['title' => 'Import SFV match into team planning',
                    'approval' => 'direct', 'scopes' => ['planning:sync' => ['planning', 'sync']],
                    'conditional_scopes' => $this->conditions, 'fields' => []]];
            }
        };
        $docs = (new ProviderApiDocumentation($registry))->extend([], []);
        $operation = $docs['paths']['/provider-actions/sfv.planning-import']['post'];
        $this->assertSame(['planning:sync'], $operation['x-required-scopes']);
        $this->assertSame($conditions, array_column($operation['x-conditional-scopes'], 'when', 'scope'));
        foreach ($conditions as $scope => $condition) {
            $this->assertStringContainsString($scope.' with current domain permission when '.$condition, $operation['description']);
        }
    }

    public function test_extension_preserves_existing_contract_and_has_unique_ids_resolvable_refs_and_errors(): void
    {
        $base = (new ApiDocumentation)->specification([]);
        $docs = (new ProviderApiDocumentation)->extend($base['paths'], $base['components']['schemas']);
        foreach ($base['paths'] as $path => $operations) {
            $this->assertSame(json_encode($operations, JSON_THROW_ON_ERROR), json_encode($docs['paths'][$path], JSON_THROW_ON_ERROR));
        }
        foreach ($base['components']['schemas'] as $name => $schema) {
            $this->assertSame(json_encode($schema, JSON_THROW_ON_ERROR), json_encode($docs['schemas'][$name], JSON_THROW_ON_ERROR));
        }
        $ids = [];
        foreach ($docs['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $ids[] = $operation['operationId'];
                if (str_starts_with($path, '/provider-') || $path === '/sfv/schedule') {
                    foreach ([401, 403, 404, 409, 422, 429, 500, 503] as $code) {
                        $this->assertArrayHasKey($code, $operation['responses']);
                    }
                    if ($method === 'post') {
                        $this->assertArrayHasKey(413, $operation['responses']);
                        $this->assertArrayHasKey(415, $operation['responses']);
                    }
                }
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
        $base['paths'] = $docs['paths'];
        $base['components']['schemas'] = $docs['schemas'];
        $decoded = json_decode(json_encode($base, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $walk = function (array $node) use (&$walk, $decoded): void {
            if (isset($node['$ref'])) {
                $target = $decoded;
                foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                    $this->assertArrayHasKey($part, $target);
                    $target = $target[$part];
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($decoded);
        Http::assertNothingSent();
    }

    public function test_sfv_schedule_is_a_safe_local_read_with_only_the_documented_filters(): void
    {
        $docs = (new ProviderApiDocumentation)->extend([], []);
        $this->assertSame(['get'], array_keys($docs['paths']['/sfv/schedule']));
        $operation = $docs['paths']['/sfv/schedule']['get'];
        $this->assertSame(['competitions:read'], $operation['x-required-scopes']);
        $this->assertSame([['bearerAuth' => []]], $operation['security']);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertArrayNotHasKey(202, $operation['responses']);
        $this->assertArrayNotHasKey(413, $operation['responses']);
        $this->assertArrayNotHasKey(415, $operation['responses']);
        $this->assertSame('#/components/schemas/SfvScheduleCollection', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
        $parameters = array_column($operation['parameters'], null, 'name');
        $this->assertSame(['page', 'per_page', 'from', 'to'], array_keys($parameters));
        foreach ($parameters as $parameter) {
            $this->assertSame('query', $parameter['in']);
            $this->assertFalse($parameter['required']);
        }
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'default' => 1], $parameters['page']['schema']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30], $parameters['per_page']['schema']);
        foreach (['from', 'to'] as $field) {
            $this->assertSame('string', $parameters[$field]['schema']['type']);
            $this->assertSame('date', $parameters[$field]['schema']['format']);
            $pattern = '~'.$parameters[$field]['schema']['pattern'].'~';
            $this->assertSame(1, preg_match($pattern, '2026-10-26'));
            $this->assertSame(0, preg_match($pattern, '26.10.2026'));
        }
        $this->assertSame(['after_or_equal:from'], $parameters['to']['x-validation']);
        $this->assertStringContainsString('on or after from', $parameters['to']['description']);
        foreach (['Read-only', 'local SFV source', 'no other filters', 'Does not refresh SFV', 'or return provider authentication tokens'] as $text) {
            $this->assertStringContainsString($text, $operation['description']);
        }

        $collection = $docs['schemas']['SfvScheduleCollection'];
        $this->assertFalse($collection['additionalProperties']);
        $this->assertSame(['data', 'meta'], $collection['required']);
        $this->assertSame('array', $collection['properties']['data']['type']);
        $this->assertSame('#/components/schemas/SfvScheduleRecord', $collection['properties']['data']['items']['$ref']);
        $meta = $collection['properties']['meta'];
        $this->assertFalse($meta['additionalProperties']);
        $this->assertSame(['page', 'per_page', 'total', 'last_page'], $meta['required']);
        $this->assertSame($meta['required'], array_keys($meta['properties']));
        $this->assertSame(100, $meta['properties']['per_page']['maximum']);
        $this->assertSame(0, $meta['properties']['total']['minimum']);
        $record = $docs['schemas']['SfvScheduleRecord'];
        $this->assertFalse($record['additionalProperties']);
        $fields = ['id', 'match_id', 'season_id', 'team_a_id', 'team_b_id', 'host_name', 'guest_name', 'title', 'starts_at', 'location', 'state', 'match_type', 'home', 'revision', 'updated_at'];
        $this->assertSame($fields, $record['required']);
        $this->assertSame($fields, array_keys($record['properties']));
        $this->assertSame(['type' => 'string', 'format' => 'date-time', 'nullable' => true], $record['properties']['starts_at']);
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $record['properties']['updated_at']);
        $this->assertSame('boolean', $record['properties']['home']['type']);
        Http::assertNothingSent();
    }
}
