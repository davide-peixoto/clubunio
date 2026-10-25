<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Api\Modules\ApiModuleDefinitions;
use App\ClubUnio\Models\{Account, ApiIntegration, Member, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, ModulePermissionService, PermissionCatalog};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiModulesTest extends TestCase
{
    private $db;
    private ApiModuleRegistry $registry;
    private ApiContext $context;
    private int $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ApiModuleRegistry;
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function fixture(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $tenant = Tenant::create(['slug' => 'api-module-'.Str::random(12), 'name' => 'API module fixture']);
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(20), 'display_name' => 'API fixture', 'password' => bcrypt(Str::random(40)), 'active' => true, 'api_only' => true])->save();
        $this->profile = $this->db->table('clubora.access_profiles')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'API module fixture', 'active' => true,
            'permissions' => json_encode(PermissionCatalog::keys(), JSON_THROW_ON_ERROR),
        ]);
        $this->db->table('clubora.tenant_accounts')->insert([
            'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'profile', 'access_profile_id' => $this->profile,
        ]);
        $scopes = [];
        foreach ($this->registry->definitions() as $definition) {
            foreach (['read', 'create', 'update', 'deactivate', 'restore'] as $function) {
                $scopes[] = $definition['permission_module'].':'.$function;
            }
        }
        $scopes[] = 'signage:send';
        $integration = new ApiIntegration;
        $integration->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'access_profile_id' => $this->profile,
            'name' => 'Synthetic module integration', 'scopes' => array_values(array_unique($scopes)), 'active' => true, 'created_by' => $actor->id])->save();
        $token = $integration->createToken('fixture', array_values(array_unique($scopes)), now()->addHour())->accessToken;
        $this->context = new ApiContext($actor, $tenant, $integration, $token);
    }

    private function tokenScopes(array $scopes): void
    {
        $this->db->table('clubora.api_access_tokens')->where('id', $this->context->token->id)
            ->update(['abilities' => json_encode($scopes, JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function rejects(callable $operation, string $exception = ValidationException::class, ?int $status = null): void
    {
        try {
            $operation();
            $this->fail('Unsafe operation accepted');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
            if ($status !== null) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
    }

    public function test_definitions_are_explicit_and_exclude_private_and_financial_models(): void
    {
        $definitions = $this->registry->definitions();
        $this->assertCount(34, ApiModuleDefinitions::all());
        foreach (array_intersect_key($definitions, ApiModuleDefinitions::all()) as $key => $definition) {
            $this->assertSame($key, $definition['key']);
            $this->assertSame($definition['permission_module'], ModulePermissionService::modelModule($definition['model']));
            $this->assertContains('id', $definition['read_fields']);
            foreach (['tenant_id', 'legacy_id', 'password', 'ahv_encrypted', 'iban', 'bank_account', 'blob_path', 'legacy_photo', 'bexio_contact_id'] as $protected) {
                $this->assertNotContains($protected, $definition['read_fields']);
                $this->assertNotContains($protected, $definition['create_fields']);
                $this->assertNotContains($protected, $definition['update_fields']);
            }
            $this->assertSame([], array_diff($definition['search_fields'], $definition['read_fields']));
            $this->assertSame([], array_diff($definition['filter_fields'], $definition['read_fields']));
        }
        $this->assertArrayHasKey('document_writes', $this->registry->unsupported());
        $this->assertArrayNotHasKey('reports', $this->registry->unsupported());
        foreach ($definitions as $definition) {
            foreach (['create', 'update'] as $operation) {
                if (! isset(PermissionCatalog::functions($definition['permission_module'])[$operation])) {
                    $this->assertSame([], $definition[$operation.'_fields']);
                    $this->assertFalse($definition[$operation]);
                }
            }
        }
        $this->assertSame([], $definitions['field_availability']['create_fields']);
        foreach (['accounts', 'settings', 'member_bank_accounts', 'sponsor_runs', '../members'] as $module) {
            $this->rejects(fn () => $this->registry->module($module), HttpException::class);
        }
    }

    public function test_all_definitions_round_trip_reads_without_unknown_columns(): void
    {
        $this->fixture();
        $columns = $this->db->table('information_schema.columns')->where('table_schema', 'clubora')->get(['table_name', 'column_name'])->groupBy('table_name');
        foreach (array_intersect_key($this->registry->definitions(), ApiModuleDefinitions::all()) as $key => $definition) {
            $table = (new $definition['model'])->getTable();
            $this->assertSame([], array_values(array_diff($definition['read_fields'], $columns->get(Str::after($table, 'clubora.'))->pluck('column_name')->all())), $key);
            $this->assertSame([], $this->registry->list($this->context, $key)['data'], $key);
        }
    }

    public function test_core_required_field_metadata_matches_payload_contracts(): void
    {
        $definitions = $this->registry->definitions();
        foreach (ApiModuleDefinitions::all() as $key => $definition) {
            foreach (['create', 'update'] as $operation) {
                $required = $definition[$operation.'_required'];
                $this->assertSame([], array_values(array_diff($required, $definition[$operation.'_fields'])), $key);
                $this->assertSame($required, $definitions[$key][$operation.'_required']);
            }
            if ($definition['version'] !== null) {
                $this->assertContains($definition['version'], $definition['update_required'], $key);
            } else {
                $this->assertSame([], $definition['update_required'], $key);
            }
        }
        $this->assertSame(['name', 'active'], $definitions['teams']['create_required']);
        $this->assertSame(['name', 'active', '_version'], $definitions['teams']['update_required']);
        $this->assertSame(['name', 'active'], $definitions['seasons']['create_required']);
        $this->assertSame(['name', 'active', '_version'], $definitions['seasons']['update_required']);
        $this->assertSame(['status_id', 'type_id', 'billing_recipient_id'], $definitions['members']['create_required']);
        $this->assertSame(['_version'], $definitions['members']['update_required']);
        $this->assertSame('present|nullable|required_without:company|string|max:255', $definitions['members']['record_rules']['first_name']);
        $this->assertSame('present|nullable|string|max:255', $definitions['members']['record_rules']['company']);
        $this->assertSame(['name', 'code', 'sort_order', 'active'], $definitions['genders']['create_required']);
        $this->assertSame(['name', 'sort_order', 'active', '_version'], $definitions['genders']['update_required']);
        $this->assertSame(['name', 'code', 'sort_order', 'active', 'iso3'], $definitions['countries']['create_required']);
        $this->assertSame(['name', 'sort_order', 'active', '_version'], $definitions['countries']['update_required']);
        $this->assertContains('email_field', $definitions['billing_recipients']['create_required']);
        $this->assertNotContains('email_field', $definitions['billing_recipients']['update_required']);
        $this->assertContains('receipt_required', $definitions['expense_types']['update_required']);
        $this->assertContains('amount_source', $definitions['expense_types']['update_required']);
        $this->assertSame([], $definitions['field_availability']['create_required']);
        $this->assertSame(['status_id', 'revision'], $definitions['field_availability']['update_required']);
        $this->assertNotContains('staff_function_id', $definitions['team_staff']['update_required']);
        $this->assertNotEmpty($definitions['team_staff']['validation_notes']);
        $this->assertNotContains('field_id', $definitions['training_slots']['create_required']);
        $this->assertNotEmpty($definitions['training_slots']['validation_notes']);
    }

    public function test_status_and_bank_institution_lookups_are_catalog_scoped_read_only_and_tenant_bound(): void
    {
        $this->fixture();
        $this->tokenScopes(['catalogs:read']);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)
            ->update(['permissions' => '["catalogs.view"]']);
        $resources = ['invoice_statuses', 'invoice_line_statuses', 'invoice_workflow_statuses', 'invoice_categories',
            'financial_adjustment_statuses', 'member_change_statuses', 'banks'];
        foreach ($resources as $resource) {
            $definition = $this->registry->definitions()[$resource];
            $this->assertSame('catalogs', $definition['permission_module']);
            $this->assertSame('catalogs', ModulePermissionService::modelModule($definition['model']));
            $this->assertSame([], $definition['create_fields']);
            $this->assertSame([], $definition['update_fields']);
            $this->assertSame([], $definition['create_required']);
            $this->assertSame([], $definition['update_required']);
            $this->assertSame([], $definition['actions']);
            $this->assertNull($definition['version']);
            $input = ['tenant_id' => $this->context->tenant->id, 'name' => 'Synthetic lookup', 'code' => $resource === 'member_change_statuses' ? 'pending' : 'fixture', 'active' => true];
            if (in_array('sort_order', $definition['read_fields'], true)) {
                $input['sort_order'] = 1;
            }
            if ($resource === 'member_change_statuses') {
                $input['color'] = 'gray';
            }
            if ($resource === 'invoice_statuses') {
                $input['position_code'] = 'fixture-position';
            }
            if ($resource === 'banks') {
                $input['postal_account'] = 'synthetic-not-for-dto';
            }
            $id = $this->db->table((new $definition['model'])->getTable())->insertGetId($input);
            $dto = $this->registry->show($this->context, $resource, $id);
            $this->assertSame($definition['read_fields'], array_keys($dto));
            $this->assertSame('Synthetic lookup', $dto['name']);
            $page = $this->registry->list($this->context, $resource, ['sort' => '-name', 'filters' => ['active' => true]]);
            $this->assertSame([$id], array_column($page['data'], 'id'));
            foreach (['create', 'update'] as $operation) {
                $this->rejects(fn () => $this->registry->authorize($this->context, $resource, $operation), HttpException::class, 405);
            }
            $this->rejects(fn () => $this->registry->create($this->context, $resource, ['name' => 'Denied']), HttpException::class, 405);
            $this->rejects(fn () => $this->registry->update($this->context, $resource, $id, ['name' => 'Denied']), HttpException::class, 405);
        }
        $other = Tenant::create(['slug' => 'lookup-other-'.Str::random(10), 'name' => 'Other lookup tenant']);
        $foreign = $this->db->table('clubora.banks')->insertGetId(['tenant_id' => $other->id, 'name' => 'Foreign bank', 'code' => 'foreign']);
        $this->rejects(fn () => $this->registry->show($this->context, 'banks', $foreign), ModelNotFoundException::class);
        $this->assertCount(1, $this->registry->list($this->context, 'banks')['data']);
        $this->tokenScopes(['invoices:read']);
        foreach ($resources as $resource) {
            $this->rejects(fn () => $this->registry->list($this->context, $resource), HttpException::class, 403);
        }
        $this->tokenScopes(['catalogs:read']);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)
            ->update(['permissions' => '["invoices.view"]']);
        $this->rejects(fn () => $this->registry->list($this->context, 'invoice_statuses'), HttpException::class, 403);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_registration_workflow_key_is_readable_but_never_an_api_write_field(): void
    {
        $this->fixture();
        $definition = $this->registry->definitions()['registration_statuses'];
        $this->assertContains('workflow_key', $definition['read_fields']);
        $this->assertNotContains('workflow_key', $definition['create_fields']);
        $this->assertNotContains('workflow_key', $definition['update_fields']);
        $id = $this->db->table('clubora.registration_statuses')->insertGetId(['tenant_id' => $this->context->tenant->id,
            'name' => 'Submitted fixture', 'code' => 'fixture', 'workflow_key' => 'submitted', 'sort_order' => 1, 'active' => true]);
        $dto = $this->registry->show($this->context, 'registration_statuses', $id);
        $this->assertSame('submitted', $dto['workflow_key']);
        $input = ['name' => 'Changed fixture', 'sort_order' => 1, 'active' => true, '_version' => $dto['_version']];
        $this->rejects(fn () => $this->registry->update($this->context, 'registration_statuses', $id, $input + ['workflow_key' => 'accepted']));
        $this->rejects(fn () => $this->registry->create($this->context, 'registration_statuses', ['name' => 'Denied', 'code' => 'denied',
            'sort_order' => 2, 'active' => true, 'workflow_key' => 'accepted']));
        $this->assertSame('submitted', $this->registry->update($this->context, 'registration_statuses', $id, $input)['workflow_key']);
    }

    public function test_pre_idempotency_authorization_checks_scopes_and_current_profile(): void
    {
        $this->fixture();
        $this->registry->authorize($this->context, 'team_players', 'create');
        $this->db->table('clubora.api_integrations')->where('id', $this->context->integration->id)->update(['scopes' => '["teams:read"]']);
        $this->rejects(fn () => $this->registry->authorize($this->context, 'team_players', 'create'), HttpException::class);
        $this->registry->authorize($this->context, 'team_players', 'list');
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['active' => false]);
        $this->rejects(fn () => $this->registry->authorize($this->context, 'team_players', 'show'), HttpException::class);
    }

    public function test_existing_context_cannot_survive_persisted_token_or_service_account_revocation(): void
    {
        $this->fixture();
        $this->registry->authorize($this->context, 'teams', 'read');
        $token = $this->db->table('clubora.api_access_tokens')->where('id', $this->context->token->id);
        $token->update(['revoked_at' => now()]);
        $this->rejects(fn () => $this->registry->list($this->context, 'teams'), HttpException::class);
        $token->update(['revoked_at' => null, 'expires_at' => now()->subMinute()->toIso8601String()]);
        $this->rejects(fn () => $this->registry->authorize($this->context, 'teams', 'create'), HttpException::class);
        $token->update(['expires_at' => now()->addHour()->toIso8601String()]);
        $this->db->table('clubora.accounts')->where('id', $this->context->actor->id)->update(['api_only' => false]);
        $this->rejects(fn () => $this->registry->list($this->context, 'teams'), HttpException::class);
    }

    public function test_member_creation_and_player_staff_lead_writes_use_existing_services(): void
    {
        $this->fixture();
        $status = $this->registry->create($this->context, 'member_statuses', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        $type = $this->registry->create($this->context, 'member_types', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        $recipient = $this->registry->create($this->context, 'billing_recipients', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'email_field' => 'email', 'active' => true]);
        $member = $this->registry->create($this->context, 'members', ['first_name' => 'Synthetic', 'last_name' => 'Member',
            'email' => 'fixture@example.invalid', 'status_id' => $status['id'], 'type_id' => $type['id'], 'billing_recipient_id' => $recipient['id']]);
        $this->assertSame(1, $member['member_number']);
        $this->assertSame(64, strlen($member['_version']));
        $company = $this->registry->create($this->context, 'members', ['company' => 'Synthetic company',
            'email' => 'fixture@example.invalid', 'status_id' => $status['id'], 'type_id' => $type['id'], 'billing_recipient_id' => $recipient['id']]);
        $this->assertNull($company['first_name']);
        $this->assertNull($company['last_name']);
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Synthetic', 'active' => true]);
        $season = $this->registry->create($this->context, 'seasons', ['name' => 'Synthetic', 'active' => true]);
        $teamSeason = $this->registry->create($this->context, 'team_seasons', ['team_id' => $team['id'], 'season_id' => $season['id'], 'active' => true]);
        $function = $this->registry->create($this->context, 'staff_functions', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        foreach (['team_players', 'team_staff', 'technical_leads'] as $resource) {
            $input = ['member_id' => $member['id'], 'team_season_id' => $teamSeason['id'], 'active' => true];
            if ($resource === 'team_staff') {
                $input['staff_function_id'] = $function['id'];
            }
            $assignment = $this->registry->create($this->context, $resource, $input);
            $this->assertSame($member['id'], $assignment['member_id']);
            $this->assertSame(64, strlen($assignment['_version']));
            $this->rejects(fn () => $this->registry->create($this->context, $resource, $input));
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_team_and_season_writes_use_audited_business_services(): void
    {
        $this->fixture();
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Synthetic U17', 'active' => true]);
        $season = $this->registry->create($this->context, 'seasons', ['name' => 'Synthetic season', 'active' => true]);
        $assignment = $this->registry->create($this->context, 'team_seasons', ['team_id' => $team['id'], 'season_id' => $season['id'], 'active' => true]);
        $this->assertSame('Synthetic U17 / Synthetic season', $assignment['label']);
        $this->assertSame(64, strlen($assignment['_version']));
        $updated = $this->registry->update($this->context, 'team_seasons', $assignment['id'], [
            'team_id' => $team['id'], 'season_id' => $season['id'], 'active' => false, '_version' => $assignment['_version'],
        ]);
        $this->assertFalse($updated['active']);
        $this->rejects(fn () => $this->registry->update($this->context, 'team_seasons', $assignment['id'], [
            'team_id' => $team['id'], 'season_id' => $season['id'], 'active' => true, '_version' => $assignment['_version'],
        ]));
        $this->assertSame(4, $this->db->table('clubora.object_changes')->where('tenant_id', $this->context->tenant->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_pagination_search_and_filters_are_bounded_and_tenant_scoped(): void
    {
        $this->fixture();
        foreach (['Alpha% one', 'Alpha two', 'Beta'] as $name) {
            $this->registry->create($this->context, 'teams', ['name' => $name, 'active' => true]);
        }
        $page = $this->registry->list($this->context, 'teams', ['per_page' => 2, 'filters' => ['active' => true]]);
        $this->assertCount(2, $page['data']);
        $this->assertTrue($page['meta']['has_more']);
        $this->assertCount(1, $this->registry->list($this->context, 'teams', ['page' => 2, 'per_page' => 2])['data']);
        $this->assertCount(1, $this->registry->list($this->context, 'teams', ['search' => 'Alpha%'])['data']);
        $ascending = array_column($this->registry->list($this->context, 'teams', ['sort' => 'name'])['data'], 'name');
        $descending = array_column($this->registry->list($this->context, 'teams', ['sort' => '-name'])['data'], 'name');
        $this->assertSame('Beta', $descending[0]);
        $this->assertSame(array_reverse($ascending), $descending);
        foreach ([['per_page' => 101], ['per_page' => 0], ['per_page' => ''], ['page' => ''], ['page' => 10001], ['include' => 'bankAccounts'],
            ['filters' => ['tenant_id' => 1]], ['filters' => ['active' => ['operator' => '!=']]], ['sort' => 'password'],
            ['sort' => 'name desc; DROP TABLE teams'], ['sort' => 'name,-id'], ['sort' => '--name'], ['sort' => ['name']], ['sort' => '']] as $input) {
            $this->rejects(fn () => $this->registry->list($this->context, 'teams', $input));
        }
        $other = Tenant::create(['slug' => 'api-other-'.Str::random(10), 'name' => 'Other']);
        $foreign = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $other->id, 'name' => 'Other team', 'active' => true]);
        $this->rejects(fn () => $this->registry->show($this->context, 'teams', $foreign), ModelNotFoundException::class);
        $this->rejects(fn () => $this->registry->update($this->context, 'teams', $foreign, ['name' => 'Changed', 'active' => true]), ModelNotFoundException::class);
        $this->assertCount(3, $this->registry->list($this->context, 'teams')['data']);
    }

    public function test_read_only_scope_and_fresh_profile_revocation_cannot_be_bypassed_by_web_cache(): void
    {
        $this->fixture();
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Synthetic', 'active' => true]);
        $this->tokenScopes(['teams:read']);
        $this->rejects(fn () => $this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Denied', 'active' => true]), HttpException::class);
        $this->assertSame('Synthetic', $this->registry->show($this->context, 'teams', $team['id'])['name']);
        request()->attributes->set(BackofficeReadContext::class, [
            'account' => $this->context->actor->id, 'permissionCache' => [], 'tenants' => collect([$this->context->tenant]),
        ]);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => '[]']);
        $this->rejects(fn () => $this->registry->show($this->context, 'teams', $team['id']), HttpException::class);
        $this->assertNotNull(request()->attributes->get(BackofficeReadContext::class));
        request()->attributes->remove(BackofficeReadContext::class);
    }

    public function test_technical_profiles_never_use_admin_office_or_personal_roles(): void
    {
        $this->fixture();
        foreach (['admin', 'office', 'trainer', 'technical_lead', 'member'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->context->actor->id)->update(['role' => $role, 'access_profile_id' => null]);
            $this->rejects(fn () => $this->registry->create($this->context, 'teams', ['name' => 'Denied', 'active' => true]), HttpException::class);
            $this->rejects(fn () => $this->registry->list($this->context, 'members'), HttpException::class);
        }
    }

    public function test_unknown_write_fields_and_immutable_catalog_keys_are_rejected(): void
    {
        $this->fixture();
        foreach (['tenant_id', 'legacy_id', 'password', 'bexio_contact_id', 'bank_accounts', 'id'] as $field) {
            $this->rejects(fn () => $this->registry->create($this->context, 'teams', ['name' => 'Denied', 'active' => true, $field => 1]));
        }
        $gender = $this->registry->create($this->context, 'genders', ['name' => 'Synthetic', 'code' => 'fixture', 'active' => true, 'sort_order' => 1]);
        $this->rejects(fn () => $this->registry->update($this->context, 'genders', $gender['id'], ['name' => 'Changed', 'code' => 'hijack', 'active' => true, 'sort_order' => 1]));
        $this->rejects(fn () => $this->registry->action($this->context, 'teams', 1, 'delete'), HttpException::class);
    }

    public function test_status_changes_require_extra_token_scope_even_when_profile_allows_them(): void
    {
        $this->fixture();
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Synthetic', 'active' => true]);
        $this->tokenScopes(['teams:update']);
        $this->rejects(fn () => $this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Synthetic', 'active' => false, '_version' => $team['_version']]), HttpException::class);
        $this->tokenScopes(['teams:update', 'teams:deactivate']);
        $this->assertFalse($this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Synthetic', 'active' => false, '_version' => $team['_version']])['active']);
    }

    public function test_catalog_updates_require_current_version_and_preserve_audit_on_conflicts(): void
    {
        $this->fixture();
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Original', 'active' => true]);
        $this->assertSame(64, strlen($team['_version']));
        $this->rejects(fn () => $this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Missing version', 'active' => true]));
        $updated = $this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Changed', 'active' => true, '_version' => $team['_version']]);
        $this->assertNotSame($team['_version'], $updated['_version']);
        $this->rejects(fn () => $this->registry->update($this->context, 'teams', $team['id'], ['name' => 'Stale overwrite', 'active' => true, '_version' => $team['_version']]));
        $this->assertSame('Changed', $this->registry->show($this->context, 'teams', $team['id'])['name']);
        $this->assertSame(2, $this->db->table('clubora.object_changes')->where('tenant_id', $this->context->tenant->id)->count());
    }

    public function test_training_competition_and_facility_revision_workflows(): void
    {
        $this->fixture();
        $team = $this->registry->create($this->context, 'teams', ['name' => 'Synthetic', 'active' => true]);
        $season = $this->registry->create($this->context, 'seasons', ['name' => 'Synthetic', 'active' => true]);
        $assignment = $this->registry->create($this->context, 'team_seasons', ['team_id' => $team['id'], 'season_id' => $season['id'], 'active' => true]);
        $options = [];
        foreach (['field', 'period', 'weekday'] as $category) {
            $options[$category] = $this->registry->create($this->context, 'training_options', ['name' => 'Synthetic', 'code' => 'fixture', 'category' => $category, 'sort_order' => 1, 'active' => true])['id'];
        }
        $slot = $this->registry->create($this->context, 'training_slots', [
            'team_season_id' => $assignment['id'], 'period_id' => $options['period'], 'weekday_id' => $options['weekday'],
            'field_id' => $options['field'], 'starts_at' => '18:00', 'ends_at' => '19:30',
            'all_fields' => false, 'without_main' => false, 'synthetic_only' => false, 'website_visible' => false, 'active' => true,
        ]);
        $this->assertSame(1, $slot['revision']);
        $status = $this->registry->create($this->context, 'facility_statuses', ['name' => 'Open', 'code' => 'fixture', 'blocks_training' => false, 'sort_order' => 1, 'active' => true]);
        $fieldId = $this->db->table('clubora.field_availabilities')->insertGetId(['tenant_id' => $this->context->tenant->id,
            'field_id' => $options['field'], 'status_id' => $status['id'], 'note' => 'Synthetic', 'active' => true, 'revision' => 1]);
        $field = $this->registry->update($this->context, 'field_availability', $fieldId, ['field_id' => $options['field'],
            'status_id' => $status['id'], 'note' => 'Updated synthetic', 'active' => true, 'revision' => 1]);
        $this->assertSame(2, $field['revision']);
        $this->rejects(fn () => $this->registry->create($this->context, 'field_availability', []), HttpException::class);
        $match = $this->registry->create($this->context, 'competitions', ['plays_on' => '2026-11-01', 'host_name' => 'Synthetic home', 'guest_name' => 'Synthetic guest', 'show_on_signage' => false, 'active' => true]);
        $this->assertSame('2026-11-01', $match['plays_on']);
        $this->assertSame(1, $match['revision']);
        $input = ['plays_on' => '2026-11-01', 'host_name' => 'Changed', 'guest_name' => 'Synthetic guest', 'show_on_signage' => false, 'active' => true, 'revision' => 1];
        $this->assertSame(2, $this->registry->update($this->context, 'competitions', $match['id'], $input)['revision']);
        $this->rejects(fn () => $this->registry->update($this->context, 'competitions', $match['id'], $input));
        $this->tokenScopes(['competitions:update']);
        $this->rejects(fn () => $this->registry->update($this->context, 'competitions', $match['id'], array_replace($input, ['revision' => 2, 'show_on_signage' => true])), HttpException::class);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_member_dto_version_update_and_archive_do_not_expose_private_attributes(): void
    {
        $this->fixture();
        $member = new Member;
        $member->forceFill(['tenant_id' => $this->context->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Member',
            'company' => null, 'email' => 'fixture@example.invalid', 'member_number' => 1, 'legacy_photo' => 'private-path',
            'bexio_contact_id' => 999999])->save();
        $dto = $this->registry->show($this->context, 'members', $member->id);
        $this->assertArrayNotHasKey('legacy_photo', $dto);
        $this->assertArrayNotHasKey('bexio_contact_id', $dto);
        $this->assertArrayNotHasKey('tenant_id', $dto);
        $this->assertSame(64, strlen($dto['_version']));
        $updated = $this->registry->update($this->context, 'members', $member->id, ['first_name' => 'Updated', '_version' => $dto['_version']]);
        $this->assertSame('Updated', $updated['first_name']);
        $this->rejects(fn () => $this->registry->update($this->context, 'members', $member->id, ['first_name' => 'Stale', '_version' => $dto['_version']]));
        $this->assertTrue($this->registry->action($this->context, 'members', $member->id, 'archive', ['_version' => $updated['_version']])['archived']);
        $this->rejects(fn () => $this->registry->show($this->context, 'members', $member->id), ModelNotFoundException::class);
    }
}
