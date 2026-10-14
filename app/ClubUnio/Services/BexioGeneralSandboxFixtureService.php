<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BexioGeneralSandboxFixtureService
{
    public const PARENT_NOTES = 'SANDBOX Elternrechnung v1 - keine echte Forderung oder Zahlung';

    public function prepareParent(Account $actor, Tenant $tenant): int
    {
        app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
        $db = DB::connection('clubunio');
        $binding = $db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($binding && hash_equals($binding->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
        $existing = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('member_id', $binding->member_id)->where('notes', self::PARENT_NOTES)->first();
        if ($existing) {
            return $existing->id;
        }
        $this->prepare($actor, $tenant);

        return $db->transaction(function () use ($actor, $tenant, $binding, $db) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
            $existing = Invoice::where('tenant_id', $tenant->id)->where('member_id', $binding->member_id)->where('notes', self::PARENT_NOTES)->first();
            if ($existing) {
                return $existing->id;
            }
            $parent = Member::where('tenant_id', $tenant->id)->whereNull('legacy_id')->whereNull('archived_at')->findOrFail($binding->parent_id);
            $member = Member::where('tenant_id', $tenant->id)->whereNull('legacy_id')->whereNull('archived_at')->findOrFail($binding->member_id);
            abort_unless(app(BexioSandboxDispatchService::class)->invoiceContactLabel($tenant, (int) $parent->bexio_contact_id) === 'Parent', 409);
            $recipient = $db->table('clubora.billing_recipients')->where('tenant_id', $tenant->id)->where('active', true)->where('email_field', 'mother_email')->value('id');
            abort_unless($recipient, 409, 'Aktiver Elternempfaenger-Lookup fehlt.');
            $member->forceFill(['mother_name' => 'Clubora Sandbox Parent v1', 'mother_email' => 'parent@example.invalid', 'billing_recipient_id' => $recipient])->save();
            $member->refresh();
            $billing = app(BexioBillingRecipientService::class);
            $billing->save($actor, $tenant, $member->id, ['version' => $billing->version($member), 'contact_id' => $parent->bexio_contact_id, 'confirmed' => true]);
            $workflow = app(InvoiceWorkflowService::class);
            $invoice = new Invoice;
            $invoice->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'notes' => self::PARENT_NOTES, 'workflow_status_id' => $workflow->status($tenant, 'draft')])->save();
            $invoice->refresh();
            foreach ([['Testbeitrag', '30.00', '5.00'], ['Testmaterial', '10.00', '2.00']] as [$description, $gross, $discount]) {
                $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line', ['description' => $description, 'gross_amount' => $gross, 'discount' => $discount]);
            }
            // Use the same validation and audit trail as the invoice dossier, not a status shortcut.
            $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');

            return $invoice->id;
        });
    }

    public function prepare(Account $actor, Tenant $tenant): array
    {
        $service = app(BexioSandboxDispatchService::class);
        $service->authorize($actor, $tenant);
        $db = DB::connection('clubunio');
        $binding = $db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($binding && hash_equals($binding->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
        $bases = $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->whereNull('source_trial_id')->where('state', 'accepted')->get()->keyBy('kind');
        abort_unless(isset($bases['invoice'], $bases['expense']), 409, 'Basis-Sandbox-Test zuerst abschliessen.');
        $invoice = json_decode($bases['invoice']->payload, true, 512, JSON_THROW_ON_ERROR);
        $bill = json_decode($bases['expense']->payload, true, 512, JSON_THROW_ON_ERROR);

        return $db->transaction(function () use ($actor, $tenant, $binding, $invoice, $bill, $db) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
            $parameters = ['MWST_TYPE' => 2, 'INVOICE_DUE_DAYS' => 30, 'BILL_DUE_DAYS' => 30,
                'UNIT_ID' => $invoice['positions'][0]['unit_id'], 'TAX_ID' => $invoice['positions'][0]['tax_id'],
                'BILL_CONTACT_PARTNER_ID' => $bill['contact_partner_id']];
            foreach (['user_id', 'language_id', 'bank_account_id', 'currency_id', 'payment_type_id'] as $field) {
                $parameters[strtoupper($field)] = $invoice[$field];
            }
            foreach ($parameters as $key => $value) {
                $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', 'BEXIO_'.$key)->firstOrFail();
                if (Crypt::decryptString($setting->encrypted_value) !== (string) $value) {
                    app(SettingsService::class)->update($actor, $tenant, $setting->id, (string) $value);
                }
            }
            $country = $db->table('clubora.countries')->where('tenant_id', $tenant->id)->where('active', true)->where('code', 'CH')->value('id');
            $recipient = $db->table('clubora.billing_recipients')->where('tenant_id', $tenant->id)->where('active', true)->where('email_field', 'email')->value('id');
            abort_unless($country && $recipient, 409, 'Aktive CH-/Mitglied-Empfaenger-Lookups erforderlich.');
            foreach ([$binding->member_id, $binding->supplier_id] as $id) {
                $member = Member::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($id);
                $member->forceFill(['country_id' => $country, 'billing_recipient_id' => $recipient])->save();
            }
            $type = $db->table('clubora.expense_types')->where('tenant_id', $tenant->id)->where('code', 'sandbox_web')->first();
            $typeId = $type?->id ?? $db->table('clubora.expense_types')->insertGetId(['tenant_id' => $tenant->id, 'code' => 'sandbox_web', 'name' => 'Sandbox Testspesen', 'receipt_required' => false, 'bexio_account_id' => $bill['line_items'][0]['booking_account_id']]);
            $result = [];
            foreach (['invoice', 'expense'] as $kind) {
                $class = $kind === 'invoice' ? Invoice::class : Expense::class;
                $notes = 'SANDBOX allgemeiner Webversand v1 - keine echte Forderung oder Zahlung';
                $doc = $class::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $notes)->first();
                if (! $doc) {
                    $doc = new $class;
                    $doc->forceFill(['tenant_id' => $tenant->id, 'member_id' => $kind === 'invoice' ? $binding->member_id : $binding->supplier_id, 'notes' => $notes]);
                    if ($kind === 'invoice') {
                        $doc->workflow_status_id = app(InvoiceWorkflowService::class)->status($tenant, 'released');
                    } else {
                        $doc->status_id = $db->table('clubora.expense_statuses')->where('tenant_id', $tenant->id)->where('active', true)->where('code', 'Z')->value('id');
                        abort_unless($doc->status_id, 409);
                        $doc->payment_released_on = now()->toDateString();
                    }
                    $doc->save();
                    foreach ([12, 8] as $amount) {
                        $line = $kind === 'invoice' ? new InvoiceLine : new ExpenseLine;
                        $line->forceFill(['tenant_id' => $tenant->id, $kind.'_id' => $doc->id]);
                        $line->forceFill($kind === 'invoice'
                            ? ['description' => 'Synthetische Testposition', 'gross_amount' => $amount + 1, 'discount' => 1, 'net_amount' => $amount, 'contribution' => false]
                            : ['expense_type_id' => $typeId, 'amount' => $amount, 'notes' => 'Synthetische Testposition ohne Pflichtbeleg']);
                        $line->save();
                    }
                    $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $kind === 'invoice' ? 'invoices' : 'expenses', 'object_id' => $doc->id, 'before_value' => '{}', 'after_value' => json_encode(['action' => 'sandbox_general_fixture'])]);
                }
                $result[$kind] = $doc->id;
            }

            return $result;
        });
    }
}
