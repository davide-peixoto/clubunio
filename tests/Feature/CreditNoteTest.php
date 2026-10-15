<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\CreditNoteResource\Pages\ViewCreditNote;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Resources\RefundResource\Pages\ViewRefund;
use App\ClubUnio\Services\BexioBalanceService;
use App\ClubUnio\Services\BexioInvoiceCashService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Invoice $invoice;

    private InvoiceLine $line;

    private array $remote;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in isolated transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'credit-'.Str::random(10), 'name' => 'Synthetic Club']);
        foreach (['draft' => 'Entwurf', 'released' => 'Freigegeben', 'confirmed' => 'Bestätigt', 'cancelled' => 'Storniert'] as $code => $name) {
            $this->db->table('clubora.financial_adjustment_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $name]);
        }
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(15).'@example.invalid', 'display_name' => 'Synthetic office', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic']);
        $status = $this->db->table('clubora.invoice_workflow_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Freigegeben']);
        $this->invoice = new Invoice;
        $this->invoice->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'workflow_status_id' => $status, 'bexio_invoice_id' => '123'])->save();
        $this->invoice->refresh();
        $this->line = new InvoiceLine;
        $this->line->forceFill(['tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id, 'description' => 'Synthetic kit', 'net_amount' => '20.00', 'gross_amount' => '20.00'])->save();
        $this->remote = ['id' => 123, 'currency_id' => 1, 'mwst_type' => 2, 'total_taxes' => '0.00', 'total' => '20.00', 'total_received_payments' => '20.00', 'total_credit_vouchers' => '10.00', 'total_remaining_payments' => '-10.00', 'kb_item_status_id' => 9];
        $this->mock(BexioSandboxFixtureService::class, function ($m) {
            $m->shouldReceive('connectionHash')->andReturn('synthetic');
        });
        $this->mock(BexioInvoiceCashService::class, function ($m) {
            $m->shouldReceive('read')->andReturnUsing(fn ($tenant, $id, $balance) => [
                'cash_received_cents' => $balance['paid_cents'], 'cash_excess_cents' => $balance['overpaid_cents']]);
            $m->shouldReceive('creditEvidence')->andReturnUsing(fn ($tenant, $id, $balance) => [
                'cash' => ['cash_received_cents' => $balance['paid_cents'], 'cash_excess_cents' => $balance['overpaid_cents']],
                'credit_allocations' => $balance['credit_cents'] ? [['voucher_id' => '4', 'reference' => 'GS-00004', 'amount_cents' => $balance['credit_cents'], 'payment_ids' => ['20']]] : [],
            ]);
        });
        $this->mock(BexioSandboxDispatchService::class, function ($m) {
            $m->shouldReceive('statusDocument')->andReturn((object) ['remote_id' => '123', 'connection_hash' => 'synthetic', 'payload' => json_encode(['currency_id' => 1, 'mwst_type' => 2])]);
            $m->shouldReceive('readStatus')->andReturnUsing(fn () => $this->remote);
        });
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function input(string $amount = '10.00'): array
    {
        return ['revision' => $this->invoice->revision, 'request_id' => (string) Str::uuid(), 'reason' => 'Synthetic correction',
            'lines' => [['invoice_line_id' => $this->line->id, 'amount' => $amount]]];
    }

    private function create(string $amount = '10.00'): CreditNote
    {
        return app(CreditNoteService::class)->create($this->actor, $this->tenant, $this->invoice->id, $this->input($amount));
    }

    public function test_web_credit_release_confirm_and_refund_are_local_and_audited(): void
    {
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])->callAction('createCredit', data: $this->input())->assertHasNoActionErrors();
        $note = CreditNote::where('tenant_id', $this->tenant->id)->firstOrFail();
        Livewire::test(ViewCreditNote::class, ['record' => $note->id])->callAction('release', data: ['revision' => $note->revision])->assertHasNoActionErrors();
        $note->refresh();
        Livewire::test(ViewCreditNote::class, ['record' => $note->id])->callAction('confirm', data: ['revision' => $note->revision, 'external_reference' => 'GS-00004', 'attestation' => true])->assertHasNoActionErrors();
        $this->assertSame('confirmed', $note->fresh()->status->code);
        Livewire::test(ViewCreditNote::class, ['record' => $note->id])->callAction('createRefund', data: ['request_id' => (string) Str::uuid(), 'amount' => '10.00', 'reason' => 'Synthetic refund'])->assertHasNoActionErrors();
        $refund = $note->refunds()->firstOrFail();
        Livewire::test(ViewRefund::class, ['record' => $refund->id])->callAction('confirm', data: ['revision' => $refund->revision, 'proof_reference' => 'TEST-REFUND-1',
            'paid_on' => now('Europe/Zurich')->toDateString(), 'attestation' => true])->assertHasNoActionErrors();
        $this->assertSame('confirmed', $refund->fresh()->status->code);
        $this->assertSame('20.0000', $this->line->fresh()->net_amount);
        $this->assertSame(5, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        $this->get('/credit-notes')->assertOk()->assertSee('Gutschriften');
        $this->get('/refunds')->assertOk()->assertSee('Rückerstattungen');
        $this->db->table('clubora.bexio_balances')->insert(['tenant_id' => $this->tenant->id, 'object_type' => 'invoice', 'object_id' => $this->invoice->id,
            'remote_id' => '123', 'remote_status' => '9', 'total_cents' => 2000, 'paid_cents' => 2000, 'credit_cents' => 1000, 'remaining_cents' => 0, 'overpaid_cents' => 1000,
            'cash_received_cents' => 2000, 'cash_excess_cents' => 1000, 'observed_at' => now()]);
        $this->assertSame(0, app(DashboardService::class)->refundableCash($this->actor, $this->tenant));
        Http::assertNothingSent();
    }

    public function test_duplicate_request_is_idempotent_and_amount_is_reserved(): void
    {
        $service = app(CreditNoteService::class);
        $input = $this->input('15.00');
        $note = $service->create($this->actor, $this->tenant, $this->invoice->id, $input);
        $this->assertSame($note->id, $service->create($this->actor, $this->tenant, $this->invoice->id, $input)->id);
        $this->expectException(ValidationException::class);
        $this->create('5.01');
    }

    public function test_cancellation_releases_capacity_and_keeps_history(): void
    {
        $note = $this->create('20.00');
        app(CreditNoteService::class)->change($this->actor, $this->tenant, $note->id, $note->revision, 'cancel', ['reason' => 'Synthetic cancellation']);
        $new = $this->create('20.00');
        $this->assertNotSame($note->id, $new->id);
        $this->assertSame('cancelled', $note->fresh()->status->code);
        $this->assertSame(1, $note->lines()->count());
    }

    public function test_missing_bexio_credit_cannot_confirm(): void
    {
        $note = $this->create();
        $service = app(CreditNoteService::class);
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $this->remote['total_credit_vouchers'] = '0.00';
        $this->remote['total_remaining_payments'] = '0.00';
        $this->expectException(ValidationException::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
    }

    public function test_refund_cannot_exceed_verified_credit_balance(): void
    {
        $this->expectException(ValidationException::class);
        app(CreditNoteService::class)->createRefund($this->actor, $this->tenant, $this->invoice->id,
            ['request_id' => (string) Str::uuid(), 'amount' => '10.01', 'reason' => 'Synthetic refund']);
    }

    public function test_unpaid_credit_does_not_permit_cash_refund(): void
    {
        $this->remote['total_received_payments'] = '0.00';
        $this->remote['total_remaining_payments'] = '10.00';
        $this->remote['kb_item_status_id'] = 16;
        $this->expectException(ValidationException::class);
        app(CreditNoteService::class)->createRefund($this->actor, $this->tenant, $this->invoice->id,
            ['request_id' => (string) Str::uuid(), 'amount' => '1.00', 'reason' => 'Synthetic refund']);
    }

    public function test_foreign_line_and_revoked_role_are_rejected(): void
    {
        $input = $this->input();
        $input['lines'][0]['invoice_line_id'] = $this->line->id + 1000000;
        try {
            app(CreditNoteService::class)->create($this->actor, $this->tenant, $this->invoice->id, $input);
            $this->fail('Foreign line accepted');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'member']);
        try {
            $this->create();
            $this->fail('Revoked office accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_stale_version_rejected(): void
    {
        $note = $this->create();
        $service = app(CreditNoteService::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $this->expectException(ValidationException::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'cancel', ['reason' => 'Synthetic']);
    }

    public function test_overpayment_is_separate_not_negative_open_balance(): void
    {
        $data = $this->remote;
        $data['total_credit_vouchers'] = '0.00';
        $data['total_received_payments'] = '25.00';
        $data['total_remaining_payments'] = '-5.00';
        $balance = app(BexioBalanceService::class)->normalize('invoice', $data, 2000);
        $this->assertSame(0, $balance['remaining_cents']);
        $this->assertSame(500, $balance['overpaid_cents']);
        $data['total_remaining_payments'] = '-4.99';
        $this->expectException(ValidationException::class);
        app(BexioBalanceService::class)->normalize('invoice', $data, 2000);
    }

    public function test_tax_snapshot_preserved_for_partial_and_final_correction(): void
    {
        $this->invoice->forceFill(['tax_mode' => 'inclusive', 'cash_rounding' => true])->save();
        $this->line->forceFill(['net_amount' => '10.03', 'tax_snapshot' => ['rate_basis_points' => 810, 'type' => 'sales_tax', 'code' => 'UN81']])->save();
        $first = $this->create('5.00');
        $last = $this->create('5.05');
        $this->assertSame('UN81', $first->lines()->first()->tax_snapshot['code']);
        $this->assertSame(1005, (int) $first->amount_cents + (int) $last->amount_cents);
        $this->assertEqualsWithDelta(0.753053, (float) $first->included_tax + (float) $last->included_tax, 0.0000001);
    }

    public function test_refund_requests_are_idempotent_and_reserved_once(): void
    {
        $service = app(CreditNoteService::class);
        $input = ['request_id' => (string) Str::uuid(), 'amount' => '10.00', 'reason' => 'Synthetic refund'];
        $refund = $service->createRefund($this->actor, $this->tenant, $this->invoice->id, $input);
        $this->assertSame($refund->id, $service->createRefund($this->actor, $this->tenant, $this->invoice->id, $input)->id);
        $this->expectException(ValidationException::class);
        $service->createRefund($this->actor, $this->tenant, $this->invoice->id, ['request_id' => (string) Str::uuid(), 'amount' => '0.01', 'reason' => 'Synthetic refund']);
    }

    public function test_inactive_status_cannot_release(): void
    {
        $note = $this->create();
        $this->db->table('clubora.financial_adjustment_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'released')->update(['active' => false]);
        $this->expectException(ValidationException::class);
        app(CreditNoteService::class)->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
    }

    public function test_office_can_create_but_cannot_use_sandbox_provider_actions(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $note = $this->create();
        $this->assertSame('draft', $note->status->code);
        try {
            app(CreditNoteService::class)->remote($this->actor, $this->tenant, $this->invoice->id);
            $this->fail('Sandbox admin protection bypassed');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_pending_credit_does_not_change_provider_rest_or_original_invoice(): void
    {
        $note = $this->create('20.00');
        $this->assertSame(2000, $note->amount_cents);
        $this->assertSame('20.0000', $this->line->fresh()->net_amount);
        $this->assertNull($this->invoice->fresh()->bexioBalance);
        $this->assertSame([], app(CreditNoteService::class)->availableLines($this->actor, $this->tenant, $this->invoice->id));
    }

    public function test_new_credit_timestamp_has_explicit_offset_despite_host_timezone(): void
    {
        $note = $this->create();
        $this->assertLessThan(15, abs($note->created_at->diffInSeconds(now('UTC'))));
    }

    public function test_credit_only_settlement_is_confirmed_but_is_not_cash_or_refundable(): void
    {
        $this->remote['total_received_payments'] = '0.00';
        $this->remote['total_credit_vouchers'] = '20.00';
        $this->remote['total_remaining_payments'] = '0.00';
        $service = app(CreditNoteService::class);
        $note = $this->create('20.00');
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'gs-00004', 'attestation' => true]);
        $this->assertSame('confirmed', $note->status->code);
        $this->assertSame('GS-00004', $note->external_reference);
        $this->assertSame('4', $note->external_snapshot['allocation']['voucher_id']);
        $this->assertSame(['20'], $note->external_snapshot['allocation']['payment_ids']);
        $this->assertSame(0, $note->external_snapshot['balance']['cash_excess_cents']);
        $this->expectException(ValidationException::class);
        $service->createRefund($this->actor, $this->tenant, $this->invoice->id, ['request_id' => (string) Str::uuid(), 'amount' => '0.01', 'reason' => 'No cash exists']);
    }

    public function test_wrong_number_cannot_be_confirmed_despite_sufficient_total_credit(): void
    {
        $service = app(CreditNoteService::class);
        $note = $this->create();
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $this->expectException(ValidationException::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'GS-00002', 'attestation' => true]);
    }

    public function test_wrong_credit_amount_cannot_be_confirmed(): void
    {
        $this->remote['total_credit_vouchers'] = '20.00';
        $this->remote['total_remaining_payments'] = '-20.00';
        $service = app(CreditNoteService::class);
        $note = $this->create();
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $this->expectException(ValidationException::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
    }

    public function test_changed_source_invoice_cannot_be_confirmed(): void
    {
        $service = app(CreditNoteService::class);
        $note = $this->create();
        $note = $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'release');
        $note->forceFill(['source_snapshot' => array_replace($note->source_snapshot, ['bexio_invoice_id' => '6'])])->save();
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
    }

    public function test_same_provider_voucher_cannot_be_reused_under_a_different_reference(): void
    {
        $service = app(CreditNoteService::class);
        $first = $this->create();
        $first = $service->change($this->actor, $this->tenant, $first->id, $first->revision, 'release');
        $first = $service->change($this->actor, $this->tenant, $first->id, $first->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
        // Simulate an old manual label; the immutable provider ID must still prevent reuse.
        $first->forceFill(['external_reference' => 'OLD-LABEL'])->save();
        $second = $this->create();
        $second = $service->change($this->actor, $this->tenant, $second->id, $second->revision, 'release');
        $this->remote['total_credit_vouchers'] = '20.00';
        $this->remote['total_remaining_payments'] = '-20.00';
        $this->mock(BexioInvoiceCashService::class)->shouldReceive('creditEvidence')->andReturn([
            'cash' => ['cash_received_cents' => 2000, 'cash_excess_cents' => 2000],
            'credit_allocations' => [['voucher_id' => '4', 'reference' => 'GS-00004', 'amount_cents' => 1000, 'payment_ids' => ['20']],
                ['voucher_id' => '5', 'reference' => 'GS-00005', 'amount_cents' => 1000, 'payment_ids' => ['21']]],
        ]);
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->tenant, $second->id, $second->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
    }

    public function test_cash_service_reads_real_credit_payment_shape_without_counting_it_as_cash(): void
    {
        $payment = ['id' => 20, 'value' => '10.050000', 'kb_invoice_id' => 9, 'kb_credit_voucher_id' => 4,
            'kb_credit_voucher_text' => 'Gutschrift GS-00004', 'is_client_account_redemption' => false, 'is_cash_discount' => false];
        $balance = ['total_cents' => 1005, 'paid_cents' => 0, 'credit_cents' => 1005];
        $service = new BexioInvoiceCashService;
        $data = $service->evidenceFromPayments([$payment], '9', $balance);
        $this->assertSame(['cash_received_cents' => 0, 'cash_excess_cents' => 0], $data['cash']);
        $this->assertSame([['voucher_id' => '4', 'reference' => 'GS-00004', 'amount_cents' => 1005, 'payment_ids' => ['20']]], $data['credit_allocations']);
        try {
            $service->evidenceFromPayments([$payment, $payment], '9', $balance);
            $this->fail('Duplicate payment');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->evidenceFromPayments([$payment], '6', $balance);
            $this->fail('Wrong invoice');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->evidenceFromPayments([$payment], '9', array_replace($balance, ['credit_cents' => 999]));
            $this->fail('Different aggregate');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
