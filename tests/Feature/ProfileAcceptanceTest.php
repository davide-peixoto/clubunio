<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, AccessProfile, Invoice, Member, Tenant};
use App\ClubUnio\Resources\{InvoiceResource, MemberResource, ProfileUi, TenantAccountResource};
use App\ClubUnio\Resources\Relations\InvoicesRelationManager;
use App\ClubUnio\Services\{ExpenseService, FinanceExportService, InvoicePdfService, InvoiceWorkflowService, ModulePermissionService, PermissionCatalog};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProfileAcceptanceTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private AccessProfile $profile;
    private string $before;
    private int $member;
    private int $category;
    private array $expenseData;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $db->getDatabaseName());
        $this->db = $db;
        $this->before = $this->rightsFingerprint();
        $db->beginTransaction();
        $this->assertTrue((bool) $db->table('clubora.account_roles')->where('code', 'profile')->value('active'));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'acceptance-'.Str::random(16), 'name' => 'Synthetic acceptance']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'acceptance-'.Str::random(20), 'display_name' => 'Acceptance fixture',
            'password' => bcrypt(Str::random(40)), 'active' => true])->save();
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Geschäftsstelle nur lesen',
            'permissions' => PermissionCatalog::readOnly(), 'active' => true, 'revision' => 1])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'profile',
            'access_profile_id' => $this->profile->id, 'portal_enabled' => false]);
        $this->member = $this->insert('members', ['first_name' => 'Acceptance', 'last_name' => 'Fixture']);
        $this->category = $this->insert('invoice_categories', ['name' => 'Acceptance', 'code' => 'acceptance']);
        foreach (['draft', 'released', 'cancelled'] as $code) {
            $this->insert('invoice_workflow_statuses', ['name' => $code, 'code' => $code]);
        }
        $this->insert('invoice_line_statuses', ['name' => 'Pending', 'code' => 'P']);
        foreach (['O', 'F', 'Z', 'R', 'B'] as $code) {
            $this->insert('expense_statuses', ['name' => $code, 'code' => $code]);
        }
        $season = $this->insert('seasons', ['name' => 'Acceptance']);
        $team = $this->insert('teams', ['name' => 'Acceptance']);
        $this->expenseData = ['member_id' => $this->member,
            'team_season_id' => $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Acceptance']),
            'round_id' => $this->insert('expense_rounds', ['name' => 'Acceptance', 'code' => 'acceptance']),
            'bank_account_id' => $this->insert('member_bank_accounts', ['member_id' => $this->member,
                'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')])];
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    private function rightsFingerprint(): string
    {
        // Compare rights without logging credentials or personal data.
        return hash('sha256', json_encode([
            $this->db->table('clubora.accounts')->orderBy('id')->get(['id', 'active', 'revision']),
            $this->db->table('clubora.tenant_accounts')->orderBy('id')->get(),
            $this->db->table('clubora.access_profiles')->orderBy('id')->get(),
            $this->db->table('clubora.account_roles')->orderBy('code')->get(),
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
                if (isset($this->before)) {
                    $this->assertSame($this->before, $this->rightsFingerprint(), 'Existing rights remain unchanged');
                }
                if (isset($this->tenant)) {
                    $this->assertFalse($this->db->table('clubora.tenants')->where('id', $this->tenant->id)->exists());
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function finance(): void
    {
        $permissions = ['members.view', 'teams.view', 'seasons.view'];
        foreach (['invoices', 'expenses', 'contributions', 'credits'] as $module) {
            foreach (['view', 'create', 'update', 'copy', 'deactivate', 'restore', 'download', 'upload', 'export'] as $function) {
                if (isset(PermissionCatalog::functions($module)[$function])) {
                    $permissions[] = $module.'.'.$function;
                }
            }
        }
        $this->profile->forceFill(['name' => 'Finanzen bearbeiten', 'permissions' => $permissions])->save();
    }

    private function invoice(): Invoice
    {
        return app(InvoiceWorkflowService::class)->create($this->actor, $this->tenant,
            ['member_id' => $this->member, 'invoice_category_id' => $this->category, 'tax_mode' => 'exempt']);
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected HTTP 403');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_office_read_only_acceptance(): void
    {
        $permissions = app(ModulePermissionService::class);
        $this->assertTrue($this->actor->canAccessTenant($this->tenant));
        $this->assertFalse($this->actor->hasUnrestrictedBackofficeAccess($this->tenant));
        foreach (PermissionCatalog::keys() as $key) {
            [$module, $function] = explode('.', $key);
            $this->assertSame($function === 'view', $permissions->allows($this->actor, $this->tenant, $module, $function), $key);
        }
        $this->get('/members')->assertOk()->assertSee('Acceptance');
        $this->get('/invoices')->assertOk();
        $this->get('/tenant-accounts')->assertForbidden();
        $this->get('/access-profiles')->assertForbidden();
        $this->denied(fn () => $this->invoice());
        $this->denied(fn () => app(ExpenseService::class)->save($this->actor, $this->tenant, null, $this->expenseData));
        $this->assertFalse(MemberResource::canCreate());
        $this->assertFalse(InvoiceResource::canCreate());
        $this->assertFalse(TenantAccountResource::canViewAny());
        $relation = new InvoicesRelationManager;
        $relation->ownerRecord = Member::findOrFail($this->member);
        $this->assertTrue(ProfileUi::actionAllowed(Action::make('view')->livewire($relation)));
        foreach (['createInvoice', 'editFinance', 'releaseFinance', 'pdf', 'export'] as $action) {
            $this->assertFalse(ProfileUi::actionAllowed(Action::make($action)->livewire($relation)), $action);
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_finance_editing_acceptance(): void
    {
        $this->finance();
        $service = app(InvoiceWorkflowService::class);
        $invoice = $this->invoice();
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'header', ['notes' => 'Acceptance edited']);
        $this->assertSame('Acceptance edited', $invoice->notes);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line',
            ['description' => 'Acceptance line', 'gross_amount' => '25.00', 'discount' => '5.00']);
        $line = $invoice->activeLines()->firstOrFail();
        $this->assertSame('20.0000', $line->net_amount);
        $invoice = $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line',
            ['line_id' => $line->id, 'description' => 'Updated line', 'gross_amount' => '30.00', 'discount' => '5.00']);
        $this->assertSame('25.0000', $line->fresh()->net_amount);
        $expenses = app(ExpenseService::class);
        $expense = $expenses->save($this->actor, $this->tenant, null, $this->expenseData);
        $expense = $expenses->save($this->actor, $this->tenant, $expense->id, ['revision' => $expense->revision, 'notes' => 'Acceptance expense']);
        $this->assertSame('Acceptance expense', $expense->notes);
        $this->assertSame([], $expenses->targets($this->actor, $this->tenant, $expense));
        $this->get('/invoices/'.$invoice->id)->assertOk()->assertSee('Acceptance edited');
        $this->get('/expenses/'.$expense->id)->assertOk();
        $pdf = app(InvoicePdfService::class)->open($this->actor, $this->tenant, $invoice->id, false);
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $export = app(FinanceExportService::class)->download($this->actor, $this->tenant, 'invoices', Invoice::whereKey($invoice->id));
        $this->assertSame(200, $export->getStatusCode());
        foreach (['release', 'cancel'] as $operation) {
            $this->denied(fn () => $service->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, $operation));
        }
        foreach (['invoices.approve', 'invoices.send', 'expenses.approve', 'expenses.send', 'bexio.sync', 'members.update', 'planning.update'] as $key) {
            [$module, $function] = explode('.', $key);
            $this->assertFalse(app(ModulePermissionService::class)->allows($this->actor, $this->tenant, $module, $function));
        }
        $this->get('/access-profiles')->assertForbidden();
        $relation = new InvoicesRelationManager;
        $relation->ownerRecord = Member::findOrFail($this->member);
        $this->assertTrue(ProfileUi::actionAllowed(Action::make('editFinance')->livewire($relation)));
        $this->assertFalse(ProfileUi::actionAllowed(Action::make('releaseFinance')->livewire($relation)));
        $this->assertGreaterThan(0, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_finance_revocation_and_foreign_tenant_are_denied(): void
    {
        $this->finance();
        $invoice = $this->invoice();
        $component = Livewire::test(\App\ClubUnio\Resources\InvoiceResource\Pages\ListInvoices::class)
            ->mountAction('createInvoice');
        $this->assertFalse(MemberResource::canCreate());
        $other = Tenant::create(['slug' => 'acceptance-foreign-'.Str::random(12), 'name' => 'Foreign synthetic']);
        $this->assertFalse($this->actor->canAccessTenant($other));
        $this->denied(fn () => app(InvoiceWorkflowService::class)->create($this->actor, $other, []));
        $this->profile->forceFill(['permissions' => PermissionCatalog::readOnly()])->save();
        $component->call('callMountedAction')->assertForbidden();
        $this->assertSame(1, Invoice::where('tenant_id', $this->tenant->id)->count());
        $this->denied(fn () => app(InvoiceWorkflowService::class)->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'header', ['notes' => 'Denied']));
        $this->profile->forceFill(['active' => false])->save();
        $this->assertFalse($this->actor->canAccessTenant($this->tenant));
        $this->get('/invoices')->assertForbidden();
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }
}
