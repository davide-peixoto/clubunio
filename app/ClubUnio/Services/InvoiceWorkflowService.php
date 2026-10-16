<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceWorkflowStatus;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceWorkflowService
{
    public function create(Account $actor, Tenant $tenant, array $input): Invoice
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'create');
        $data = Validator::make($input, ['member_id' => 'required|integer|min:1', 'invoice_category_id' => 'required|integer|min:1',
            'tax_mode' => 'required|in:exempt,inclusive', 'notes' => 'nullable|string|max:4000', 'season_id' => 'sometimes|required|integer|min:1'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'create');
            Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->findOrFail($data['member_id']);
            InvoiceCategory::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($data['invoice_category_id']);
            if (isset($data['season_id'])) {
                Season::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($data['season_id']);
            }
            $invoice = new Invoice;
            $invoice->forceFill($data + ['tenant_id' => $tenant->id, 'cash_rounding' => true, 'workflow_status_id' => $this->status($tenant, 'draft')])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'invoices', 'object_id' => $invoice->id, 'after_value' => json_encode(['action' => 'create', 'data' => $data], JSON_THROW_ON_ERROR)]);

            return $invoice->refresh();
        });
    }

    public function editable(Account $actor, Tenant $tenant, Invoice $invoice, string $function = 'update'): bool
    {
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'invoices', $function) && (int) $invoice->tenant_id === (int) $tenant->id
            && $invoice->active && ! $invoice->legacy_id && ! $invoice->bexio_invoice_id
            && $invoice->workflowStatus?->code === 'draft';
    }

    public function status(Tenant $tenant, string $code): int
    {
        $id = InvoiceWorkflowStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', $code)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['revision' => 'Rechnungsstatus '.$code.' fehlt oder ist inaktiv.']);
        }

        return $id;
    }

    public function cents(string $value): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,4}))?$/', $value, $m)) {
            throw ValidationException::withMessages(['gross_amount' => 'Ungültiger Betrag.']);
        }
        $fraction = str_pad($m[2] ?? '', 4, '0');

        return (int) $m[1] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
    }

    public function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function change(Account $actor, Tenant $tenant, int $id, int $revision, string $operation, array $input = []): Invoice
    {
        $function = match ($operation) {
            'header' => 'update', 'line' => empty($input['line_id']) ? 'create' : 'update',
            'remove_line', 'archive' => 'deactivate', 'restore_line', 'restore' => 'restore',
            'release' => 'approve', 'cancel' => 'cancel', default => abort(403),
        };
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', $function);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $operation, $input, $function) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', $function);
            $invoice = Invoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ((int) $invoice->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Rechnung wurde geändert. Bitte neu laden.']);
            }
            $cancellable = $operation === 'cancel' && $invoice->workflowStatus?->code === 'released'
                && $invoice->active && ! $invoice->legacy_id && ! $invoice->bexio_invoice_id;
            $archivable = in_array($operation, ['archive', 'restore'], true)
                && ! $invoice->legacy_id && ! $invoice->bexio_invoice_id
                && $invoice->workflowStatus?->code === 'cancelled'
                && (bool) $invoice->active === ($operation === 'archive');
            abort_unless($this->editable($actor, $tenant, $invoice, $function) || $cancellable || $archivable, 403);
            $before = $invoice->only(['notes', 'revision', 'workflow_status_id', 'active', 'tax_mode', 'rounding_cents', 'invoice_category_id']);
            $detail = [];
            if ($operation === 'header') {
                $data = Validator::make($input, ['notes' => 'nullable|string|max:4000', 'tax_mode' => 'sometimes|required|in:exempt,inclusive',
                    'invoice_category_id' => 'sometimes|nullable|integer|min:1'])->validate();
                if (isset($data['invoice_category_id'])) {
                    InvoiceCategory::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($data['invoice_category_id']);
                }
                if (($data['tax_mode'] ?? $invoice->tax_mode) === 'exempt' && $invoice->tax_mode === 'inclusive') {
                    $detail['taxes_before'] = $invoice->activeLines()->get()->map->only(['id', 'tax_snapshot'])->all();
                    $invoice->activeLines()->update(['bexio_catalog_tax_id' => null, 'tax_snapshot' => null]);
                }
                $invoice->forceFill($data);
            } elseif ($operation === 'line') {
                $data = Validator::make($input, ['line_id' => 'nullable|integer|min:1', 'description' => 'required|string|max:1000',
                    'gross_amount' => 'required|numeric|min:0|max:999999999.99|decimal:0,4',
                    'discount' => 'required|numeric|min:0|max:999999999.99|decimal:0,4', 'notes' => 'nullable|string|max:4000',
                    'bexio_catalog_article_id' => 'nullable|integer|min:1', 'bexio_catalog_tax_id' => 'nullable|integer|min:1'])->validate();
                $line = ! empty($data['line_id']) ? $invoice->activeLines()->where('tenant_id', $tenant->id)->findOrFail($data['line_id']) : new InvoiceLine;
                if (array_key_exists('bexio_catalog_tax_id', $data)) {
                    if ($invoice->tax_mode !== 'inclusive' && $data['bexio_catalog_tax_id']) {
                        throw ValidationException::withMessages(['bexio_catalog_tax_id' => 'Zuerst Steuerbehandlung der Rechnung ändern.']);
                    }
                    $detail['tax_before'] = $line->tax_snapshot;
                    $line->tax_snapshot = $data['bexio_catalog_tax_id'] ? app(InvoiceTaxService::class)->snapshot($tenant,
                        $data['bexio_catalog_tax_id'], app(InvoiceTaxService::class)->invoiceDate($invoice)) : null;
                    $line->bexio_catalog_tax_id = $data['bexio_catalog_tax_id'];
                    $detail['tax_after'] = $line->tax_snapshot;
                }
                if (array_key_exists('bexio_catalog_article_id', $data)) {
                    $article = $data['bexio_catalog_article_id'] ? app(BexioCatalogService::class)->invoiceArticle($tenant, $data['bexio_catalog_article_id']) : null;
                    $detail['article_before'] = $line->bexio_catalog_article_id;
                    $line->bexio_catalog_article_id = $article?->id;
                    $line->bexio_product_id = $article?->remote_id;
                    $detail['article_after'] = $article?->id;
                }
                $gross = $this->cents((string) $data['gross_amount']);
                $discount = $this->cents((string) $data['discount']);
                if ($discount > $gross) {
                    throw ValidationException::withMessages(['discount' => 'Rabatt darf den Bruttobetrag nicht übersteigen.']);
                }
                $detail['before_line'] = $line->exists ? $line->only(['id', 'description', 'gross_amount', 'discount', 'net_amount', 'notes']) : null;
                $status = InvoiceLineStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', 'P')->first();
                if (! $status) {
                    throw ValidationException::withMessages(['revision' => 'Der aktive Positionsstatus P fehlt.']);
                }
                $line->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'description' => $data['description'],
                    'notes' => $data['notes'] ?? null, 'gross_amount' => $this->decimal($gross), 'discount' => $this->decimal($discount),
                    'net_amount' => $this->decimal($gross - $discount), 'status_id' => $status->id])->save();
                $detail['after_line'] = $line->only(['id', 'description', 'gross_amount', 'discount', 'net_amount', 'notes']);
            } elseif (in_array($operation, ['remove_line', 'restore_line'], true)) {
                $data = Validator::make($input, ['line_id' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000'])->validate();
                $line = $invoice->lines()->where('active', $operation === 'remove_line')->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($data['line_id']);
                $linked = DB::connection('clubunio')->table('clubora.team_players')->where('tenant_id', $tenant->id)->where('invoice_line_id', $line->id)->exists();
                if ($line->contribution || $linked) {
                    throw ValidationException::withMessages(['line_id' => 'Beitragspositionen mit Zuordnungen bleiben erhalten. Bitte die Rechnung bei Bedarf stornieren.']);
                }
                $detail['before_line'] = $line->only(['id', 'description', 'gross_amount', 'discount', 'net_amount', 'active']);
                $line->active = $operation === 'restore_line';
                $line->save();
                $detail['after_line'] = $line->only(['id', 'active']);
                $detail['reason'] = $data['reason'];
            } elseif (in_array($operation, ['archive', 'restore'], true)) {
                abort_unless($archivable, 403);
                $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
                $invoice->active = $operation === 'restore';
                $detail['reason'] = $data['reason'];
            } elseif ($operation === 'release') {
                $lines = $invoice->activeLines()->get();
                if ($lines->isEmpty() || $lines->contains(fn ($line) => ! trim((string) $line->description) || $line->net_amount === null)) {
                    throw ValidationException::withMessages(['revision' => 'Mindestens eine vollständige Rechnungsposition ist erforderlich.']);
                }
                foreach ($lines as $line) {
                    $gross = $this->cents((string) $line->gross_amount);
                    $discount = $this->cents((string) ($line->discount ?? '0'));
                    if ((int) $line->tenant_id !== (int) $tenant->id || $discount > $gross
                        || $this->cents((string) $line->net_amount) !== $gross - $discount) {
                        throw ValidationException::withMessages(['revision' => 'Positionsbetrag oder Zuordnung ist widersprüchlich. Bitte vor Freigabe korrigieren.']);
                    }
                }
                $invoice->workflow_status_id = $this->status($tenant, 'released');
                app(InvoiceTaxService::class)->summary($invoice, true);
            } elseif ($operation === 'cancel') {
                $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
                $invoice->workflow_status_id = $this->status($tenant, 'cancelled');
                $detail['reason'] = $data['reason'];
                // Keep lines and team links for history. A new contribution needs explicit reconciliation.
            } else {
                abort(422);
            }
            $invoice->rounding_cents = app(InvoiceTaxService::class)->summary($invoice)['rounding_cents'];
            $invoice->revision++;
            $invoice->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'invoices', 'object_id' => $id, 'before_value' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_value' => json_encode(['action' => $operation, 'invoice' => $invoice->only(array_keys($before))] + $detail, JSON_THROW_ON_ERROR)]);

            return $invoice->refresh();
        });
    }
}
