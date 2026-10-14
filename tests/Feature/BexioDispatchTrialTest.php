<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioDispatchTrials;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\BexioBillingRecipientService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioPayloadBuilder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioDispatchTrialTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $invoice;

    private int $expense;

    private BexioDispatchTrialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB fixtures');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $suffix = bin2hex(random_bytes(8));
        $this->tenant = Tenant::create(['slug' => 'trial-'.$suffix, 'name' => 'Trial']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'trial-'.$suffix, 'display_name' => 'Trial', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        foreach (['USER_ID' => 1, 'LANGUAGE_ID' => 1, 'BANK_ACCOUNT_ID' => 1, 'CURRENCY_ID' => 1, 'PAYMENT_TYPE_ID' => 1, 'UNIT_ID' => 1, 'TAX_ID' => 3, 'MWST_TYPE' => 2, 'INVOICE_DUE_DAYS' => 30, 'BILL_CONTACT_PARTNER_ID' => 1, 'BILL_DUE_DAYS' => 30] as $key => $value) {
            $this->insert('tenant_settings', ['area' => 'bexio', 'key' => 'BEXIO_'.$key, 'label' => $key, 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $value)]);
        }
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Schweiz']);
        $recipient = $this->insert('billing_recipients', ['code' => 'self', 'name' => 'Mitglied', 'email_field' => 'email']);
        $member = $this->insert('members', ['first_name' => 'Test', 'last_name' => 'Only', 'bexio_contact_id' => 123, 'billing_recipient_id' => $recipient, 'country_id' => $country, 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Sissach']);
        $status = $this->insert('invoice_workflow_statuses', ['code' => 'released', 'name' => 'Freigegeben']);
        $this->invoice = $this->insert('invoices', ['member_id' => $member, 'workflow_status_id' => $status]);
        $this->insert('invoice_lines', ['invoice_id' => $this->invoice, 'description' => 'Beitrag', 'gross_amount' => '50.00', 'discount' => '5.00', 'net_amount' => '45.00']);
        $status = $this->insert('expense_statuses', ['code' => 'Z', 'name' => 'Zur Zahlung freigegeben']);
        $type = $this->insert('expense_types', ['name' => 'Test', 'bexio_account_id' => 4000]);
        $this->expense = $this->insert('expenses', ['member_id' => $member, 'status_id' => $status, 'payment_released_on' => '2026-10-13']);
        $this->insert('expense_lines', ['expense_id' => $this->expense, 'expense_type_id' => $type, 'amount' => '12.50']);
        $this->service = app(BexioDispatchTrialService::class);
        $this->actingAs($this->actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        Http::fake();
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table($table)->insertGetId(['tenant_id' => $this->tenant->id] + $data);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_preview_and_simulation_are_idempotent_and_never_write_documents_or_send_http(): void
    {
        foreach (['invoice' => $this->invoice, 'expense' => $this->expense] as $type => $document) {
            $id = $this->service->preview($this->actor, $this->tenant, $type, $document);
            $this->assertSame($id, $this->service->preview($this->actor, $this->tenant, $type, $document));
            $trial = $this->db->table('bexio_dispatch_trials')->find($id);
            $this->assertSame($type === 'invoice' ? 4500 : 1250, $trial->total_cents);
            $this->service->simulate($this->actor, $this->tenant, $id, 'uncertain');
            $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
            $this->assertSame('uncertain', $this->db->table('bexio_dispatch_trials')->find($id)->state);
            $this->assertSame($id, $this->service->preview($this->actor, $this->tenant, $type, $document));
        }
        $this->assertNull($this->db->table('invoices')->find($this->invoice)->bexio_invoice_id);
        $this->assertNull($this->db->table('expenses')->find($this->expense)->bexio_bill_id);
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_old_ready_trial_cannot_bypass_another_uncertain_attempt(): void
    {
        $old = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        Invoice::whereKey($this->invoice)->update(['notes' => 'Changed']);
        $new = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->simulate($this->actor, $this->tenant, $new, 'uncertain');
        Invoice::whereKey($this->invoice)->update(['notes' => null]);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $old, 'simulated');
    }

    public function test_changed_line_blocks_confirmation(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->db->table('invoice_lines')->where('invoice_id', $this->invoice)->update(['description' => 'Changed']);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    public function test_expired_preview_blocks_confirmation(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->travel(11)->minutes();
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    public function test_existing_remote_reference_blocks_creation(): void
    {
        $this->db->table('expenses')->where('id', $this->expense)->update(['bexio_bill_id' => 'existing']);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_web_simulation_success_and_rejection_without_real_delivery(): void
    {
        $this->get('/clubunio/'.$this->tenant->slug.'/bexio-dispatch-trials')->assertOk()->assertSee('Echtversand gesperrt');
        $page = Livewire::test(BexioDispatchTrials::class)->assertSee('Echtversand gesperrt')->call('check', $this->invoice);
        $id = $this->db->table('bexio_dispatch_trials')->where('tenant_id', $this->tenant->id)->value('id');
        $page->call('simulate', $id)->assertSee('Annahme simuliert');
        $page->set('type', 'expense')->call('check', $this->expense);
        $id = $this->db->table('bexio_dispatch_trials')->where('tenant_id', $this->tenant->id)->orderByDesc('id')->value('id');
        $page->set('outcome', 'rejected')->call('simulate', $id)->assertSee('Ablehnung simuliert');
        Http::assertNothingSent();
    }

    public function test_role_revocation_is_immediate(): void
    {
        $page = Livewire::test(BexioDispatchTrials::class);
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $page->call('check', $this->invoice)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_uncertain_result_cannot_be_retried_after_edit(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->simulate($this->actor, $this->tenant, $id, 'uncertain');
        $this->db->table('invoice_lines')->where('invoice_id', $this->invoice)->update(['description' => 'Changed']);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_mismatched_amount_is_rejected(): void
    {
        $this->db->table('invoice_lines')->where('invoice_id', $this->invoice)->update(['net_amount' => '49.00']);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_missing_expense_mapping_is_rejected(): void
    {
        $this->db->table('expense_types')->where('tenant_id', $this->tenant->id)->update(['bexio_account_id' => null]);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_foreign_tenant_cannot_read_or_simulate(): void
    {
        $other = Tenant::create(['slug' => 'other-'.bin2hex(random_bytes(8)), 'name' => 'Other']);
        $this->expectException(HttpException::class);
        $this->service->preview($this->actor, $other, 'invoice', $this->invoice);
    }

    public function test_payloads_have_fixed_endpoints_exact_prices_and_required_bill_arrays(): void
    {
        $builder = app(BexioPayloadBuilder::class);
        $request = $builder->build($this->tenant, Invoice::findOrFail($this->invoice), [['description' => 'Beitrag <Test>', 'net' => 4500, 'article' => null]], 4500);
        $this->assertSame('/2.0/kb_invoice', $request['path']);
        $this->assertSame('45.00', $request['payload']['positions'][0]['unit_price']);
        $this->assertSame('Beitrag &lt;Test&gt;', $request['payload']['positions'][0]['text']);
        $this->assertSame(2, $request['payload']['mwst_type']);
        $this->assertArrayNotHasKey('discount_in_percent', $request['payload']['positions'][0]);
        $bill = $builder->build($this->tenant, Expense::findOrFail($this->expense), [['description' => 'Test', 'net' => 1250, 'account' => 4000]], 1250);
        $this->assertSame('/4.0/purchase/bills', $bill['path']);
        $this->assertSame('2026-11-12', $bill['payload']['due_date']);
        $this->assertSame([], $bill['payload']['attachment_ids']);
        $this->assertSame([], $bill['payload']['discounts']);
        $this->assertSame('CH', $bill['payload']['address']['country_code']);
        $this->assertSame(12.5, $bill['payload']['amount_calc']);
        Http::assertNothingSent();
    }

    public function test_configuration_change_invalidates_ready_trial(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->db->table('tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'BEXIO_BANK_ACCOUNT_ID')->update(['encrypted_value' => Crypt::encryptString('9')]);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    public function test_parent_recipient_is_not_silently_replaced_by_member(): void
    {
        $this->db->table('billing_recipients')->where('tenant_id', $this->tenant->id)->update(['email_field' => 'mother_email']);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_missing_settings_have_no_guessed_defaults(): void
    {
        $this->db->table('tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'BEXIO_UNIT_ID')->update(['encrypted_value' => Crypt::encryptString('')]);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_bill_position_limit_is_enforced(): void
    {
        $this->expectException(ValidationException::class);
        app(BexioPayloadBuilder::class)->build($this->tenant, Expense::findOrFail($this->expense), array_fill(0, 101, ['description' => 'Test', 'net' => 100, 'account' => 4000]), 10100);
    }

    public function test_invoice_position_limit_is_enforced(): void
    {
        $this->expectException(ValidationException::class);
        app(BexioPayloadBuilder::class)->build($this->tenant, Invoice::findOrFail($this->invoice), array_fill(0, 151, ['description' => 'Test', 'net' => 100, 'article' => null]), 15100);
    }

    public function test_taxable_invoice_is_not_silently_exempted(): void
    {
        $this->db->table('tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'BEXIO_MWST_TYPE')->update(['encrypted_value' => Crypt::encryptString('0')]);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_supplier_address_change_invalidates_confirmation(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $expense = Expense::findOrFail($this->expense);
        $this->db->table('members')->where('id', $expense->member_id)->update(['street' => 'Neue Strasse']);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    private function parentMember(): Member
    {
        $member = Invoice::findOrFail($this->invoice)->member;
        $this->db->table('billing_recipients')->where('tenant_id', $this->tenant->id)->update(['email_field' => 'mother_email']);
        $member->forceFill(['mother_name' => 'Test Mother', 'mother_email' => 'mother@example.invalid'])->save();

        return $member->fresh();
    }

    private function assignParent($member, int $contact = 456): void
    {
        $service = app(BexioBillingRecipientService::class);
        $service->save($this->actor, $this->tenant, $member->id, ['version' => $service->version($member), 'contact_id' => $contact, 'confirmed' => true]);
    }

    public function test_parent_mapping_changes_invoice_but_not_expense_contact(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $builder = app(BexioPayloadBuilder::class);
        $invoice = $builder->build($this->tenant, Invoice::findOrFail($this->invoice), [['description' => 'Test', 'net' => 4500, 'article' => null]], 4500);
        $expense = $builder->build($this->tenant, Expense::findOrFail($this->expense), [['description' => 'Test', 'net' => 1250, 'account' => 4000]], 1250);
        $this->assertSame(456, $invoice['payload']['contact_id']);
        $this->assertSame(123, $expense['payload']['supplier_id']);
        $this->assertSame(123, (int) $member->fresh()->bexio_contact_id);
        $audit = $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->first();
        $this->assertStringNotContainsString('mother@example', $audit->after_value);
        Http::assertNothingSent();
    }

    public function test_parent_invoice_does_not_require_child_bexio_contact(): void
    {
        $member = $this->parentMember();
        $member->forceFill(['bexio_contact_id' => null])->save();
        $this->assignParent($member->fresh());
        $this->assertGreaterThan(0, $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice));
    }

    public function test_changed_parent_identity_blocks_preview(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $member->forceFill(['mother_email' => 'changed@example.invalid'])->save();
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_mapping_change_invalidates_confirmation(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assignParent($member->fresh(), 789);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    public function test_mapping_stale_editor_cannot_overwrite(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $this->expectException(ValidationException::class);
        $this->assignParent($member, 789);
    }

    public function test_mapping_cannot_be_saved_after_role_revocation(): void
    {
        $member = $this->parentMember();
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $this->assignParent($member);
    }

    public function test_mapping_can_be_removed_and_then_blocks_parent_invoice(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $service = app(BexioBillingRecipientService::class);
        $service->save($this->actor, $this->tenant, $member->id, ['version' => $service->version($member->fresh()), 'contact_id' => null, 'confirmed' => true]);
        $this->assertNull($member->fresh()->billing_bexio_contact_id);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_mapping_web_action_saves_explicitly_confirmed_contact(): void
    {
        $member = $this->parentMember();
        Livewire::test(ViewMember::class, ['record' => $member->id])
            ->mountAction('bexioBillingRecipient')
            ->setActionData(['contact_id' => 456, 'confirmed' => true])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(456, (int) $member->fresh()->billing_bexio_contact_id);
        Http::assertNothingSent();
    }

    public function test_mapping_requires_confirmation(): void
    {
        $member = $this->parentMember();
        $service = app(BexioBillingRecipientService::class);
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->tenant, $member->id, ['version' => $service->version($member), 'contact_id' => 456, 'confirmed' => false]);
    }

    public function test_mapping_cannot_cross_tenant_boundary(): void
    {
        $other = Tenant::create(['slug' => 'mapping-other-'.bin2hex(random_bytes(8)), 'name' => 'Other']);
        $id = $this->db->table('members')->insertGetId(['tenant_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Fixture']);
        $member = Member::findOrFail($id);
        $this->expectException(ModelNotFoundException::class);
        $this->assignParent($member);
    }

    public function test_mapping_cannot_be_saved_for_archived_member(): void
    {
        $member = $this->parentMember();
        $member->forceFill(['archived_at' => now()])->save();
        $this->expectException(ModelNotFoundException::class);
        $this->assignParent($member);
    }

    public function test_changed_parent_name_blocks_existing_mapping(): void
    {
        $member = $this->parentMember();
        $this->assignParent($member);
        $member->forceFill(['mother_name' => 'Other Mother'])->save();
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
    }

    public function test_mapping_rejects_missing_parent_email(): void
    {
        $member = $this->parentMember();
        $member->forceFill(['mother_email' => null])->save();
        $this->expectException(ValidationException::class);
        $this->assignParent($member->fresh());
    }

    private function attachReceipt(string $reference = 'receipt.pdf'): void
    {
        $this->db->table('expense_lines')->where('expense_id', $this->expense)->update(['storage_reference' => $reference, 'filename' => 'Private receipt.pdf']);
    }

    private function receiptReader(array $bodies): void
    {
        $reader = \Mockery::mock(AzureDocumentReader::class)->makePartial();
        $reader->shouldReceive('read')->with('azure.blob://files/', 'receipt.pdf')->times(count($bodies))->andReturn(...$bodies);
        $this->app->instance(AzureDocumentReader::class, $reader);
    }

    public function test_attachment_is_read_again_for_confirmation_without_storing_bytes_or_paths(): void
    {
        $this->attachReceipt('receipt.pdf,receipt.pdf');
        $pdf = "%PDF-1.4\nfixture receipt\n%%EOF";
        $this->receiptReader([$pdf, $pdf, $pdf]);
        $id = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $trial = $this->db->table('bexio_dispatch_trials')->find($id);
        $this->assertSame(1, $trial->attachment_count);
        $this->assertSame(strlen($pdf), $trial->attachment_bytes);
        $this->assertStringNotContainsString('receipt.pdf', json_encode($trial));
        $this->assertStringNotContainsString('fixture receipt', json_encode($trial));
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
        $this->assertSame('simulated', $this->db->table('bexio_dispatch_trials')->find($id)->state);
        Http::assertNothingSent();
    }

    public function test_changed_blob_content_under_same_filename_blocks_confirmation(): void
    {
        $this->attachReceipt();
        $this->receiptReader(["%PDF-1.4\noriginal\n%%EOF", "%PDF-1.4\nchanged\n%%EOF"]);
        $id = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $this->expectException(ValidationException::class);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
    }

    public function test_required_receipt_cannot_be_omitted(): void
    {
        $this->db->table('expense_types')->where('tenant_id', $this->tenant->id)->update(['receipt_required' => true]);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_document_reference_changed_during_read_rejects_preview(): void
    {
        $this->attachReceipt();
        $level = $this->db->transactionLevel();
        $reader = \Mockery::mock(AzureDocumentReader::class)->makePartial();
        $reader->shouldReceive('read')->once()->andReturnUsing(function () use ($level) {
            $this->assertSame($level, $this->db->transactionLevel(), 'No additional database transaction during Azure IO');
            $this->db->table('expense_lines')->where('expense_id', $this->expense)->update(['storage_reference' => 'replacement.pdf']);

            return "%PDF-1.4\nfixture\n%%EOF";
        });
        $this->app->instance(AzureDocumentReader::class, $reader);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_attachment_failure_is_sanitized_and_leaves_no_trial(): void
    {
        $this->attachReceipt();
        $reader = \Mockery::mock(AzureDocumentReader::class)->makePartial();
        $reader->shouldReceive('read')->once()->andThrow(new \RuntimeException('https://private.invalid?sig=secret', 404));
        $this->app->instance(AzureDocumentReader::class, $reader);
        try {
            $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
            $this->fail('Missing attachment accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Referenz:', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
        $this->assertSame(0, $this->db->table('bexio_dispatch_trials')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_unsupported_attachment_content_is_rejected_despite_pdf_filename(): void
    {
        $this->attachReceipt();
        $this->receiptReader(['<html><script>test</script></html>']);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_unsafe_attachment_reference_never_reaches_storage(): void
    {
        $this->attachReceipt('../secret.pdf');
        $reader = \Mockery::mock(AzureDocumentReader::class)->makePartial();
        $reader->shouldNotReceive('read');
        $this->app->instance(AzureDocumentReader::class, $reader);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_file_count_limit_is_checked_before_reading_storage(): void
    {
        $this->attachReceipt(implode(',', array_map(fn ($n) => 'file'.$n.'.pdf', range(1, 11))));
        $reader = \Mockery::mock(AzureDocumentReader::class)->makePartial();
        $reader->shouldNotReceive('read');
        $this->app->instance(AzureDocumentReader::class, $reader);
        $this->expectException(ValidationException::class);
        $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
    }

    public function test_pipeline_persists_steps_and_simulation_reference_only(): void
    {
        $this->attachReceipt();
        $pdf = "%PDF-1.4\nfixture receipt\n%%EOF";
        $this->receiptReader([$pdf, $pdf, $pdf]);
        $id = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
        $steps = $this->db->table('bexio_dispatch_steps')->where('trial_id', $id)->orderBy('id')->get();
        $this->assertSame(['upload', 'create'], $steps->pluck('operation')->all());
        $this->assertSame(['accepted', 'accepted'], $steps->pluck('state')->all());
        $this->assertStringStartsWith('simulation:', $this->db->table('bexio_dispatch_trials')->find($id)->simulation_reference);
        $this->assertNull($this->db->table('expenses')->find($this->expense)->bexio_bill_id);
        $this->assertStringNotContainsString('fixture receipt', $steps->toJson());
    }

    public function test_uncertain_pipeline_requires_explicit_resolution_before_retry(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->simulate($this->actor, $this->tenant, $id, 'uncertain');
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
        $this->assertSame(1, $this->db->table('bexio_dispatch_steps')->where('trial_id', $id)->count());
        $this->service->resolveSimulation($this->actor, $this->tenant, $id, 'not_created');
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
        $this->assertSame('simulated', $this->db->table('bexio_dispatch_trials')->find($id)->state);
        $this->assertSame(3, $this->db->table('bexio_dispatch_steps')->where('trial_id', $id)->count());
    }

    public function test_uncertain_pipeline_can_be_resolved_without_recreating_document(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->simulate($this->actor, $this->tenant, $id, 'uncertain');
        $this->service->resolveSimulation($this->actor, $this->tenant, $id, 'found');
        $this->service->simulate($this->actor, $this->tenant, $id, 'simulated');
        $this->assertSame('simulated', $this->db->table('bexio_dispatch_trials')->find($id)->state);
        $this->assertSame(1, $this->db->table('bexio_dispatch_steps')->where('trial_id', $id)->where('operation', 'create')->count());
    }

    public function test_running_trial_cannot_be_reset_immediately(): void
    {
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->db->table('bexio_dispatch_trials')->where('id', $id)->update(['state' => 'running', 'run_started_at' => now()]);
        $this->expectException(ValidationException::class);
        $this->service->resolveSimulation($this->actor, $this->tenant, $id, 'not_created');
    }
}
