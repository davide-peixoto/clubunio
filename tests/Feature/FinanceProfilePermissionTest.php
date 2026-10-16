<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioBillingRecipientService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\ContributionBatchService;
use App\ClubUnio\Services\ContributionInvoiceService;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\CustomerCreditService;
use App\ClubUnio\Services\DocumentUploadService;
use App\ClubUnio\Services\ExpenseDocumentService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\FinanceExportService;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\InvoicePdfService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\RegistrationBlobStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceProfilePermissionTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private int $profile;
    private int $member;
    private int $category;
    private array $expenseData;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')) {
            (require database_path('clubunio/2026_10_16_000069_access_profiles.php'))->up();
        }
        if (! Schema::connection('clubunio')->hasColumn('clubora.tenant_accounts', 'access_profile_id')) {
            (require database_path('clubunio/2026_10_16_000070_account_access_profiles.php'))->up();
        }
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->tenant = Tenant::create(['slug' => 'finance-permissions-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->profile = $this->insert('access_profiles', ['name' => 'Synthetic', 'permissions' => '[]']);
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'profile', 'access_profile_id' => $this->profile, 'portal_enabled' => false]);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic']);
        $this->category = $this->insert('invoice_categories', ['name' => 'Synthetic', 'code' => 'fixture']);
        foreach (['draft', 'released', 'cancelled'] as $code) {
            $this->insert('invoice_workflow_statuses', ['name' => $code, 'code' => $code]);
        }
        $this->insert('invoice_line_statuses', ['name' => 'Pending', 'code' => 'P']);
        foreach (['draft', 'released', 'confirmed', 'cancelled'] as $code) {
            $this->insert('financial_adjustment_statuses', ['name' => $code, 'code' => $code]);
        }
        foreach (['O', 'F', 'Z', 'R', 'B'] as $code) {
            $this->insert('expense_statuses', ['name' => $code, 'code' => $code]);
        }
        $season = $this->insert('seasons', ['name' => 'Synthetic']);
        $team = $this->insert('teams', ['name' => 'Synthetic']);
        $this->expenseData = ['member_id' => $this->member,
            'team_season_id' => $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic']),
            'round_id' => $this->insert('expense_rounds', ['name' => 'Synthetic', 'code' => 'fixture']),
            'bank_account_id' => $this->insert('member_bank_accounts', ['member_id' => $this->member, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]),
        ];
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function grant(array $permissions): void
    {
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode($permissions)]);
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Unauthorized finance operation succeeded');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    private function invoice(): Invoice
    {
        return app(InvoiceWorkflowService::class)->create($this->actor, $this->tenant,
            ['member_id' => $this->member, 'invoice_category_id' => $this->category, 'tax_mode' => 'exempt']);
    }

    public function test_invoice_functions_are_independent_and_unknown_operations_deny(): void
    {
        $service = app(InvoiceWorkflowService::class);
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $this->assertTrue(app(InvoiceAccessService::class)->visible($this->actor, $this->tenant)->whereKey($invoice->id)->exists());
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line', ['description' => 'Synthetic', 'gross_amount' => '10.00', 'discount' => '0']);
        foreach (['header', 'release', 'cancel', 'remove_line', 'restore_line', 'unknown'] as $operation) {
            $this->denied(fn () => $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, $operation));
        }
        $this->grant(['invoices.view', 'invoices.approve']);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'release');
        $this->assertSame('released', $invoice->workflowStatus->code);
        $this->grant(['invoices.view', 'invoices.cancel']);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'cancel', ['reason' => 'Synthetic cancellation']);
        $this->assertSame('cancelled', $invoice->workflowStatus->code);
        $this->denied(fn () => $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'archive'));
        $this->grant(['invoices.view', 'invoices.deactivate']);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'archive', ['reason' => 'Synthetic archive']);
        $this->assertFalse($invoice->active);
        $this->grant(['invoices.view', 'invoices.restore']);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'restore', ['reason' => 'Synthetic restore']);
        $this->assertTrue($invoice->active);
    }

    public function test_read_only_denies_write_download_export_send_sync_and_admin_settings(): void
    {
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $this->grant(['invoices.view', 'expenses.view', 'credits.view', 'contributions.view', 'bexio.view']);
        $a = $this->actor;
        $t = $this->tenant;
        $actions = [
            fn () => $this->invoice(),
            fn () => app(InvoicePdfService::class)->open($a, $t, $invoice->id, false),
            fn () => app(FinanceExportService::class)->download($a, $t, 'invoices', Invoice::query()),
            fn () => app(FinanceExportService::class)->download($a, $t, 'payments', Expense::query()),
            fn () => app(ExpenseService::class)->save($a, $t, null, $this->expenseData),
            fn () => app(ContributionBatchService::class)->create($a, $t, []),
            fn () => app(ContributionInvoiceService::class)->create($a, $t, $this->member, 0, ''),
            fn () => app(ContributionTrainingService::class)->save($a, $t, \App\ClubUnio\Models\ContributionPrice::class, null, []),
            fn () => app(CreditNoteService::class)->create($a, $t, $invoice->id, []),
            fn () => app(CreditNoteService::class)->createRefund($a, $t, $invoice->id, []),
            fn () => app(CreditNoteService::class)->changeRefund($a, $t, 0, 1, 'confirm', []),
            fn () => app(BexioDispatchTrialService::class)->preview($a, $t, 'invoice', $invoice->id),
            fn () => app(BexioReconciliationService::class)->preview($a, $t, 'invoice', $invoice->id),
            fn () => app(BexioBillingRecipientService::class)->save($a, $t, $this->member, []),
            fn () => app(CustomerCreditService::class)->register($a, $t, 0, []),
            fn () => app(BexioSandboxDispatchService::class)->authorize($a, $t),
            fn () => app(BexioConnectionService::class)->connect($t, [], '', $a->id),
        ];
        foreach ($actions as $action) {
            $this->denied($action);
        }
        $this->assertFalse(BexioConnectionService::canManage($a, $t));
        $this->grant(\App\ClubUnio\Services\PermissionCatalog::keys());
        $this->assertFalse(BexioConnectionService::canManage($a, $t));
        $this->denied(fn () => app(BexioConnectionService::class)->connect($t, [], '', $a->id));
        $this->denied(fn () => app(BexioSandboxDispatchService::class)->authorize($a, $t));
        $request = \Illuminate\Http\Request::create('/bexio');
        $request->setUserResolver(fn ($guard = null) => $a);
        $this->denied(fn () => app(\App\ClubUnio\Http\BexioConnectionController::class)->show($request, $t, app(BexioConnectionService::class)));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_expense_create_update_and_transitions_have_no_read_fallback(): void
    {
        $service = app(ExpenseService::class);
        $this->grant(['expenses.view', 'expenses.create']);
        $expense = $service->save($this->actor, $this->tenant, null, $this->expenseData);
        $this->assertFalse($service->editable($this->actor, $this->tenant, $expense));
        $this->assertSame([], $service->targets($this->actor, $this->tenant, $expense));
        $this->grant(['expenses.view', 'expenses.update']);
        $expense = $service->save($this->actor, $this->tenant, $expense->id, ['revision' => $expense->revision, 'notes' => 'Updated']);
        $this->assertSame('Updated', $expense->notes);
        $this->grant(['expenses.view', 'expenses.send']);
        $this->assertSame(['F'], $service->targets($this->actor, $this->tenant, $expense));
        $this->grant(['expenses.view', 'expenses.cancel']);
        $this->assertSame(['R'], $service->targets($this->actor, $this->tenant, $expense));
        $status = $this->db->table('clubora.expense_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'R')->value('id');
        $expense = $service->transition($this->actor, $this->tenant, $expense->id, ['revision' => $expense->revision, 'status_id' => $status, 'reason' => 'Synthetic rejection']);
        $this->assertSame('R', $expense->status->code);
        $this->grant(['expenses.view']);
        $this->assertSame([], $service->targets($this->actor, $this->tenant, $expense));
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $expense->id, ['revision' => $expense->revision]));
    }

    public function test_revocation_is_fresh_and_profiles_never_get_portal_or_foreign_tenant_access(): void
    {
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $access = app(InvoiceAccessService::class);
        $this->assertTrue($access->visible($this->actor, $this->tenant)->whereKey($invoice->id)->exists());
        $this->assertFalse(app(ExpenseService::class)->portalAllowed($this->actor, $this->tenant));
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic']);
        $this->assertFalse(app(ExpenseService::class)->portalAllowed($this->actor, $this->tenant));
        $this->assertFalse($this->actor->hasUnrestrictedBackofficeAccess($this->tenant));
        $this->assertTrue($this->actor->canAccessTenant($this->tenant));
        $this->grant(['invoices.view', 'expenses.view']);
        $this->denied(fn () => app(ExpenseService::class)->save($this->actor, $this->tenant, null, $this->expenseData));
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Synthetic foreign']);
        $this->assertFalse($access->visible($this->actor, $other)->exists());
        $this->denied(fn () => app(InvoiceWorkflowService::class)->create($this->actor, $other, []));
        $this->grant([]);
        $this->assertFalse($access->visible($this->actor, $this->tenant)->exists());
        $this->denied(fn () => $this->invoice());
        $this->grant(['invoices.view', 'invoices.create']);
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => false]);
        $this->denied(fn () => $this->invoice());
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['active' => false]);
        $this->denied(fn () => $this->invoice());
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['active' => true]);
        $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => false]);
        $this->denied(fn () => $this->invoice());
    }

    public function test_download_and_export_grants_do_not_require_write_permissions(): void
    {
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $this->grant(['invoices.view', 'invoices.download', 'invoices.export']);
        $response = app(InvoicePdfService::class)->open($this->actor, $this->tenant, $invoice->id, false);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $response = app(FinanceExportService::class)->download($this->actor, $this->tenant, 'invoices', Invoice::query()->whereRaw('1=0'));
        $this->assertSame(200, $response->getStatusCode());
        $this->denied(fn () => $this->invoice());
        Http::assertNothingSent();
    }

    public function test_admin_and_office_keep_business_access_but_office_cannot_manage_oauth(): void
    {
        foreach (['office', 'admin'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
                ->update(['role' => $role, 'access_profile_id' => null]);
            $invoice = $this->invoice();
            $this->assertTrue(app(InvoiceWorkflowService::class)->editable($this->actor, $this->tenant, $invoice));
            $this->assertSame($role === 'admin', BexioConnectionService::canManage($this->actor, $this->tenant));
        }
    }

    public function test_contribution_maintenance_and_credit_approval_are_explicit(): void
    {
        $this->grant(['contributions.view', 'contributions.create']);
        $prices = app(ContributionTrainingService::class);
        $model = \App\ClubUnio\Models\ContributionPrice::class;
        $price = $prices->save($this->actor, $this->tenant, $model, null, ['name' => 'Synthetic', 'amount' => '10.00', 'active' => true]);
        $this->assertSame('Synthetic', $price->name);
        $this->grant(['contributions.view', 'contributions.update']);
        $input = ['name' => 'Synthetic', 'amount' => '10.00', 'active' => false, 'revision' => $price->revision];
        $this->denied(fn () => $prices->save($this->actor, $this->tenant, $model, $price->id, $input));
        $this->grant(['contributions.view', 'contributions.update', 'contributions.deactivate']);
        $this->assertFalse($prices->save($this->actor, $this->tenant, $model, $price->id, $input)->active);

        $this->grant(['invoices.view', 'invoices.create', 'invoices.approve', 'credits.view', 'credits.create']);
        $invoice = $this->invoice();
        $workflow = app(InvoiceWorkflowService::class);
        $invoice = $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line', ['description' => 'Synthetic', 'gross_amount' => '10', 'discount' => '0']);
        $invoice = $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'release');
        $credits = app(CreditNoteService::class);
        $note = $credits->create($this->actor, $this->tenant, $invoice->id, ['request_id' => (string) Str::uuid(), 'revision' => $invoice->revision,
            'reason' => 'Synthetic adjustment', 'lines' => [['invoice_line_id' => $invoice->activeLines()->firstOrFail()->id, 'amount' => '5.00']]]);
        $this->assertSame(500, $note->amount_cents);
        $this->denied(fn () => $credits->change($this->actor, $this->tenant, $note->id, $note->revision, 'release'));
        $this->grant(['credits.view', 'credits.approve']);
        $note = $credits->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $this->assertSame('released', $note->status->code);
        $this->denied(fn () => $credits->change($this->actor, $this->tenant, $note->id, $note->revision, 'cancel'));
        $this->grant(['credits.view', 'credits.cancel']);
        $note = $credits->change($this->actor, $this->tenant, $note->id, $note->revision, 'cancel', ['reason' => 'Synthetic cancellation']);
        $this->assertSame('cancelled', $note->status->code);
        Http::assertNothingSent();
    }

    public function test_sync_requires_both_integration_and_document_permissions(): void
    {
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $service = app(BexioReconciliationService::class);
        $this->grant(['bexio.view', 'bexio.sync', 'invoices.view']);
        $this->denied(fn () => $service->previewNow($this->actor, $this->tenant, 'invoice', $invoice->id));
        $this->grant(['bexio.view', 'bexio.sync', 'invoices.view', 'invoices.sync']);
        $check = $service->previewNow($this->actor, $this->tenant, 'invoice', $invoice->id);
        $this->assertSame('disabled', $this->db->table('clubora.bexio_checks')->where('id', $check)->value('state'));
        $this->grant(['bexio.view', 'invoices.view', 'invoices.sync']);
        $this->denied(fn () => $service->previewNow($this->actor, $this->tenant, 'invoice', $invoice->id));
        Http::assertNothingSent();
    }

    public function test_portal_self_ownership_survives_but_cannot_bypass_profile_permissions(): void
    {
        $this->grant(['expenses.view', 'expenses.create']);
        $service = app(ExpenseService::class);
        $expense = $service->save($this->actor, $this->tenant, null, $this->expenseData);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $this->member,
            'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic']);
        $foreignMember = $this->insert('members', ['first_name' => 'Another synthetic member']);
        $foreignExpense = $this->insert('expenses', ['member_id' => $foreignMember, 'status_id' => $expense->status_id]);
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'member', 'access_profile_id' => null, 'portal_enabled' => true]);
        $this->assertTrue($service->portalAllowed($this->actor, $this->tenant));
        $this->assertTrue($service->editable($this->actor, $this->tenant, $expense));
        $this->assertTrue($service->visible($this->actor, $this->tenant)->whereKey($expense->id)->exists());
        $this->assertFalse($service->visible($this->actor, $this->tenant)->whereKey($foreignExpense)->exists());
        $this->assertSame(['F'], $service->targets($this->actor, $this->tenant, $expense));
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'profile', 'access_profile_id' => $this->profile, 'portal_enabled' => false]);
        $this->grant(['expenses.view']);
        $this->assertFalse($service->editable($this->actor, $this->tenant, $expense));
        $this->assertFalse($service->editable($this->actor, $this->tenant, $expense, 'upload'));
        $this->assertSame([], $service->targets($this->actor, $this->tenant, $expense));
        $request = \Illuminate\Http\Request::create('/portal');
        $request->setUserResolver(fn ($guard = null) => $this->actor);
        $this->denied(fn () => app(\App\ClubUnio\Http\ExpensePortalController::class)->index($request, $this->tenant, $service));
        $this->denied(fn () => app(\App\ClubUnio\Http\InvoicePortalController::class)->index($request, $this->tenant, app(InvoiceAccessService::class)));
    }

    public function test_expense_lines_require_separate_create_update_and_lifecycle_grants(): void
    {
        $this->grant(['expenses.view', 'expenses.create']);
        $service = app(ExpenseService::class);
        $expense = $service->save($this->actor, $this->tenant, null, $this->expenseData);
        $type = $this->insert('expense_types', ['name' => 'Synthetic', 'code' => 'fixture', 'receipt_required' => false]);
        $line = $service->saveLine($this->actor, $this->tenant, $expense->id, null,
            ['expense_revision' => $expense->revision, 'expense_type_id' => $type, 'amount' => '10.00']);
        $input = ['expense_revision' => $expense->fresh()->revision, 'revision' => $line->revision, 'active' => false];
        $this->denied(fn () => $service->saveLine($this->actor, $this->tenant, $expense->id, $line->id, $input));
        $this->grant(['expenses.view', 'expenses.update']);
        $this->denied(fn () => $service->saveLine($this->actor, $this->tenant, $expense->id, $line->id, $input));
        $this->grant(['expenses.view', 'expenses.update', 'expenses.deactivate']);
        $line = $service->saveLine($this->actor, $this->tenant, $expense->id, $line->id, $input);
        $this->assertFalse($line->active);
        $input = ['expense_revision' => $expense->fresh()->revision, 'revision' => $line->revision, 'active' => true];
        $this->denied(fn () => $service->saveLine($this->actor, $this->tenant, $expense->id, $line->id, $input));
        $this->grant(['expenses.view', 'expenses.update', 'expenses.restore']);
        $this->assertTrue($service->saveLine($this->actor, $this->tenant, $expense->id, $line->id, $input)->active);
        $this->grant(['expenses.view', 'expenses.upload']);
        $this->assertTrue($service->editable($this->actor, $this->tenant, $expense->fresh(), 'upload'));
        $this->assertFalse($service->editable($this->actor, $this->tenant, $expense->fresh()));
    }

    private function uploadLine(): ExpenseLine
    {
        $status = $this->db->table('clubora.expense_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'O')->value('id');
        $expense = $this->insert('expenses', ['member_id' => $this->member, 'status_id' => $status]);
        $line = $this->insert('expense_lines', ['expense_id' => $expense, 'amount' => '10.00']);
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);

        return ExpenseLine::findOrFail($line);
    }

    public function test_expense_upload_only_profile_can_stage_and_publish_without_update(): void
    {
        $line = $this->uploadLine();
        $this->grant(['expenses.view', 'expenses.upload']);
        $this->assertFalse(app(ExpenseService::class)->editable($this->actor, $this->tenant, $line->expense));
        $bytes = "%PDF-1.4\nsynthetic permission upload\n%%EOF";
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()
            ->withArgs(fn ($folder, $filename, $content, $mime) => $folder === 'clubunio/expenses/'.$this->tenant->id.'/'.$line->id.'/'
                && str_ends_with($filename, '.pdf') && $content === $bytes && $mime === 'application/pdf')->andReturnNull();
        $result = app(ExpenseDocumentService::class)->add($this->actor, $this->tenant, $line->id,
            $line->revision, $line->expense->revision, UploadedFile::fake()->createWithContent('synthetic.pdf', $bytes));
        $this->assertSame($line->id, $result);
        $upload = app(DocumentUploadService::class)->table()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('published', $upload->state);
        $this->assertNull($upload->encrypted_bytes);
        $this->assertSame($upload->folder.$upload->filename, $line->fresh()->storage_reference);
        $this->assertSame($line->revision + 1, $line->fresh()->revision);
        $this->assertSame($line->expense->revision + 1, $line->expense->fresh()->revision);
        $this->denied(fn () => app(ExpenseService::class)->saveLine($this->actor, $this->tenant, $line->expense_id, $line->id,
            ['revision' => $line->fresh()->revision, 'expense_revision' => $line->expense->fresh()->revision, 'amount' => '20.00']));
        Http::assertNothingSent();
    }

    public function test_expense_upload_denies_missing_or_revoked_grants_and_preserves_source_guards(): void
    {
        $line = $this->uploadLine();
        $uploads = app(DocumentUploadService::class);
        $metadata = ['revision' => $line->revision, 'expense_revision' => $line->expense->revision];
        $file = UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nsynthetic queued upload\n%%EOF");
        $this->mock(RegistrationBlobStore::class)->shouldNotReceive('put');
        foreach ([['expenses.view'], ['expenses.view', 'expenses.update'], ['expenses.upload']] as $permissions) {
            $this->grant($permissions);
            $this->denied(fn () => $uploads->stage($this->actor, $this->tenant, 'expense', $line->id, $metadata, $file));
        }
        $this->assertSame(0, $uploads->table()->where('tenant_id', $this->tenant->id)->count());
        $this->grant(['expenses.view', 'expenses.upload']);
        $id = $uploads->stage($this->actor, $this->tenant, 'expense', $line->id, $metadata, $file);
        $this->assertSame('pending', $uploads->table()->find($id)->state);
        $this->grant(['expenses.view', 'expenses.update']);
        $this->denied(fn () => $uploads->process($this->actor, $this->tenant, $id));
        $this->assertSame('pending', $uploads->table()->find($id)->state);
        $this->assertNotNull($uploads->table()->find($id)->encrypted_bytes);
        $this->grant(['expenses.view', 'expenses.upload']);
        try {
            $uploads->authorizeSource($this->actor, $this->tenant, 'expense', $line->id,
                array_replace($metadata, ['revision' => $line->revision + 1]), null);
            $this->fail('Stale line revision allowed');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $released = $this->db->table('clubora.expense_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'F')->value('id');
        $line->expense->forceFill(['status_id' => $released])->save();
        $this->denied(fn () => $uploads->process($this->actor, $this->tenant, $id));
        $this->assertNull($line->fresh()->storage_reference);
        Http::assertNothingSent();
    }

    public function test_finance_blade_expense_actions_add_profile_restrictions_without_overriding_ownership(): void
    {
        $line = $this->uploadLine();
        $line->forceFill(['storage_reference' => 'synthetic.pdf'])->save();
        $this->actingAs($this->actor, 'portal');
        $statuses = \App\ClubUnio\Models\ExpenseStatus::where('tenant_id', $this->tenant->id)->get();
        $data = ['tenant' => $this->tenant, 'tenants' => collect([$this->tenant]), 'e' => $line->expense,
            'editable' => true, 'statuses' => $statuses, 'types' => collect(), 'gameTypes' => collect(),
            'errors' => new \Illuminate\Support\ViewErrorBag];
        $render = fn ($overrides = []) => view('clubunio.portal.expense', array_replace($data, $overrides))->render();
        $this->grant(['expenses.view']);
        $html = $render();
        foreach (['Position bearbeiten', 'Neue Position', 'Status ändern', 'Beleg 1', 'expense-line-form'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }
        $this->grant(['expenses.view', 'expenses.update']);
        $html = $render();
        $this->assertStringContainsString('Position bearbeiten', $html);
        $this->assertStringNotContainsString('Neue Position', $html);
        $this->assertStringNotContainsString('type="checkbox" name="active"', $html);
        $this->assertStringContainsString('type="hidden" name="active" value="1"', $html);
        $this->grant(['expenses.view', 'expenses.update', 'expenses.deactivate']);
        $this->assertStringContainsString('type="checkbox" name="active"', $render());
        foreach (['send' => ['F'], 'approve' => ['Z', 'B'], 'cancel' => ['R']] as $function => $codes) {
            $this->grant(['expenses.view', 'expenses.'.$function]);
            $html = $render();
            foreach ($statuses as $status) {
                $option = '<option value="'.$status->id.'">'.$status->name.'</option>';
                if (in_array($status->code, $codes, true)) {
                    $this->assertStringContainsString($option, $html);
                } else {
                    $this->assertStringNotContainsString($option, $html);
                }
            }
        }
        $this->grant(['expenses.view', 'expenses.download']);
        $this->assertStringContainsString('Beleg 1', $render());
        $this->grant(['expenses.view', 'expenses.create', 'expenses.update', 'expenses.send', 'expenses.approve', 'expenses.cancel']);
        $html = $render(['editable' => false, 'statuses' => collect()]);
        foreach (['Position bearbeiten', 'Neue Position', 'Status ändern'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'member', 'access_profile_id' => null, 'portal_enabled' => true]);
        $html = $render();
        foreach (['Position bearbeiten', 'Neue Position', 'Status ändern', 'Beleg 1'] as $control) {
            $this->assertStringContainsString($control, $html);
        }
        $this->assertStringNotContainsString('Position bearbeiten', $render(['editable' => false]));
    }

    public function test_finance_blade_lists_create_forms_and_member_invoice_links_respect_profile_grants(): void
    {
        $this->actingAs($this->actor, 'portal');
        $data = ['tenant' => $this->tenant, 'tenants' => collect([$this->tenant]), 'errors' => new \Illuminate\Support\ViewErrorBag,
            'q' => '', 'statuses' => collect(), 'seasons' => collect(),
            'expenses' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25)];
        $this->grant(['expenses.view']);
        $html = view('clubunio.portal.expenses', $data)->render();
        $this->assertStringNotContainsString('Spesenbeleg erfassen', $html);
        $this->assertStringNotContainsString('>Rechnungen</a>', $html);
        $this->grant(['expenses.view', 'expenses.create']);
        $this->assertStringContainsString('Spesenbeleg erfassen', view('clubunio.portal.expenses', $data)->render());
        $member = \App\ClubUnio\Models\Member::findOrFail($this->member);
        $create = $data + ['member' => $member, 'teams' => collect(), 'rounds' => collect(), 'banks' => collect()];
        $this->assertStringContainsString('name="member_id"', view('clubunio.portal.expense-create', $create)->render());
        $this->grant(['expenses.view']);
        $this->assertStringNotContainsString('name="member_id"', view('clubunio.portal.expense-create', $create)->render());
        $members = $data + ['members' => new \Illuminate\Pagination\LengthAwarePaginator([$member], 1, 25), 'editableMemberIds' => []];
        $memberInvoiceUrl = e(route('clubunio.portal.invoices', ['tenant' => $this->tenant->slug, 'member' => $member->id]));
        $this->assertStringNotContainsString($memberInvoiceUrl, view('clubunio.portal.members', $members)->render());
        $this->grant(['invoices.view', 'invoices.create']);
        $invoice = $this->invoice();
        $this->grant(['invoices.view']);
        $this->assertStringContainsString($memberInvoiceUrl, view('clubunio.portal.members', $members)->render());
        $invoices = $data + ['invoices' => new \Illuminate\Pagination\LengthAwarePaginator([$invoice], 1, 25)];
        $detailUrl = e(route('clubunio.portal.invoice', ['tenant' => $this->tenant->slug, 'invoice' => $invoice->id]));
        $this->assertStringContainsString($detailUrl, view('clubunio.portal.invoices', $invoices)->render());
        $this->assertStringContainsString('Positionen', view('clubunio.portal.invoice', $data + ['record' => $invoice])->render());
        $this->grant(['expenses.view']);
        $this->assertStringNotContainsString($detailUrl, view('clubunio.portal.invoices', $invoices)->render());
        $this->assertStringNotContainsString('Positionen', view('clubunio.portal.invoice', $data + ['record' => $invoice])->render());
        $line = $this->uploadLine();
        $notification = (object) ['id' => 1, 'object_type' => 'expense', 'object_id' => $line->expense_id,
            'title' => 'Synthetic', 'body' => 'Synthetic', 'created_at' => now(), 'read_at' => now()];
        $notifications = $data + ['unread' => 0, 'notifications' => new \Illuminate\Pagination\LengthAwarePaginator([$notification], 1, 25)];
        $this->assertStringContainsString('Spesenbeleg öffnen', view('clubunio.portal.notifications', $notifications)->render());
        $this->grant(['invoices.view']);
        $this->assertStringNotContainsString('Spesenbeleg öffnen', view('clubunio.portal.notifications', $notifications)->render());
    }

    public function test_finance_blade_pdf_viewer_does_not_load_or_link_download_without_download_grant(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $data = ['tenant' => $this->tenant, 'url' => '/documents/invoices/123/local'];
        $this->grant(['invoices.view']);
        $html = view('clubunio.invoices.viewer-content', $data)->render();
        $this->assertStringNotContainsString('data-pdf-url', $html);
        $this->assertStringNotContainsString('download=1', $html);
        $this->grant(['invoices.view', 'invoices.download']);
        $html = view('clubunio.invoices.viewer-content', $data)->render();
        $this->assertStringContainsString('data-pdf-url="'.$data['url'].'"', $html);
        $this->assertStringContainsString('download=1', $html);
        $this->grant(['invoices.view']);
        $this->assertStringNotContainsString('download=1', view('clubunio.invoices.viewer-content', $data)->render());
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'office', 'access_profile_id' => null]);
        $this->assertStringContainsString('download=1', view('clubunio.invoices.viewer-content', $data)->render());
    }
}
