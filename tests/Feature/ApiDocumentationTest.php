<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiDocumentation;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Api\Modules\WorkflowApiModules;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use App\ClubUnio\Http\ApiController;
use App\ClubUnio\Http\ApiDocumentationController;
use App\ClubUnio\Http\ApiFileController;
use App\ClubUnio\Http\ApiReportController;
use App\ClubUnio\Http\ApiUploadController;
use App\ClubUnio\Http\AuthenticateApiIntegration;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingSlot;
use App\ClubUnio\Services\ActiveTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_expense_suggestion_fields_are_typed_read_only_and_not_write_inputs(): void
    {
        $definition = ['permission_module' => 'expenses', 'read_fields' => ['id', 'amount', 'suggested_amount', 'suggestion_accepted'],
            'create_fields' => ['amount'], 'update_fields' => ['amount'], 'version' => null, 'search_fields' => [], 'filter_fields' => [], 'actions' => []];
        $spec = (new ApiDocumentation)->specification(['expense_lines' => $definition]);
        $properties = $spec['components']['schemas']['expense_lines_record']['properties'];
        $this->assertSame('string', $properties['suggested_amount']['type']);
        $this->assertSame('decimal', $properties['suggested_amount']['format']);
        $this->assertSame(1, preg_match('~'.$properties['suggested_amount']['pattern'].'~', '25.1234'));
        $this->assertSame(0, preg_match('~'.$properties['suggested_amount']['pattern'].'~', '25.12345'));
        $this->assertSame('boolean', $properties['suggestion_accepted']['type']);
        $this->assertTrue($properties['suggested_amount']['readOnly']);
        $this->assertTrue($properties['suggestion_accepted']['readOnly']);
        $this->assertArrayNotHasKey('readOnly', $properties['amount']);
        foreach (['/expense_lines' => 'post', '/expense_lines/{id}' => 'patch'] as $path => $method) {
            $inputs = $spec['paths'][$path][$method]['requestBody']['content']['application/json']['schema']['properties'];
            $this->assertArrayNotHasKey('suggested_amount', $inputs);
            $this->assertArrayNotHasKey('suggestion_accepted', $inputs);
        }
    }

    public function test_time_fields_are_resource_specific_and_match_actual_dto_serialization(): void
    {
        $spec = (new ApiDocumentation)->specification();
        $registry = app(ApiModuleRegistry::class);
        foreach (['training_slots' => new TrainingSlot, 'competitions' => new Competition] as $name => $record) {
            $record->forceFill(['id' => 1, 'starts_at' => '18:30:00', 'revision' => 1]);
            if ($name === 'training_slots') {
                $record->forceFill(['ends_at' => '20:00:00']);
            } else {
                $record->forceFill(['plays_on' => '2026-10-25']);
            }
            $dto = $registry->module($name)->serialize($record);
            $read = $spec['components']['schemas'][$name.'_record']['properties']['starts_at'];
            $write = $spec['paths']['/'.$name.'/{id}']['patch']['requestBody']['content']['application/json']['schema']['properties']['starts_at'];
            $this->assertSame('18:30:00', $dto['starts_at']);
            foreach ([$read, $write] as $schema) {
                $this->assertSame('string', $schema['type']);
                $this->assertArrayNotHasKey('format', $schema);
                $this->assertSame(1, preg_match('~'.$schema['pattern'].'~', $dto['starts_at']));
                $this->assertSame(0, preg_match('~'.$schema['pattern'].'~', '2026-10-25T18:30:00+01:00'));
            }
            if ($name === 'training_slots') {
                $this->assertSame('20:00:00', $dto['ends_at']);
            } else {
                $this->assertSame('2026-10-25', $dto['plays_on']);
                $this->assertSame('date', $spec['components']['schemas'][$name.'_record']['properties']['plays_on']['format']);
            }
        }
        $serializer = new \ReflectionMethod(WorkflowApiModules::class, 'dto');
        foreach (['team_events', 'bus_reservations'] as $name) {
            $dto = $serializer->invoke(app(WorkflowApiModules::class), $name, (object) [
                'id' => 1, 'starts_at' => '2026-10-25 18:30:00+01', 'ends_at' => '2026-10-25 20:00:00+01',
                'deadline_at' => '2026-10-24 18:30:00+02', 'revision' => 1,
            ]);
            foreach (['starts_at', 'ends_at'] as $field) {
                $this->assertSame('date-time', $spec['components']['schemas'][$name.'_record']['properties'][$field]['format']);
                $this->assertSame(1, preg_match('~^2026-10-25T[0-9]{2}:[0-9]{2}:[0-9]{2}\+01:00$~', $dto[$field]));
                $write = $spec['paths']['/'.$name.'/{id}']['patch']['requestBody']['content']['application/json']['schema']['properties'][$field];
                $this->assertSame('local-date-time', $write['format']);
                $this->assertSame(1, preg_match('~'.$write['pattern'].'~', '2026-10-25T18:30'));
            }
        }
        $this->assertSame('date-time', $spec['components']['schemas']['members_record']['properties']['archived_at']['format']);
    }

    public function test_stable_resource_fields_have_meaningful_wire_types_and_examples(): void
    {
        $spec = (new ApiDocumentation)->specification();
        $schemas = $spec['components']['schemas'];
        $this->assertSame('integer', $schemas['members_record']['properties']['id']['type']);
        $this->assertSame('date', $schemas['members_record']['properties']['birth_date']['format']);
        $this->assertSame('email', $schemas['members_record']['properties']['email']['format']);
        $this->assertSame('boolean', $schemas['teams_record']['properties']['active']['type']);
        $this->assertSame('string', $schemas['teams_record']['properties']['laundry_allowance']['type']);
        $this->assertSame('integer', $schemas['invoices_record']['properties']['rounding_cents']['type']);
        $this->assertSame('boolean', $schemas['invoices_record']['properties']['cash_rounding']['type']);
        $money = $schemas['invoice_lines_record']['properties']['gross_amount'];
        $this->assertSame('string', $money['type']);
        $this->assertSame('decimal', $money['format']);
        $this->assertSame(1, preg_match('~'.$money['pattern'].'~', '150.0000'));
        $this->assertSame(0, preg_match('~'.$money['pattern'].'~', '1.5e2'));
        $targets = $spec['paths']['/announcements']['post']['requestBody']['content']['application/json']['schema']['properties']['target_ids'];
        $this->assertSame('array', $targets['type']);
        $this->assertSame([['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'maxLength' => 30]], $targets['items']['oneOf']);
        foreach (['members', 'teams', 'invoices'] as $resource) {
            $body = $spec['paths']['/'.$resource]['post']['requestBody']['content']['application/json']['schema'];
            $this->assertNotEmpty($body['required']);
            $this->assertNotEmpty($body['example']);
            foreach ($body['required'] as $field) {
                $this->assertArrayHasKey($field, $body['example']);
            }
        }
        $memberCreate = $spec['paths']['/members']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertCount(2, $memberCreate['anyOf']);
        $this->assertTrue($memberCreate['properties']['company']['nullable']);
        $invoiceLine = $spec['paths']['/invoices/{id}/actions/add_line']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['revision', 'description', 'gross_amount', 'discount'], $invoiceLine['required']);
        $this->assertSame('string', $invoiceLine['properties']['gross_amount']['type']);
        $this->assertSame('150.0000', $invoiceLine['example']['gross_amount']);
        $credits = $spec['paths']['/credit_notes']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame('array', $credits['properties']['lines']['type']);
        $this->assertSame(['invoice_line_id', 'amount'], $credits['properties']['lines']['items']['required']);
        $this->assertSame('string', $credits['properties']['lines']['items']['properties']['amount']['type']);
        $plan = $spec['paths']['/training_plans/{id}']['patch']['requestBody']['content']['application/json']['schema']['properties']['plan'];
        $this->assertSame('object', $plan['type']);
        $this->assertSame('array', $plan['properties']['exercises']['type']);
        $exercise = $plan['properties']['exercises']['items'];
        $this->assertFalse($exercise['additionalProperties']);
        $this->assertSame('integer', $exercise['properties']['markers']['items']['properties']['x']['type']);
        $walk = function (array $schema) use (&$walk): void {
            foreach ((array) ($schema['properties'] ?? []) as $field => $property) {
                $this->assertTrue(isset($property['type']) || isset($property['$ref']) || isset($property['oneOf']) || isset($property['anyOf']) || isset($property['allOf']), 'Untyped property: '.$field);
                $walk($property);
            }
            if (isset($schema['type'])) {
                $this->assertIsString($schema['type']);
                $this->assertNotSame('null', $schema['type']);
                if ($schema['type'] === 'array') {
                    $this->assertArrayHasKey('items', $schema);
                }
            }
            foreach ($schema as $key => $value) {
                if ($key !== 'example' && $key !== 'properties' && is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($spec);
    }

    public function test_registry_required_metadata_is_preserved_in_crud_and_action_bodies(): void
    {
        $spec = (new ApiDocumentation)->specification();
        foreach (app(ApiModuleRegistry::class)->definitions() as $name => $definition) {
            foreach (['create' => 'post', 'update' => 'patch'] as $operation => $method) {
                if (empty($definition[$operation.'_fields'])) {
                    continue;
                }
                $path = '/'.$name.($operation === 'update' ? '/{id}' : '');
                $body = $spec['paths'][$path][$method]['requestBody']['content']['application/json']['schema'];
                foreach ($definition[$operation.'_required'] ?? [] as $required) {
                    $this->assertContains($required, $body['required'], $name.' '.$operation.' lost required metadata');
                }
                foreach ($definition[$operation.'_schema']['required'] ?? [] as $required) {
                    $this->assertContains($required, $body['required']);
                }
                foreach ($body['required'] ?? [] as $field) {
                    $this->assertArrayHasKey($field, $body['properties']);
                }
            }
            foreach ($definition['actions'] as $action => $configuration) {
                $body = $spec['paths']['/'.$name.'/{id}/actions/'.$action]['post']['requestBody']['content']['application/json']['schema'];
                foreach ($configuration['required'] ?? [] as $field) {
                    $this->assertContains($field, $body['required']);
                }
                foreach ($configuration['schema']['properties'] ?? [] as $field => $metadata) {
                    foreach ($metadata as $key => $value) {
                        $this->assertSame($value, $body['properties'][$field][$key], $name.' action schema metadata changed');
                    }
                }
            }
        }
    }

    public function test_explicit_overrides_and_json_schema_null_unions_stay_in_openapi_30(): void
    {
        $definition = ['permission_module' => 'members', 'read_fields' => ['id', 'payload', 'doc'], 'search_fields' => [], 'filter_fields' => [],
            'create_fields' => ['payload', 'name'], 'update_fields' => [], 'version' => null, 'actions' => [],
            'field_schemas' => ['payload' => ['type' => ['string', 'integer', 'null'], 'description' => 'Explicitly polymorphic identifier.']],
            'create_required' => ['payload', 'name'], 'create_schema' => ['properties' => ['name' => ['type' => ['string', 'null'], 'const' => 'Example']]]];
        $spec = (new ApiDocumentation)->specification(['typed_fixture' => $definition]);
        $this->assertSame('3.0.3', $spec['openapi']);
        $body = $spec['paths']['/typed_fixture']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['payload', 'name'], $body['required']);
        $this->assertCount(3, $body['properties']['payload']['anyOf']);
        $this->assertSame('string', $body['properties']['name']['type']);
        $this->assertTrue($body['properties']['name']['nullable']);
        $this->assertSame(['Example'], $body['properties']['name']['enum']);
        $this->assertArrayNotHasKey('const', $body['properties']['name']);
        $document = $spec['components']['schemas']['typed_fixture_record']['properties']['doc'];
        $this->assertSame('object', $document['type']);
        $this->assertSame('boolean', $document['properties']['portal_visible']['type']);
    }

    public function test_new_unclassified_fields_require_an_explicit_schema_instead_of_empty_types(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No API field schema for fixture.unclassified_payload');
        (new ApiDocumentation)->specification(['fixture' => ['permission_module' => 'members', 'read_fields' => ['unclassified_payload'], 'version' => null]]);
    }

    public function test_unsupported_provider_schema_keywords_fail_explicitly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Provider schema keyword if needs an explicit OpenAPI 3.0 conversion');
        (new ApiDocumentation)->specification(['fixture' => ['permission_module' => 'members', 'read_fields' => ['name'], 'version' => null, 'search_fields' => [], 'filter_fields' => [],
            'read_schema' => ['if' => ['required' => ['name']]]]]);
    }

    public function test_report_and_export_contracts_match_known_routes_scopes_and_bounded_selections(): void
    {
        $spec = (new ApiDocumentation)->specification([]);
        $scopes = app(ApiTokenService::class)->scopes();
        foreach (['reports' => ApiReportController::REPORTS, 'exports' => ApiReportController::REPORTS + ['invoices' => 'invoices', 'payments' => 'expenses']] as $group => $kinds) {
            $paths = array_values(array_filter(array_keys($spec['paths']), fn ($path) => str_starts_with($path, '/'.$group.'/')));
            $this->assertSame(array_map(fn ($kind) => '/'.$group.'/'.$kind, array_keys($kinds)), $paths);
            foreach ($kinds as $kind => $module) {
                $path = '/'.$group.'/'.$kind;
                $operation = $spec['paths'][$path]['get'];
                $exportScope = in_array($kind, ['invoices', 'payments'], true) ? $module.':export' : 'reports:export';
                $this->assertSame([$group === 'reports' ? 'reports:read' : $exportScope, $module.':read'], $operation['x-required-scopes']);
                foreach ($operation['x-required-scopes'] as $scope) {
                    $this->assertArrayHasKey($scope, $scopes);
                }
                $parameters = array_column($operation['parameters'], null, 'name');
                if ($group === 'reports') {
                    $this->assertSame(['page', 'per_page', 'season_id'], array_keys($parameters));
                    $this->assertSame(10000, $parameters['page']['schema']['maximum']);
                    $this->assertSame(100, $parameters['per_page']['schema']['maximum']);
                    $this->assertSame(30, $parameters['per_page']['schema']['default']);
                    $this->assertArrayHasKey('application/json', $operation['responses']['200']['content']);
                } else {
                    $this->assertSame(['season_id'], array_keys($parameters));
                    $this->assertSame(5000, $operation['x-max-rows']);
                    $this->assertSame(['text/csv'], array_keys($operation['responses']['200']['content']));
                    $this->assertStringContainsString('No payment, bank transfer', $operation['description']);
                }
                $this->assertSame('#/components/responses/422', $operation['responses']['422']['$ref']);
                $route = Route::getRoutes()->match(Request::create('/api/v1'.$path));
                $this->assertSame(ApiReportController::class.'@'.($group === 'reports' ? 'index' : 'export'), $route->getActionName());
                $this->assertContains(AuthenticateApiIntegration::class, $route->gatherMiddleware());
                $this->getJson('/api/v1'.$path)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
            }
        }
        foreach (['index', 'export'] as $method) {
            try {
                (new ApiReportController)->$method(Request::create('/api/v1/reports/unknown'), 'unknown');
                $this->fail('Unknown report/export kind was accepted');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
    }

    public function test_all_documented_scopes_are_available_from_actual_token_scope_catalog(): void
    {
        $scopes = app(ApiTokenService::class)->scopes();
        $definitions = app(ApiModuleRegistry::class)->definitions();
        $spec = (new ApiDocumentation)->specification();
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $required = $operation['x-required-scopes'];
                foreach ($operation['x-required-scopes-by-kind'] ?? [] as $kindScopes) {
                    $required = [...$required, ...$kindScopes];
                }
                foreach ($operation['x-required-scopes-by-operation'] ?? [] as $actionScopes) {
                    $required = [...$required, ...$actionScopes];
                }
                foreach ($operation['x-conditional-scopes'] ?? [] as $condition) {
                    $required[] = $condition['scope'];
                }
                foreach (array_unique($required) as $scope) {
                    $this->assertArrayHasKey($scope, $scopes, strtoupper($method).' '.$path.' documents an unavailable scope: '.$scope);
                }
            }
        }
        foreach ($definitions as $name => $definition) {
            foreach (['create' => 'post', 'update' => 'patch'] as $function => $method) {
                $path = '/'.$name.($function === 'update' ? '/{id}' : '');
                $this->assertSame($definition[$function.'_fields'] !== [], isset($spec['paths'][$path][$method]), $name.':'.$function.' differs from the normalized registry');
            }
        }
        $this->assertSame([], $definitions['field_availability']['create_fields']);
        $this->assertArrayNotHasKey('field_availability:create', $scopes);
        $this->assertArrayNotHasKey('post', $spec['paths']['/field_availability']);
        $this->assertSame(['contributions:create', 'contributions:read', 'invoices:create'], $spec['paths']['/contributions']['post']['x-required-scopes']);
        $this->assertContains('signage:send', $spec['paths']['/signage_notices']['post']['x-required-scopes']);
        $this->assertContains('members:update', $spec['paths']['/member_changes/{id}/actions/approve']['post']['x-required-scopes']);
    }

    public function test_upload_routes_document_multipart_limits_scopes_and_distinct_idempotency(): void
    {
        $spec = (new ApiDocumentation)->specification([]);
        $expectedScopes = [];
        foreach (ApiUploadController::TYPES as $kind => $module) {
            $expectedScopes[$kind] = [$module.':upload'];
            $path = '/uploads/'.$kind.'/{id}';
            $operation = $spec['paths'][$path]['post'];
            $this->assertSame([$module.':upload'], $operation['x-required-scopes']);
            $parameters = array_column($operation['parameters'], null, 'name');
            $this->assertTrue($parameters['Idempotency-Key']['required']);
            $this->assertArrayHasKey('202', $operation['responses']);
            $this->assertArrayNotHasKey('200', $operation['responses']);
            $this->assertSame('#/components/schemas/UploadEnvelope', $operation['responses']['202']['content']['application/json']['schema']['$ref']);
            $multipart = $operation['requestBody']['content']['multipart/form-data'];
            $this->assertSame(20971520, $multipart['schema']['properties']['file']['x-max-bytes']);
            $this->assertSame('binary', $multipart['schema']['properties']['file']['format']);
            $this->assertFalse($multipart['schema']['additionalProperties']);
            if ($kind === 'expense') {
                $this->assertSame(['file', 'revision', 'expense_revision'], $multipart['schema']['required']);
                $this->assertStringNotContainsString('image/webp', $multipart['encoding']['file']['contentType']);
            } elseif ($kind === 'registration') {
                $this->assertSame(['portrait', 'identity'], $multipart['schema']['properties']['kind']['enum']);
                $this->assertStringContainsString('PDF is allowed only for identity', $multipart['schema']['properties']['kind']['description']);
            } else {
                $this->assertSame(['file', 'type', 'portal_visible'], $multipart['schema']['required']);
                $this->assertSame('documents:send', $operation['x-conditional-scopes'][0]['scope']);
            }
            $route = Route::getRoutes()->match(Request::create('/api/v1/uploads/'.$kind.'/1', 'POST'));
            $this->assertSame(ApiUploadController::class.'@stage', $route->getActionName());
            $this->assertContains(AuthenticateApiIntegration::class, $route->gatherMiddleware());
            $this->postJson('/api/v1/uploads/'.$kind.'/1')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
        foreach (['show' => 'get', 'process' => 'post'] as $action => $method) {
            $path = '/uploads/{id}'.($action === 'process' ? '/process' : '');
            $operation = $spec['paths'][$path][$method];
            $this->assertSame($expectedScopes, $operation['x-required-scopes-by-kind']);
            $this->assertSame(['id'], array_column($operation['parameters'], 'name'));
            $this->assertArrayHasKey('200', $operation['responses']);
            $this->assertStringContainsString('Only the creating integration', $operation['description']);
            if ($action === 'process') {
                $this->assertFalse($operation['requestBody']['required']);
                $this->assertSame(0, $operation['requestBody']['content']['application/json']['schema']['maxProperties']);
                $this->assertSame('documents:send', $operation['x-conditional-scopes'][0]['scope']);
                $this->assertStringContainsString('No API Idempotency-Key is required', $operation['description']);
            }
            $uri = '/api/v1'.str_replace('{id}', '1', $path);
            $route = Route::getRoutes()->match(Request::create($uri, strtoupper($method)));
            $this->assertSame(ApiUploadController::class.'@'.$action, $route->getActionName());
            $this->assertContains(AuthenticateApiIntegration::class, $route->gatherMiddleware());
            $this->json(strtoupper($method), $uri)->assertUnauthorized();
        }
        $status = $spec['components']['schemas']['UploadEnvelope']['properties']['data'];
        $this->assertSame(['id', 'kind', 'object_id', 'state', 'result_id'], $status['required']);
        $this->assertTrue($status['properties']['result_id']['nullable']);
        $this->assertSame(array_keys(ApiUploadController::TYPES), $status['properties']['kind']['enum']);
    }

    public function test_private_file_paths_match_known_protected_route_and_download_contract(): void
    {
        $spec = (new ApiDocumentation)->specification([]);
        $filePaths = array_filter(array_keys($spec['paths']), fn ($path) => str_starts_with($path, '/files/'));
        $this->assertSame(array_map(fn ($kind) => '/files/'.$kind.'/{id}', array_keys(ApiFileController::TYPES)), array_values($filePaths));
        foreach (ApiFileController::TYPES as $kind => $module) {
            $path = '/files/'.$kind.'/{id}';
            $operation = $spec['paths'][$path]['get'];
            $this->assertSame(['get'], array_keys($spec['paths'][$path]));
            $this->assertSame([$module.':download'], $operation['x-required-scopes']);
            $parameters = array_column($operation['parameters'], null, 'name');
            $this->assertTrue($parameters['id']['required']);
            $this->assertSame('boolean', $parameters['download']['schema']['type']);
            $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'default' => 0], $parameters['index']['schema']);
            $content = $operation['responses']['200']['content'];
            $this->assertArrayHasKey('application/pdf', $content);
            $this->assertArrayNotHasKey('application/json', $content);
            foreach ($content as $media) {
                $this->assertSame(['type' => 'string', 'format' => 'binary'], $media['schema']);
            }
            foreach ([401, 403, 404, 422] as $status) {
                $this->assertSame('#/components/responses/'.$status, $operation['responses'][$status]['$ref']);
            }
            $this->assertArrayNotHasKey('302', $operation['responses']);
            $this->assertArrayNotHasKey('requestBody', $operation);
            $request = Request::create('/api/v1/files/'.$kind.'/1');
            $route = Route::getRoutes()->match($request);
            $this->assertSame(ApiFileController::class.'@open', $route->getActionName());
            $this->assertSame($kind, $route->parameter('kind'));
            $this->assertContains(AuthenticateApiIntegration::class, $route->gatherMiddleware());
            $this->getJson($request->getRequestUri())->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
        try {
            (new ApiFileController)->open(Request::create('/api/v1/files/unknown/1'), 'unknown', 1);
            $this->fail('Unknown file kind was accepted');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_documentation_routes_require_web_authentication(): void
    {
        foreach (['/api-docs', '/api-docs/openapi.json'] as $path) {
            $this->get($path)->assertRedirect('/login');
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_workflow_provider_definitions_drive_action_scopes_and_write_availability(): void
    {
        $definitions = app(WorkflowApiModules::class)->definitions();
        $spec = (new ApiDocumentation)->specification($definitions);
        foreach ($definitions as $name => $definition) {
            $this->assertArrayHasKey('/'.$name, $spec['paths']);
            foreach (['create' => 'post', 'update' => 'patch'] as $function => $method) {
                $path = '/'.$name.($function === 'update' ? '/{id}' : '');
                if ($definition[$function.'_fields']) {
                    $operation = $spec['paths'][$path][$method];
                    $this->assertContains($definition['permission_module'].':'.$function, $operation['x-required-scopes']);
                    $this->assertSame($definition[$function.'_fields'], array_keys($operation['requestBody']['content']['application/json']['schema']['properties']));
                } else {
                    $this->assertArrayNotHasKey($method, $spec['paths'][$path]);
                }
            }
            foreach ($definition['actions'] as $action => $configuration) {
                $function = is_string($configuration) ? $configuration : $configuration['function'];
                $scope = $definition['permission_module'].':'.($function === 'view' ? 'read' : $function);
                $operation = $spec['paths']['/'.$name.'/{id}/actions/'.$action]['post'];
                $this->assertSame([$scope], $operation['x-required-scopes']);
                $this->assertSame('Idempotency-Key', $operation['parameters'][0]['name']);
                $this->assertSame('object', $operation['requestBody']['content']['application/json']['schema']['type']);
            }
        }
        $this->assertSame('#/components/schemas/invoices_envelope', $spec['paths']['/contributions']['post']['responses']['201']['content']['application/json']['schema']['$ref']);
    }

    public function test_wired_routes_recheck_admin_after_access_is_revoked(): void
    {
        $account = new Account;
        $account->forceFill(['id' => 11, 'active' => true]);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 22, 'name' => 'Active tenant']);
        $this->actingAs($account, 'clubunio');
        $this->mock(ActiveTenant::class)->shouldReceive('resolve')->andReturn($tenant);
        $gate = new class
        {
            public bool $allowed = true;

            public function isAdmin(Account $account, Tenant $tenant): bool
            {
                return $this->allowed && $account->id === 11 && $tenant->id === 22;
            }
        };
        $this->app->instance(ApiTokenService::class, $gate);
        $this->get('/api-docs')->assertOk()->assertSee('SwaggerUIBundle');
        $this->get('/api-docs/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3');
        $gate->allowed = false;
        $this->get('/api-docs')->assertForbidden();
        $this->getJson('/api-docs/openapi.json')->assertForbidden();
    }

    public function test_context_contract_matches_controller_and_error_contract_matches_middleware(): void
    {
        $spec = (new ApiDocumentation)->specification([]);
        $account = new Account;
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 22, 'name' => 'Test tenant']);
        $integration = new ApiIntegration;
        $integration->forceFill(['id' => 33, 'name' => 'Test integration', 'scopes' => ['members:read', 'teams:read']]);
        $token = new ApiAccessToken;
        $token->forceFill(['abilities' => ['members:read'], 'expires_at' => now()->addHour()]);
        $request = Request::create('/api/v1/context');
        $request->attributes->set('clubunio.api.context', new ApiContext($account, $tenant, $integration, $token));
        $payload = (new ApiController)->context($request)->getData(true)['data'];
        $schema = $spec['components']['schemas']['ContextEnvelope']['properties']['data'];
        $this->assertSame(array_keys($payload), $schema['required']);
        $this->assertSame(array_keys($payload), array_keys($schema['properties']));
        $this->assertSame(['id', 'name'], $schema['properties']['tenant']['required']);
        $this->assertSame(['members:read'], $payload['scopes']);
        $this->assertSame('date-time', $schema['properties']['expires_at']['format']);
        $this->assertSame(['v1'], $schema['properties']['api_version']['enum']);
        $response = $this->getJson('/api/v1/context')->assertUnauthorized();
        $response->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
        $response->assertJsonPath('error.code', 'unauthenticated');
        $error = $spec['components']['schemas']['Error'];
        $this->assertSame(['error'], $error['required']);
        $this->assertSame(array_keys($response->json('error')), $error['properties']['error']['required']);
        $this->assertSame('object', $error['properties']['error']['properties']['fields']['type']);
        $this->assertSame('array', $error['properties']['error']['properties']['fields']['additionalProperties']['type']);
    }

    public function test_empty_field_schemas_serialize_as_objects_and_writes_describe_idempotency(): void
    {
        $spec = (new ApiDocumentation)->specification([
            'empty_fixture' => ['permission_module' => 'members', 'read_fields' => [], 'search_fields' => [], 'filter_fields' => [],
                'create_fields' => ['name'], 'update_fields' => [], 'version' => null, 'actions' => ['approve' => ['function' => 'approve', 'fields' => []]]],
        ]);
        $decoded = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $decoded->components->schemas->empty_fixture_record->properties);
        $body = $decoded->paths->{'/empty_fixture/{id}/actions/approve'}->post->requestBody->content->{'application/json'}->schema;
        $this->assertInstanceOf(\stdClass::class, $body->properties);
        $this->assertInstanceOf(\stdClass::class, $decoded->paths->{'/empty_fixture'}->post->requestBody->content->{'application/json'}->schema->properties->name);
        foreach (['/empty_fixture', '/empty_fixture/{id}/actions/approve'] as $path) {
            $operation = $spec['paths'][$path]['post'];
            $parameter = $operation['parameters'][0];
            $this->assertSame('Idempotency-Key', $parameter['name']);
            $this->assertTrue($parameter['required']);
            $this->assertSame(8, $parameter['schema']['minLength']);
            $this->assertArrayHasKey('415', $operation['responses']);
        }
        $this->assertArrayHasKey('201', $spec['paths']['/empty_fixture']['post']['responses']);
        $this->assertArrayNotHasKey('200', $spec['paths']['/empty_fixture']['post']['responses']);
    }

    public function test_live_registry_has_resolvable_schemas_and_only_explicit_resource_paths(): void
    {
        $registry = app(ApiModuleRegistry::class);
        $spec = (new ApiDocumentation)->specification();
        $decoded = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($registry->definitions() as $name => $definition) {
            $this->assertArrayHasKey('/'.$name, $spec['paths']);
            $this->assertArrayHasKey('/'.$name.'/{id}', $spec['paths']);
            $this->assertSame($definition['read_fields'], array_values(array_intersect($definition['read_fields'], array_keys($spec['components']['schemas'][$name.'_record']['properties']))));
            $this->assertFalse($spec['components']['schemas'][$name.'_record']['additionalProperties']);
            if ($definition['version']) {
                $this->assertArrayHasKey($definition['version'], $spec['components']['schemas'][$name.'_record']['properties']);
            }
            foreach (array_keys($definition['actions']) as $action) {
                $this->assertArrayHasKey('/'.$name.'/{id}/actions/'.$action, $spec['paths']);
            }
        }
        $resourceNames = array_keys($registry->definitions());
        $providerPaths = [...array_map(fn ($operation) => '/provider-actions/'.$operation, array_keys(app(ProviderApiRegistry::class)->definitions())), '/provider-work/{id}', '/provider-work/{id}/process', '/sfv/schedule'];
        foreach ($spec['paths'] as $path => $operations) {
            if ($path !== '/context' && ! in_array($path, $providerPaths, true) && ! str_starts_with($path, '/website/') && ! str_starts_with($path, '/files/') && ! str_starts_with($path, '/uploads/') && ! str_starts_with($path, '/reports/') && ! str_starts_with($path, '/exports/')) {
                $this->assertContains(explode('/', $path)[1], $resourceNames, 'Unregistered resource in API documentation');
            }
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertContains($method, ['get', 'post', 'patch']);
                $ids[] = $operation['operationId'];
            }
            $this->assertStringNotContainsString('{resource}', $path);
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
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
    }

    public function test_specification_is_explicit_bearer_only_and_has_standard_errors(): void
    {
        $spec = (new ApiDocumentation)->specification([]);
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame([['url' => '/api/v1']], $spec['servers']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame(['/context', '/website/teams', '/website/fixtures', '/website/training-times', '/website/facility-status',
            ...array_map(fn ($kind) => '/reports/'.$kind, array_keys(ApiReportController::REPORTS)),
            ...array_map(fn ($kind) => '/exports/'.$kind, [...array_keys(ApiReportController::REPORTS), 'invoices', 'payments']),
            ...array_map(fn ($kind) => '/files/'.$kind.'/{id}', array_keys(ApiFileController::TYPES)),
            ...array_map(fn ($kind) => '/uploads/'.$kind.'/{id}', array_keys(ApiUploadController::TYPES)), '/uploads/{id}', '/uploads/{id}/process',
            ...array_map(fn ($operation) => '/provider-actions/'.$operation, array_keys(app(ProviderApiRegistry::class)->definitions())), '/provider-work/{id}', '/provider-work/{id}/process', '/sfv/schedule'], array_keys($spec['paths']));
        foreach ($spec['paths'] as $path => $operations) {
            $operation = $operations['get'] ?? $operations['post'];
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
            $this->assertArrayHasKey('422', $operation['responses']);
            $this->assertArrayHasKey('429', $operation['responses']);
            if (str_starts_with($path, '/website/')) {
                $this->assertSame(['website:'.basename($path).':read'], $operations['get']['x-required-scopes']);
            }
        }
        $this->assertArrayNotHasKey('/login', $spec['paths']);
        $this->assertArrayNotHasKey('/api-docs/openapi.json', $spec['paths']);
    }

    public function test_resource_catalog_controls_filters_writes_and_actions(): void
    {
        $spec = (new ApiDocumentation)->specification([
            'members' => ['permission_module' => 'members', 'read_fields' => ['id', 'name'], 'search_fields' => [], 'filter_fields' => ['active'], 'create_fields' => ['name'], 'update_fields' => ['name', '_version'], 'version' => '_version', 'actions' => ['archive' => ['function' => 'deactivate', 'fields' => ['_version']]]],
            'invoices' => ['permission_module' => 'invoices', 'read_fields' => ['id'], 'search_fields' => [], 'filter_fields' => [], 'version' => null],
        ]);
        $this->assertSame(['members:read'], $spec['paths']['/members']['get']['x-required-scopes']);
        $parameters = $spec['paths']['/members']['get']['parameters'];
        $this->assertSame(['page', 'per_page', 'filters[active]'], array_column($parameters, 'name'));
        $this->assertSame(100, $parameters[1]['schema']['maximum']);
        $this->assertSame(['members:create'], $spec['paths']['/members']['post']['x-required-scopes']);
        $this->assertSame(['members:update'], $spec['paths']['/members/{id}']['patch']['x-required-scopes']);
        $this->assertSame(['members:deactivate'], $spec['paths']['/members/{id}/actions/archive']['post']['x-required-scopes']);
        $this->assertTrue($spec['paths']['/members/{id}']['parameters'][0]['required']);
        $this->assertArrayNotHasKey('post', $spec['paths']['/invoices']);
        $this->assertArrayNotHasKey('delete', $spec['paths']['/members/{id}']);
        $this->assertArrayNotHasKey('/invoices/{id}/actions/send', $spec['paths']);
        $ids = [];
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $method => $operation) {
                if ($method !== 'parameters') {
                    $ids[] = $operation['operationId'];
                }
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_sort_is_documented_only_when_explicitly_supported(): void
    {
        $definition = ['permission_module' => 'members', 'read_fields' => ['id', 'name'], 'search_fields' => [],
            'filter_fields' => [], 'version' => null, 'create_fields' => [], 'update_fields' => []];
        $spec = (new ApiDocumentation)->specification(['sortable' => $definition + ['sort_fields' => ['id', 'name']], 'unsortable' => $definition]);
        $parameters = array_column($spec['paths']['/sortable']['get']['parameters'], null, 'name');
        $this->assertSame(['id', 'name', '-id', '-name'], $parameters['sort']['schema']['enum']);
        $this->assertNotContains('sort', array_column($spec['paths']['/unsortable']['get']['parameters'], 'name'));
    }

    public function test_both_controller_methods_reject_guests_before_resolving_tenant(): void
    {
        $this->mock(ActiveTenant::class)->shouldNotReceive('resolve');
        foreach (['show', 'specification'] as $method) {
            try {
                (new ApiDocumentationController)->$method(Request::create('/api-docs'));
                $this->fail('Guest access was accepted');
            } catch (HttpException $exception) {
                $this->assertSame(401, $exception->getStatusCode());
            }
        }
    }

    public function test_both_controller_methods_recheck_admin_in_active_tenant(): void
    {
        [$request, $tenant, $account] = $this->context();
        $gate = new class($account, $tenant)
        {
            public bool $allowed = true;

            public int $checks = 0;

            public function __construct(private Account $account, private Tenant $tenant) {}

            public function isAdmin(Account $account, Tenant $tenant): bool
            {
                if ($account !== $this->account || $tenant !== $this->tenant) {
                    throw new \LogicException('Wrong account or tenant');
                }
                $this->checks++;

                return $this->allowed;
            }
        };
        $this->app->instance(ApiTokenService::class, $gate);
        $controller = new ApiDocumentationController;
        $html = $controller->show($request);
        $this->assertSame(200, $html->getStatusCode());
        $this->assertStringContainsString('no-store', $html->headers->get('Cache-Control'));
        $body = $html->getContent();
        $this->assertStringContainsString('/assets/swagger-ui/swagger-ui-bundle.js', $body);
        $this->assertStringContainsString('/assets/swagger-ui/swagger-ui.css', $body);
        $this->assertStringContainsString('persistAuthorization: false', $body);
        $this->assertStringContainsString('validatorUrl: null', $body);
        $this->assertStringContainsString("request.credentials = isSpecification ? 'same-origin' : 'omit'", $body);
        $this->assertStringContainsString("target.searchParams.set('download', download === 'true' ? '1' : '0')", $body);
        $this->assertStringNotContainsString('preauthorize', $body);
        $this->assertStringNotContainsString('cdn.', $body);
        $json = $controller->specification($request);
        $this->assertSame(200, $json->getStatusCode());
        $this->assertStringContainsString('no-store', $json->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $json->headers->get('X-Content-Type-Options'));
        $gate->allowed = false;
        foreach (['show', 'specification'] as $method) {
            try {
                $controller->$method($request);
                $this->fail('Revoked admin access was accepted');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(4, $gate->checks);
    }

    private function context(): array
    {
        $account = new Account;
        $account->forceFill(['id' => 11, 'active' => true]);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 22, 'name' => 'Documentation test']);
        $request = Request::create('/api-docs?tenant=untrusted');
        $request->setUserResolver(fn ($guard) => $guard === 'clubunio' ? $account : null);
        $this->mock(ActiveTenant::class)->shouldReceive('resolve')->with($request)->andReturn($tenant);

        return [$request, $tenant, $account];
    }
}
