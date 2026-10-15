<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioInvoiceTransferService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\BexioSandboxRecipientService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvoiceDirectSandboxTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Invoice $invoice;

    private array $contact = [];

    private array $remote = [];

    private bool $contactTimeout = false;

    private bool $invoiceTimeout = false;

    private string $profile = 'SVS Api Test';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'direct-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic admin', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $released = $this->insert('invoice_workflow_statuses', ['code' => 'released', 'name' => 'Freigegeben']);
        $member = new Member;
        $member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'PrivateName', 'email' => 'private@example.invalid', 'bexio_contact_id' => 99999, 'legacy_id' => 991234])->save();
        $this->invoice = new Invoice;
        $this->invoice->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member->id, 'workflow_status_id' => $released])->save();
        $this->invoice->refresh();
        $line = new InvoiceLine;
        $line->forceFill(['tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id, 'description' => 'Synthetic service', 'gross_amount' => '12.50', 'net_amount' => '12.50'])->save();
        $fixture = new Member;
        $fixture->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Fixture'])->save();
        $this->mock(BexioConnectionService::class, function ($m) {
            $m->shouldReceive('accessToken')->andReturn('synthetic-token');
            $m->shouldReceive('connection')->andReturn(['fingerprint' => 'synthetic', 'connected_at' => '2026-10-15']);
        });
        $this->db->table('clubora.bexio_sandbox_fixtures')->insert(['tenant_id' => $this->tenant->id, 'member_id' => $fixture->id, 'parent_id' => $fixture->id, 'supplier_id' => $fixture->id,
            'invoice_id' => $this->invoice->id, 'remote_invoice_id' => 1, 'remote_contact_id' => 2, 'connection_hash' => app(BexioSandboxFixtureService::class)->connectionHash($this->tenant)]);
        foreach (['USER_ID', 'LANGUAGE_ID', 'BANK_ACCOUNT_ID', 'CURRENCY_ID', 'PAYMENT_TYPE_ID', 'UNIT_ID', 'TAX_ID', 'MWST_TYPE', 'INVOICE_DUE_DAYS'] as $key) {
            $this->insert('tenant_settings', ['area' => 'bexio', 'key' => 'BEXIO_'.$key, 'label' => $key, 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString($key === 'MWST_TYPE' ? '2' : '1')]);
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/2.0/contact') {
                $this->contact = $request->data() + ['id' => 50];

                return Http::response($this->contactTimeout ? [] : $this->contact, $this->contactTimeout ? 504 : 201);
            }
            if ($request->method() === 'POST' && $path === '/2.0/kb_invoice') {
                $this->remote = $request->data() + ['id' => 60, 'total' => '12.50', 'total_taxes' => '0.00', 'kb_item_status_id' => 7];

                return Http::response($this->invoiceTimeout ? [] : $this->remote, $this->invoiceTimeout ? 504 : 201);
            }

            return Http::response(match ($path) {
                '/2.0/company_profile' => [['id' => 1, 'name' => $this->profile]],
                '/3.0/users/me' => ['id' => 1],
                '/2.0/contact/50' => $this->contact,
                '/2.0/contact' => $this->contact ? [$this->contact] : [],
                '/2.0/kb_invoice/60' => $this->remote,
                '/2.0/kb_invoice' => $this->remote ? [$this->remote] : [],
                default => throw new \LogicException('Unexpected synthetic request '.$path),
            });
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function transfer(): object
    {
        return app(BexioInvoiceTransferService::class)->transfer($this->actor, $this->tenant, $this->invoice->id, $this->invoice->fresh()->revision);
    }

    public function test_direct_button_transfers_other_member_with_anonymous_contact_and_preserves_original_ids(): void
    {
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])->callAction('sendBexio', data: ['revision' => $this->invoice->revision, 'sandbox_confirmed' => true])->assertHasNoActionErrors();
        $this->assertSame('60', $this->invoice->fresh()->bexio_invoice_id);
        $this->assertSame(50, $this->remote['contact_id']);
        $this->assertSame(99999, (int) $this->invoice->member->fresh()->bexio_contact_id);
        $this->assertStringNotContainsString('PrivateName', json_encode($this->contact));
        $this->assertArrayNotHasKey('mail', $this->contact);
        $this->assertSame('accepted', $this->transfer()->state);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/contact')));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/kb_invoice')));
        app(BexioSandboxFixtureService::class)->guardPdf($this->tenant, $this->invoice->fresh(), 'synthetic-token');
    }

    public function test_confirmation_is_required_before_any_remote_request(): void
    {
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])->callAction('sendBexio', data: ['revision' => $this->invoice->revision, 'sandbox_confirmed' => false])->assertHasActionErrors(['sandbox_confirmed']);
        Http::assertNothingSent();
    }

    public function test_changed_contact_is_blocked_without_invoice_post(): void
    {
        app(BexioSandboxRecipientService::class)->prepare($this->actor, $this->tenant, $this->invoice);
        $this->contact['mail'] = 'changed@example.invalid';
        $this->expectException(HttpException::class);
        try {
            $this->transfer();
        } finally {
            $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/kb_invoice')));
        }
    }

    public function test_second_invoice_reuses_confirmed_contact_without_touching_imported_member(): void
    {
        $this->transfer();
        $second = new Invoice;
        $second->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $this->invoice->member_id, 'workflow_status_id' => $this->invoice->workflow_status_id])->save();
        $line = new InvoiceLine;
        $line->forceFill(['tenant_id' => $this->tenant->id, 'invoice_id' => $second->id, 'description' => 'Second synthetic service', 'gross_amount' => '12.50', 'net_amount' => '12.50'])->save();
        $second->refresh();
        $this->assertSame('accepted', app(BexioInvoiceTransferService::class)->transfer($this->actor, $this->tenant, $second->id, $second->revision)->state);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/contact')));
        $this->assertSame(99999, (int) $this->invoice->member->fresh()->bexio_contact_id);
    }

    public function test_wrong_profile_and_stale_revision_prevent_remote_writes(): void
    {
        try {
            app(BexioInvoiceTransferService::class)->transfer($this->actor, $this->tenant, $this->invoice->id, 9999);
            $this->fail('Stale revision');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->profile = 'Not the sandbox';
        try {
            $this->transfer();
            $this->fail('Wrong profile');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_contact_timeout_is_reconciled_read_only_without_duplicate_contact(): void
    {
        $this->contactTimeout = true;
        try {
            $this->transfer();
            $this->fail('Timeout');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('accepted', $this->transfer()->state);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/contact')));
    }

    public function test_invoice_timeout_is_read_back_without_second_post(): void
    {
        $this->invoiceTimeout = true;
        $this->assertSame('uncertain', $this->transfer()->state);
        $row = $this->transfer();
        $this->assertSame('accepted', $row->state);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/kb_invoice')));
    }

    public function test_foreign_member_registry_and_nonadmin_are_rejected(): void
    {
        $registry = app(BexioSandboxRecipientService::class)->prepare($this->actor, $this->tenant, $this->invoice);
        $this->db->table('clubora.bexio_sandbox_recipients')->where('id', $registry)->update(['member_id' => $this->db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $this->tenant->id)->value('member_id')]);
        try {
            app(BexioSandboxRecipientService::class)->resolve($this->tenant, $this->invoice, $registry);
            $this->fail('Foreign member');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $this->expectException(HttpException::class);
        $this->transfer();
    }
}
