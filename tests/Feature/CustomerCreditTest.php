<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\CustomerCredit;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\CreditNoteResource\Pages\ViewCreditNote;
use App\ClubUnio\Resources\CustomerCreditResource\Pages\ListCustomerCredits;
use App\ClubUnio\Resources\CustomerCreditResource\Pages\ViewCustomerCredit;
use App\ClubUnio\Resources\EmbeddedCreditNote;
use App\ClubUnio\Resources\EmbeddedCustomerCredit;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\CustomerCreditsRelationManager;
use App\ClubUnio\Resources\Relations\InvoiceCustomerCreditAllocationsRelationManager;
use App\ClubUnio\Resources\Relations\InvoicesRelationManager;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\CustomerCreditService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private CreditNote $note;

    private Invoice $invoice;

    private Invoice $other;

    private array $remotes;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'credit-pool-'.Str::random(10), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(15).'@example.invalid', 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic']);
        $released = $this->db->table('clubora.financial_adjustment_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Freigegeben']);
        $this->db->table('clubora.financial_adjustment_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'confirmed', 'name' => 'Bestätigt']);
        $this->invoice = new Invoice;
        $this->invoice->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'bexio_invoice_id' => '21'])->save();
        $this->other = new Invoice;
        $this->other->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'bexio_invoice_id' => '22'])->save();
        $this->note = new CreditNote;
        $this->note->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'invoice_id' => $this->invoice->id,
            'status_id' => $released, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'reason' => 'Synthetic tax correction',
            'amount_cents' => 1000, 'included_tax' => '0.749306', 'source_snapshot' => ['bexio_invoice_id' => '21'], 'created_by' => $this->actor->id])->save();
        $this->note->refresh();
        $base = ['connection_hash' => 'synthetic', 'contact_id' => '5', 'currency_id' => 1, 'credit_allocations' => [], 'balance' => ['cash_excess_cents' => 0]];
        $this->remotes = [$this->invoice->id => $base + ['remote_id' => '21'], $this->other->id => $base + ['remote_id' => '22']];
        $this->mock(CreditNoteService::class, fn ($m) => $m->shouldReceive('remote')->andReturnUsing(fn ($actor, $tenant, $id) => $this->remotes[$id]));
        $this->mock(BexioSandboxFixtureService::class, fn ($m) => $m->shouldReceive('connectionHash')->andReturn('synthetic'));
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function register(): CustomerCredit
    {
        return app(CustomerCreditService::class)->register($this->actor, $this->tenant, $this->note->id,
            ['revision' => $this->note->revision, 'external_reference' => 'gs-00008', 'attestation' => true]);
    }

    private function payment(Invoice $invoice, int $cents, string $voucher = '8'): void
    {
        $this->remotes[$invoice->id]['credit_allocations'] = [['voucher_id' => $voucher, 'reference' => 'GS-00008', 'amount_cents' => $cents, 'payment_ids' => [(string) $invoice->id]]];
    }

    public function test_embedded_credit_dialog_keeps_context_and_checks_parent(): void
    {
        $credit = $this->register();
        Livewire::test(CustomerCreditsRelationManager::class, ['ownerRecord' => $credit->member, 'pageClass' => ViewMember::class])
            ->mountTableAction('view', $credit)->assertSet('mountedActions.0.name', 'view')->assertNoRedirect();
        $dialog = Livewire::test(EmbeddedCustomerCredit::class, ['record' => $credit->id, 'dossierId' => $credit->member_id]);
        $dialog->assertSuccessful()->callAction('correctReference', data: ['reference' => 'GS-00009'])->assertHasNoActionErrors()->assertNoRedirect()->assertDispatched('dossier-financial-updated');
        $this->assertSame('GS-00009', $credit->fresh()->external_reference);
        Livewire::test(EmbeddedCustomerCredit::class, ['record' => $credit->id, 'dossierId' => -1])->assertForbidden();
        Livewire::test(EmbeddedCreditNote::class, ['record' => $this->note->id, 'dossierId' => $this->invoice->id])->assertSuccessful();
        Livewire::test(EmbeddedCreditNote::class, ['record' => $this->note->id, 'dossierId' => $this->other->id])->assertForbidden();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'trainer']);
        $dialog->call('$refresh')->assertForbidden();
    }

    public function test_two_invoice_allocation_preserves_original_tax_and_confirms_only_when_fully_used(): void
    {
        $credit = $this->register();
        $this->assertSame(1000, $credit->remaining_cents);
        $this->assertSame('released', $this->note->fresh()->status->code);
        $this->payment($this->invoice, 400);
        $credit = app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->invoice->id);
        $this->assertSame(600, $credit->remaining_cents);
        $this->assertSame('released', $this->note->fresh()->status->code);
        $this->payment($this->other, 600);
        $credit = app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
        $this->assertSame(0, $credit->remaining_cents);
        $this->assertSame('confirmed', $this->note->fresh()->status->code);
        $this->assertSame('0.749306', $this->note->fresh()->included_tax);
        $this->assertSame(2, $credit->allocations()->count());
    }

    public function test_different_payer_rejected_without_write(): void
    {
        $credit = $this->register();
        $this->payment($this->other, 400);
        $this->remotes[$this->other->id]['contact_id'] = '99';
        $this->expectException(HttpException::class);
        app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
    }

    public function test_changed_prior_provider_allocation_blocks_further_spending(): void
    {
        $credit = $this->register();
        $this->payment($this->invoice, 400);
        $credit = app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->invoice->id);
        $this->payment($this->invoice, 300);
        $this->payment($this->other, 600);
        try {
            app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
            $this->fail('Changed evidence');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, $credit->allocations()->count());
    }

    public function test_overallocation_rollback_and_stale_revision(): void
    {
        $credit = $this->register();
        $this->payment($this->invoice, 400);
        $credit = app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->invoice->id);
        $this->payment($this->other, 601);
        try {
            app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
            $this->fail('Overspend');
        } catch (ValidationException) {
            $this->assertSame(1, $credit->allocations()->count());
        }
        $this->payment($this->other, 600);
        $this->expectException(HttpException::class);
        app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, 1, $this->other->id);
    }

    public function test_repeated_invoice_and_wrong_voucher_are_rejected(): void
    {
        $credit = $this->register();
        $this->payment($this->invoice, 400);
        $credit = app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->invoice->id);
        try {
            app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->invoice->id);
            $this->fail('Duplicate');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->payment($this->other, 600, '9');
        $this->expectException(HttpException::class);
        app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
    }

    public function test_fresh_role_and_foreign_tenant_are_rejected(): void
    {
        $credit = $this->register();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $this->expectException(HttpException::class);
        app(CustomerCreditService::class)->correctReference($this->actor, $this->tenant, $credit->id, $credit->revision, 'GS-00009');
    }

    public function test_reference_correction_before_allocation_only_and_web_details_render(): void
    {
        $credit = $this->register();
        $credit = app(CustomerCreditService::class)->correctReference($this->actor, $this->tenant, $credit->id, $credit->revision, 'GS-00009');
        $this->assertSame('GS-00009', $credit->external_reference);
        Livewire::test(ViewCustomerCredit::class, ['record' => $credit->id])->assertSuccessful()->assertSee('GS-00009')->assertSee('Steuerkorrektur');
        Livewire::test(ViewCreditNote::class, ['record' => $this->note->id])->assertSuccessful();
    }

    public function test_missing_payer_and_duplicate_registration_cannot_create_money(): void
    {
        $this->remotes[$this->invoice->id]['contact_id'] = null;
        try {
            $this->register();
            $this->fail('Missing payer');
        } catch (HttpException) {
            $this->assertSame(0, CustomerCredit::where('tenant_id', $this->tenant->id)->count());
        }
        $this->remotes[$this->invoice->id]['contact_id'] = '5';
        $this->register();
        $this->expectException(HttpException::class);
        $this->register();
    }

    public function test_currency_connection_and_foreign_tenant_are_rejected(): void
    {
        $credit = $this->register();
        $this->payment($this->other, 400);
        foreach (['currency_id' => 2, 'connection_hash' => 'other'] as $field => $wrong) {
            $old = $this->remotes[$this->other->id][$field];
            $this->remotes[$this->other->id][$field] = $wrong;
            try {
                app(CustomerCreditService::class)->allocate($this->actor, $this->tenant, $credit->id, $credit->revision, $this->other->id);
                $this->fail('Wrong identity');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $this->remotes[$this->other->id][$field] = $old;
        }
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(10), 'name' => 'Foreign']);
        $this->expectException(HttpException::class);
        app(CustomerCreditService::class)->correctReference($this->actor, $other, $credit->id, $credit->revision, 'GS-00009');
    }

    public function test_registered_credit_cannot_bypass_legacy_confirm_or_cancel(): void
    {
        $this->register();
        $note = $this->note->fresh();
        foreach (['confirm' => ['external_reference' => 'GS-00008', 'attestation' => true], 'cancel' => ['reason' => 'Not allowed']] as $op => $input) {
            try {
                (new CreditNoteService)->change($this->actor, $this->tenant, $note->id, $note->revision, $op, $input);
                $this->fail('Bypass');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertSame('released', $note->fresh()->status->code);
    }

    public function test_list_filters_and_member_dossier_expose_credit_without_mutation(): void
    {
        $credit = $this->register();
        Livewire::test(ListCustomerCredits::class)
            ->assertSuccessful()->assertCanSeeTableRecords([$credit])->filterTable('remaining', true)->assertCanSeeTableRecords([$credit]);
        Livewire::test(CustomerCreditsRelationManager::class,
            ['ownerRecord' => $credit->member, 'pageClass' => ViewMember::class])->assertSuccessful()->assertCanSeeTableRecords([$credit]);
        Livewire::test(InvoicesRelationManager::class,
            ['ownerRecord' => $credit->member, 'pageClass' => ViewMember::class])->assertSuccessful()->assertCanSeeTableRecords([$this->invoice]);
        $this->assertSame(0, $credit->allocations()->count());
    }

    public function test_web_registration_and_allocation_actions_use_domain_guards(): void
    {
        Livewire::test(ViewCreditNote::class, ['record' => $this->note->id])->callAction('customerCredit',
            ['revision' => $this->note->revision, 'external_reference' => 'GS-00008', 'attestation' => true])->assertHasNoActionErrors();
        $credit = CustomerCredit::where('tenant_id', $this->tenant->id)->sole();
        $this->payment($this->other, 600);
        Livewire::test(ViewCustomerCredit::class, ['record' => $credit->id])->callAction('allocate',
            ['revision' => $credit->revision, 'invoice_id' => $this->other->id, 'attestation' => true])->assertHasNoActionErrors();
        $this->assertSame(400, $credit->fresh()->remaining_cents);
        Livewire::test(InvoiceCustomerCreditAllocationsRelationManager::class,
            ['ownerRecord' => $this->other, 'pageClass' => ViewInvoice::class])->assertSuccessful()
            ->assertCanSeeTableRecords($credit->allocations()->get());
    }
}
