<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BexioConnectionService, BexioSandboxFixtureService, ModulePermissionService, SponsorRunBillingService, SponsorRunService, TestMailGuard};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

final class MobileSponsorBillingData
{
    public const ACTIONS = ['link-contact', 'create-contact', 'create-draft', 'issue', 'send', 'cancel', 'sync', 'confirm-sent', 'refresh-preview'];
    public const STATES = ['prepared' => 'DRAFT', 'contact_ready' => 'CONTACT_READY', 'contact_uncertain' => 'CONTACT_PENDING',
        'draft_uncertain' => 'CREATE_PENDING', 'draft' => 'BEXIO_DRAFT', 'issue_uncertain' => 'ISSUE_PENDING', 'issued' => 'ISSUED',
        'send_uncertain' => 'SEND_PENDING', 'sent' => 'SENT', 'cancel_uncertain' => 'CANCEL_PENDING', 'cancelled' => 'CANCELLED',
        'partial' => 'PARTIALLY_PAID', 'paid' => 'PAID', 'running' => 'REVIEW'];
    public function service(): SponsorRunBillingService { return app(SponsorRunBillingService::class); }
    public function enter(Account $actor, Tenant $tenant, string $function = 'view'): void
    {
        app(MobileAccess::class)->enter($actor, $tenant); $this->service()->authorize($actor, $tenant, $function);
    }
    public function function(string $action): string
    {
        return match ($action) { 'send', 'confirm-sent' => 'send', 'cancel' => 'cancel', 'sync' => 'sync', default => 'approve' };
    }
    public function sourceVersion(array $source): string
    {
        unset($source['invoice_date']);
        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
    }
    public function settings(Account $actor, Tenant $tenant): array
    {
        $this->enter($actor, $tenant); $configuration = $this->service()->configuration($tenant);
        $enabled = (bool) config('clubunio-mobile.native_sponsor_billing_enabled', false);
        $binding = app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        $valid = (bool) ($configuration['enabled'] ?? false) && $binding !== null;
        foreach (SponsorRunBillingService::FIELDS as $key => $label) {
            $value = $configuration[$key] ?? null;
            $valid = $valid && is_scalar($value) && preg_match('/^\d{1,10}$/D', (string) $value)
                && (int) $value >= (in_array($key, ['mwst_type', 'due_days'], true) ? 0 : 1)
                && (int) $value <= match ($key) { 'mwst_type' => 2, 'due_days' => 365, default => 2147483647 };
        }
        return ['Contract' => 'clubunio-sponsor-billing-v1', 'ReadOnly' => false, 'Enabled' => $enabled && (bool) ($configuration['enabled'] ?? false),
            'Ready' => $enabled && $valid, 'TestMode' => true, 'TestEmail' => TestMailGuard::RECIPIENT,
            'ProviderActionsEnabled' => $enabled, 'ConnectionReady' => $binding !== null,
            'Message' => !$enabled ? 'Persönliche Abrechnungsaktionen sind noch nicht freigegeben. Lokale Vorschauen bleiben lesbar.'
                : (!$valid ? 'Abrechnung oder unabhängige Bexio-Verbindung im Vereinsbackend prüfen. Es werden keine Zahlungen ausgelöst.'
                    : 'Sandbox-Abrechnung. Versand ausschließlich an die angezeigte Testadresse; keine Bankzahlung.')];
    }
    public function index(Request $request, Account $actor, Tenant $tenant, int $event): array
    {
        $settings = $this->settings($actor, $tenant); app(SponsorRunService::class)->event($tenant, $event);
        $d = $request->validate(['start' => 'nullable|integer|min:0|max:100000', 'search' => 'nullable|string|max:100']);
        $query = DB::connection('clubunio')->table('clubora.sponsor_run_pledges as g')
            ->join('clubora.sponsor_run_participants as p', fn ($j) => $j->on('p.id', '=', 'g.participant_id')->on('p.tenant_id', '=', 'g.tenant_id'))
            ->join('clubora.members as m', fn ($j) => $j->on('m.id', '=', 'p.member_id')->on('m.tenant_id', '=', 'p.tenant_id'))
            ->leftJoin('clubora.sponsor_run_invoices as i', fn ($j) => $j->on('i.pledge_id', '=', 'g.id')->on('i.tenant_id', '=', 'g.tenant_id'))
            ->where('g.tenant_id', $tenant->id)->where('g.event_id', $event);
        if ($search = trim($d['search'] ?? '')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('g.sponsor_name', 'ilike', $pattern)->orWhereRaw("concat_ws(' ',m.first_name,m.last_name) ILIKE ?", [$pattern]));
        }
        $rows = $query->orderBy('g.id')->offset($d['start'] ?? 0)->limit(21)->get(['g.*', 'i.id as invoice_id', 'p.final_laps', 'p.approved_at',
            'p.required', 'm.first_name as participant_first_name', 'm.last_name as participant_last_name']);
        return ['Settings' => $settings, 'Candidates' => $rows->take(20)->map(fn ($row) => $this->candidate($actor, $tenant, $row, $settings))->all(),
            'HasMore' => $rows->count() > 20, 'Notice' => 'CHF-Zusagen und versionierte Rechnungssnapshots. Es wird keine Zahlung ausgelöst.'];
    }
    public function candidate(Account $actor, Tenant $tenant, object $pledge, ?array $settings = null): array
    {
        $settings ??= $this->settings($actor, $tenant);
        if ($pledge->invoice_id) {
            $invoice = $this->service()->get($actor, $tenant, (int) $pledge->invoice_id);
            return array_replace($this->invoice($actor, $tenant, $invoice, $settings), ['SponsorName' => $pledge->sponsor_name,
                'ParticipantName' => trim($pledge->participant_first_name.' '.$pledge->participant_last_name), 'ParticipationRequired' => (bool) $pledge->required]);
        }
        $event = app(SponsorRunService::class)->event($tenant, (int) $pledge->event_id);
        $eligible = in_array($event->status_code, ['open', 'closed'], true) && $event->event_date->toDateString() <= app(SponsorRunService::class)->today()
            && $pledge->required && $pledge->final_laps !== null && $pledge->approved_at !== null
            && ($pledge->payment_mode === 'invoice' || ($pledge->collector_account_id && $pledge->collector_confirmed_at));
        $source = null;
        if ($eligible && app(ModulePermissionService::class)->allows($actor, $tenant, 'sponsor_runs', 'approve')) {
            $source = app(SponsorRunService::class)->billingSource($actor, $tenant, (int) $pledge->id);
        }
        $recipient = json_decode(Crypt::decryptString($pledge->recipient_encrypted), true, 64, JSON_THROW_ON_ERROR);
        $total = SponsorRunService::totalCents($pledge, $pledge->final_laps === null ? null : (int) $pledge->final_laps);
        return ['InvoiceID' => null, 'PledgeID' => (int) $pledge->id, 'EventID' => (int) $pledge->event_id, 'Revision' => null,
            'SourceVersion' => $source ? $this->sourceVersion($source) : null, 'State' => '', 'StateLabel' => 'Noch keine Rechnung',
            'SponsorName' => $pledge->sponsor_name, 'ParticipantName' => trim($pledge->participant_first_name.' '.$pledge->participant_last_name),
            'PaymentMode' => strtoupper($pledge->payment_mode), 'AmountType' => strtoupper($pledge->amount_type), 'TotalCents' => $total,
            'ApprovedAt' => $pledge->approved_at, 'ParticipationRequired' => (bool) $pledge->required, 'Recipient' => $this->recipient($recipient),
            'BexioInvoiceID' => null, 'BexioStatus' => null, 'PaidCents' => null, 'CreditCents' => null, 'CanPdf' => false,
            'RequiresReconciliation' => false,
            'AvailableActions' => $source && $total > 0 && $settings['Ready'] ? ['prepare'] : []];
    }
    public function invoice(Account $actor, Tenant $tenant, object $invoice, ?array $settings = null): array
    {
        $this->enter($actor, $tenant); $settings ??= $this->settings($actor, $tenant);
        $binding = app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        $bound = $binding !== null && hash_equals($invoice->connection_hash, $binding);
        $manager = BexioConnectionService::canManage($actor, $tenant);
        $configurationMatches = hash_equals($invoice->configuration_hash, hash('sha256', json_encode($this->service()->configuration($tenant), JSON_THROW_ON_ERROR)));
        $actions = [];
        if ($settings['ProviderActionsEnabled'] && $bound && $manager) {
            foreach (self::ACTIONS as $action) {
                if (!app(ModulePermissionService::class)->allows($actor, $tenant, 'sponsor_runs', $this->function($action))) continue;
                if (!$settings['Ready'] && !in_array($action, ['sync', 'refresh-preview'], true)) continue;
                if (!$configurationMatches && !in_array($action, ['sync', 'refresh-preview'], true)) continue;
                if ($action === 'refresh-preview' && !$settings['Enabled']) continue;
                $allowed = match ($action) { 'link-contact', 'create-contact' => ['prepared'], 'create-draft' => ['contact_ready'], 'issue' => ['draft'],
                    'send' => ['issued'], 'cancel' => ['issued', 'sent'], 'confirm-sent' => ['send_uncertain'], 'refresh-preview' => ['prepared', 'contact_ready'],
                    'sync' => array_keys(SponsorRunBillingService::STATES) };
                if (!in_array($invoice->state, $allowed, true)) continue;
                if ($invoice->operation_token && !($action === 'sync' && now()->greaterThan($invoice->lease_until))) continue;
                if ($action === 'cancel' && ((int) ($invoice->paid_cents ?? 0) !== 0 || (int) ($invoice->credit_cents ?? 0) !== 0
                    || ($invoice->remote_status !== null && !in_array((int) $invoice->remote_status, [8, 31], true)))) continue;
                $actions[] = $action;
            }
        }
        $source = $invoice->snapshot;
        return ['InvoiceID' => (int) $invoice->id, 'PledgeID' => (int) $invoice->pledge_id, 'EventID' => (int) $invoice->event_id,
            'Revision' => (int) $invoice->revision, 'SourceVersion' => $this->sourceVersion($source),
            'State' => self::STATES[$invoice->state], 'StateLabel' => SponsorRunBillingService::STATES[$invoice->state],
            'PaymentMode' => strtoupper($source['payment_mode']), 'AmountType' => strtoupper($source['amount_type']), 'TotalCents' => (int) $invoice->total_cents,
            'ApprovedAt' => $source['approved_at'], 'ParticipationRequired' => true, 'Recipient' => $this->recipient($source['recipient']),
            'BexioInvoiceID' => $invoice->remote_id ? (int) $invoice->remote_id : null, 'BexioStatus' => $invoice->remote_status === null ? null : (int) $invoice->remote_status,
            'PaidCents' => $invoice->paid_cents === null ? null : (int) $invoice->paid_cents, 'CreditCents' => $invoice->credit_cents === null ? null : (int) $invoice->credit_cents,
            'SentAt' => $invoice->sent_at, 'SyncedAt' => DB::connection('clubunio')->table('clubora.sponsor_run_billing_audit')->where('tenant_id', $tenant->id)
                ->where('invoice_id', $invoice->id)->where('action', 'sync')->max('created_at'), 'LastAction' => $invoice->last_operation,
            'AvailableActions' => $actions, 'CanPdf' => $settings['ProviderActionsEnabled'] && $bound && (bool) $invoice->remote_id
                && app(ModulePermissionService::class)->allows($actor, $tenant, 'sponsor_runs', 'download'),
            'RequiresReconciliation' => (bool) $invoice->operation_token || str_ends_with($invoice->state, '_uncertain')];
    }
    private function recipient(array $values): array
    {
        $result = [];
        foreach (['Company' => 'company', 'FirstName' => 'first_name', 'LastName' => 'last_name', 'Street' => 'street', 'HouseNumber' => 'house_number',
            'PostalCode' => 'postal_code', 'City' => 'city', 'Country' => 'country', 'Email' => 'email'] as $key => $field) $result[$key] = $values[$field] ?? '';
        return $result;
    }
}
