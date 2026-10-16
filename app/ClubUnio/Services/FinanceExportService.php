<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExportService
{
    public function download(Account $actor, Tenant $tenant, string $kind, Builder $filtered): StreamedResponse
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, match ($kind) {
            'invoices' => 'invoices', 'payments' => 'expenses', default => abort(403),
        }, 'export');
        abort_unless(in_array($kind, ['invoices', 'payments'], true), 404);
        $model = $kind === 'invoices' ? Invoice::class : Expense::class;
        abort_unless($filtered->getModel() instanceof $model, 422);
        $query = clone $filtered;
        $query->withoutEagerLoads();
        $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenant->id)->where($query->getModel()->qualifyColumn('active'), true);
        if ($kind === 'payments') {
            $query->whereHas('status', fn ($q) => $q->where('code', 'Z'));
        } else {
            app(DashboardService::class)->scopeOpenInvoices($query, $tenant);
        }
        // Bound the materialized result so Livewire downloads cannot exhaust memory.
        $rows = $query->limit(5001)->get();
        if ($rows->count() > 5000) {
            throw ValidationException::withMessages(['export' => 'Bitte die Auswahl auf höchstens 5000 Einträge eingrenzen.']);
        }
        $rows->load(['member' => fn ($q) => $q->select(['id', 'legacy_id', 'first_name', 'last_name', 'company']), 'season', 'status']);
        if ($kind === 'payments') {
            $rows->load(['teamSeason.team', 'bankAccount.bank'])->loadSum('activeLines', 'amount');
        }
        // Resolve masked values before sending headers; retain only CSV fields in the response.
        $values = $rows->map(fn ($row) => array_map($this->cell(...), $kind === 'invoices'
            ? [$row->legacy_id ?? $row->id, $row->member?->display_name, $row->season?->name, $row->status?->name, number_format((float) $row->open_amount, 2, '.', ''), $row->bexio_invoice_id]
            : [$row->legacy_id ?? $row->id, $row->member?->display_name, $row->season?->name, $row->teamSeason?->team?->name, number_format((float) $row->active_lines_sum_amount, 2, '.', ''), $row->bankAccount?->bank?->name, $row->bankAccount?->masked_iban, $row->payment_released_on]));
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'finance_exports', 'object_id' => $tenant->id,
            'before_value' => null, 'after_value' => json_encode(['action' => 'export_prepared', 'kind' => $kind, 'row_count' => $rows->count()], JSON_THROW_ON_ERROR),
        ]);

        return response()->streamDownload(function () use ($values, $kind) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            $header = $kind === 'invoices'
                ? ['Rechnung', 'Mitglied', 'Saison', 'Status', 'Offen CHF', 'Bexio']
                : ['Beleg', 'Mitglied', 'Saison', 'Mannschaft', 'Betrag CHF', 'Bank', 'IBAN (maskiert)', 'Freigegeben am'];
            fputcsv($file, $header, ';', '"', '');
            foreach ($values as $row) {
                fputcsv($file, $row, ';', '"', '');
            }
            fclose($file);
        }, ($kind === 'invoices' ? 'offene-rechnungen' : 'spesen-zur-bezahlung').'-'.now('Europe/Zurich')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function cell(mixed $value): string
    {
        $value = (string) $value;

        // Spreadsheet applications must not interpret member text as formulas.
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
