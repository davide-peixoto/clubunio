<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\Modules\{ApiModuleRegistry, AuxiliaryApiModules};
use App\ClubUnio\Models\{Account, ApiIntegration, Invoice, InvoiceLine, Member, Tenant};
use App\ClubUnio\Services\PermissionCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuxiliaryApiModulesTest extends TestCase
{
    private $db;
    private ApiContext $context;
    private ApiModuleRegistry $registry;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        Http::preventStrayRequests();
        Mail::fake();
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $tenant = Tenant::create(['slug' => 'aux-api-'.Str::random(12), 'name' => 'Synthetic API auxiliary']);
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(20), 'display_name' => 'API fixture', 'password' => bcrypt(Str::random(40)), 'active' => true, 'api_only' => true])->save();
        $profile = $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Fixture', 'active' => true,
            'permissions' => json_encode(PermissionCatalog::keys(), JSON_THROW_ON_ERROR)]);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'profile', 'access_profile_id' => $profile]);
        $scopes = [];
        foreach (PermissionCatalog::keys() as $permission) {
            [$module, $function] = explode('.', $permission);
            $scopes[] = $module.':'.($function === 'view' ? 'read' : $function);
        }
        $integration = new ApiIntegration;
        $integration->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'access_profile_id' => $profile,
            'name' => 'Synthetic auxiliary integration', 'scopes' => $scopes, 'active' => true, 'created_by' => $actor->id])->save();
        $token = $integration->createToken('fixture', $scopes, now()->addHour())->accessToken;
        $this->context = new ApiContext($actor, $tenant, $integration, $token);
        $this->registry = new ApiModuleRegistry;
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $tenant->id, 'member_number' => 1, 'first_name' => 'Synthetic', 'last_name' => 'Member'])->save();
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

    public function test_auxiliary_read_fields_match_schema_and_never_include_storage_or_provider_secrets(): void
    {
        $definitions = app(AuxiliaryApiModules::class)->definitions();
        $this->assertCount(14, $definitions);
        $columns = $this->db->table('information_schema.columns')->where('table_schema', 'clubora')->get(['table_name', 'column_name'])->groupBy('table_name');
        foreach ($definitions as $key => $definition) {
            $table = Str::after((new $definition['model'])->getTable(), 'clubora.');
            $this->assertSame([], array_values(array_diff($definition['read_fields'], $columns->get($table)->pluck('column_name')->all())), $key);
            foreach (['tenant_id', 'storage_path', 'filename', 'blob_url', 'snapshot_encrypted', 'source_snapshot', 'external_snapshot', 'connection_hash', 'proof_reference', 'iban'] as $field) {
                $this->assertNotContains($field, $definition['read_fields'], $key);
            }
            $this->assertArrayHasKey($key, $this->registry->definitions());
            $this->registry->list($this->context, $key, ['per_page' => 1, 'sort' => '-id']);
        }
    }

    public function test_every_registered_read_field_exists_in_postgresql_with_explicit_query_builder_tables(): void
    {
        $columns = $this->db->table('information_schema.columns')->where('table_schema', 'clubora')
            ->get(['table_name', 'column_name'])->groupBy('table_name');
        $queryBuilderTables = ['team_events' => 'team_events', 'team_absences' => 'team_absences',
            'team_participations' => 'team_participations', 'team_guests' => 'team_guests',
            'team_invitations' => 'team_invitations', 'training_plans' => 'training_plans',
            'buses' => 'buses', 'bus_reservations' => 'bus_reservations',
            'planning_options' => 'planning_options', 'bus_reservation_statuses' => 'bus_statuses',
            'material_receipt_statuses' => 'material_receipt_statuses',
            'announcement_statuses' => 'announcement_statuses', 'sponsor_run_statuses' => 'sponsor_run_statuses'];
        $issues = [];
        foreach ($this->registry->definitions() as $resource => $definition) {
            if ($definition['model'] !== null) {
                $table = Str::after((new $definition['model'])->getTable(), 'clubora.');
            } else {
                $this->assertArrayHasKey($resource, $queryBuilderTables, 'Unreviewed query-builder projection: '.$resource);
                $table = $queryBuilderTables[$resource];
            }
            $this->assertTrue($columns->has($table), 'Missing table for '.$resource.': '.$table);
            $missing = array_values(array_diff($definition['read_fields'], $columns->get($table)->pluck('column_name')->all()));
            if ($missing !== []) {
                $issues[$resource] = $missing;
            }
        }
        $this->assertSame([], $issues, 'Registered DTOs declare nonexistent PostgreSQL columns');
    }

    public function test_events_and_member_bound_guests_are_audited_and_revision_protected(): void
    {
        $season = $this->registry->create($this->context, 'seasons', ['name' => 'Synthetic', 'active' => true]);
        $event = $this->registry->create($this->context, 'events', ['name' => 'Synthetic event', 'location' => 'Synthetic venue', 'season_id' => $season['id'], 'active' => true]);
        $input = ['member_id' => $this->member->id, 'club_event_id' => $event['id'], 'active' => true];
        $guest = $this->registry->create($this->context, 'event_guests', $input);
        $this->assertSame($this->member->id, $guest['member_id']);
        $this->rejects(fn () => $this->registry->create($this->context, 'event_guests', $input));
        $changed = $this->registry->update($this->context, 'event_guests', $guest['id'], $input + ['revision' => $guest['revision']]);
        $this->assertSame(2, $changed['revision']);
        $this->rejects(fn () => $this->registry->update($this->context, 'event_guests', $guest['id'], $input + ['revision' => $guest['revision']]));
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->context->tenant->id, 'first_name' => 'Other'])->save();
        $this->rejects(fn () => $this->registry->update($this->context, 'event_guests', $guest['id'], array_replace($input, ['member_id' => $other->id, 'revision' => 2])), ModelNotFoundException::class);
    }

    public function test_contribution_prices_and_rules_keep_overlap_revision_and_reference_guards(): void
    {
        $price = $this->registry->create($this->context, 'contribution_prices', ['name' => 'Synthetic price', 'amount' => '12.50', 'active' => true]);
        $this->assertSame('12.5000', $price['amount']);
        $this->rejects(fn () => $this->registry->update($this->context, 'contribution_prices', $price['id'], ['name' => 'Changed', 'amount' => '15', 'active' => true, 'revision' => 99]));
        $this->rejects(fn () => $this->registry->create($this->context, 'contribution_prices', ['name' => 'Denied', 'amount' => 1, 'active' => true, 'bexio_article_id' => 1]));
        $season = $this->registry->create($this->context, 'seasons', ['name' => 'Synthetic', 'active' => true]);
        $gender = $this->registry->create($this->context, 'genders', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        $rule = ['season_id' => $season['id'], 'price_id' => $price['id'], 'gender_id' => $gender['id'], 'year_from' => 2010, 'year_to' => 2015, 'active' => true];
        $this->assertSame(1, $this->registry->create($this->context, 'contribution_rules', $rule)['revision']);
        $this->rejects(fn () => $this->registry->create($this->context, 'contribution_rules', $rule));
    }

    public function test_notice_publication_requires_separate_send_scope_before_mutation(): void
    {
        $this->tokenScopes(['signage:create']);
        $input = ['shows_on' => '2026-11-01', 'title' => 'Synthetic notice', 'body' => 'Synthetic only', 'active' => true];
        $this->rejects(fn () => $this->registry->create($this->context, 'signage_notices', $input), HttpException::class, 403);
        $this->tokenScopes(['signage:create', 'signage:send']);
        $this->assertSame('2026-11-01', $this->registry->create($this->context, 'signage_notices', $input)['shows_on']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_credit_creation_release_and_cancel_stay_local_and_never_confirm_provider_state(): void
    {
        $status = [];
        foreach (['draft', 'released', 'confirmed', 'cancelled'] as $code) {
            $status[$code] = $this->db->table('clubora.financial_adjustment_statuses')->insertGetId(['tenant_id' => $this->context->tenant->id, 'name' => $code, 'code' => $code]);
        }
        $workflow = $this->db->table('clubora.invoice_workflow_statuses')->insertGetId(['tenant_id' => $this->context->tenant->id, 'name' => 'Released', 'code' => 'released']);
        $invoice = new Invoice;
        $invoice->forceFill(['tenant_id' => $this->context->tenant->id, 'member_id' => $this->member->id, 'workflow_status_id' => $workflow])->save();
        $line = new InvoiceLine;
        $line->forceFill(['tenant_id' => $this->context->tenant->id, 'invoice_id' => $invoice->id, 'description' => 'Synthetic', 'gross_amount' => '20.00', 'net_amount' => '20.00'])->save();
        $input = ['invoice_id' => $invoice->id, 'revision' => $invoice->refresh()->revision, 'request_id' => (string) Str::uuid(),
            'reason' => 'Synthetic correction', 'lines' => [['invoice_line_id' => $line->id, 'amount' => '10.00']]];
        $note = $this->registry->create($this->context, 'credit_notes', $input);
        $this->assertSame(1000, $note['amount_cents']);
        $this->assertSame($note['id'], $this->registry->create($this->context, 'credit_notes', $input)['id']);
        $this->assertArrayNotHasKey('source_snapshot', $note);
        $released = $this->registry->action($this->context, 'credit_notes', $note['id'], 'release', ['revision' => $note['revision']]);
        $this->assertSame($status['released'], $released['status_id']);
        $this->rejects(fn () => $this->registry->action($this->context, 'credit_notes', $note['id'], 'confirm', ['revision' => $released['revision']]), HttpException::class, 404);
        $cancelled = $this->registry->action($this->context, 'credit_notes', $note['id'], 'cancel', ['revision' => $released['revision'], 'reason' => 'Synthetic cancellation']);
        $this->assertSame($status['cancelled'], $cancelled['status_id']);
        $refund = new \App\ClubUnio\Models\Refund;
        $refund->forceFill(['tenant_id' => $this->context->tenant->id, 'invoice_id' => $invoice->id,
            'status_id' => $status['draft'], 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'reason' => 'Synthetic refund fixture', 'amount_cents' => 1000, 'entitlement_snapshot' => [], 'created_by' => $this->context->actor->id])->save();
        $refundDto = $this->registry->action($this->context, 'refunds', $refund->id, 'cancel', ['revision' => $refund->refresh()->revision, 'reason' => 'Synthetic cancellation']);
        $this->assertSame($status['cancelled'], $refundDto['status_id']);
        $this->assertNull($refund->refresh()->paid_on);
        $this->rejects(fn () => $this->registry->create($this->context, 'credit_notes', array_replace($input, ['lines' => [['invoice_line_id' => $line->id, 'amount' => 1, 'tenant_id' => 1]]])));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_cash_assertions_and_customer_credit_writes_are_explicitly_unsupported(): void
    {
        foreach (['customer_credits', 'refunds', 'documents', 'registration_documents', 'material_receipts', 'legacy_material_receipts'] as $resource) {
            $this->rejects(fn () => $this->registry->create($this->context, $resource, []), HttpException::class, 405);
            $this->rejects(fn () => $this->registry->update($this->context, $resource, 1, []), HttpException::class, 405);
        }
        $this->rejects(fn () => $this->registry->action($this->context, 'refunds', 1, 'confirm', []), HttpException::class, 404);
        $this->rejects(fn () => $this->registry->action($this->context, 'customer_credits', 1, 'allocate', []), HttpException::class, 404);
        Http::assertNothingSent();
    }

    public function test_document_metadata_exposes_original_name_but_not_storage_or_opaque_filename(): void
    {
        $type = $this->registry->create($this->context, 'document_types', ['name' => 'Synthetic', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        $id = $this->db->table('clubora.member_documents')->insertGetId(['tenant_id' => $this->context->tenant->id,
            'member_id' => $this->member->id, 'document_type_id' => $type['id'], 'filename' => 'opaque-secret-name',
            'storage_path' => 'private/secret', 'original_filename' => 'Synthetic.pdf', 'file_size' => 100, 'content_type' => 'application/pdf']);
        $dto = $this->registry->show($this->context, 'documents', $id);
        $this->assertSame('Synthetic.pdf', $dto['original_filename']);
        $this->assertArrayNotHasKey('filename', $dto);
        $this->assertArrayNotHasKey('storage_path', $dto);
        $this->assertStringNotContainsString('private/secret', json_encode($dto));
        $this->assertStringNotContainsString('opaque-secret-name', json_encode($dto));
        $this->tokenScopes(['documents:download']);
        $this->rejects(fn () => $this->registry->show($this->context, 'documents', $id), HttpException::class, 403);
    }

    public function test_member_change_review_is_revision_guarded_and_requires_member_write_scope_to_approve(): void
    {
        foreach (['pending', 'approved', 'rejected', 'withdrawn'] as $state) {
            $statuses[$state] = $this->db->table('clubora.member_change_statuses')->insertGetId(['tenant_id' => $this->context->tenant->id, 'code' => $state, 'name' => $state, 'color' => 'gray']);
        }
        $id = $this->db->table('clubora.member_change_requests')->insertGetId(['tenant_id' => $this->context->tenant->id,
            'member_id' => $this->member->id, 'requested_by' => $this->context->actor->id, 'status_id' => $statuses['pending'],
            'snapshot_encrypted' => Crypt::encryptString('{"before":{},"changes":{}}'), 'member_version' => str_repeat('a', 64), 'revision' => 1]);
        $this->tokenScopes(['member_changes:read', 'member_changes:approve']);
        $this->rejects(fn () => $this->registry->authorize($this->context, 'member_changes', 'approve'), HttpException::class, 403);
        $dto = $this->registry->show($this->context, 'member_changes', $id);
        $this->assertArrayNotHasKey('snapshot_encrypted', $dto);
        $this->rejects(fn () => $this->registry->action($this->context, 'member_changes', $id, 'reject', ['revision' => 99, 'note' => 'Synthetic rejection']));
        $dto = $this->registry->action($this->context, 'member_changes', $id, 'reject', ['revision' => 1, 'note' => 'Synthetic rejection']);
        $this->assertSame('rejected', $dto['state']);
        $this->assertSame(2, $dto['revision']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
