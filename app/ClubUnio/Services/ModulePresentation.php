<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Member, Invoice, Expense};
use Illuminate\Support\Collection;

class ModulePresentation
{
    public static function money(int $cents): string
    {
        return 'CHF '.number_format($cents / 100, 2, '.', "'");
    }

    public static function financePage(Collection $records, bool $invoice): array
    {
        $amount = $records->sum(fn ($row) => (int) round((float) ($invoice ? $row->active_lines_sum_net_amount : $row->active_lines_sum_amount) * 100) + ($invoice ? (int) $row->rounding_cents : 0));

        return [
            ['label' => trans('module_ui.documents'), 'value' => $records->count()],
            ['label' => trans('module_ui.amount'), 'value' => self::money($amount)],
            ['label' => trans('module_ui.states'), 'value' => $records->pluck('status_id')->filter()->unique()->count()],
        ];
    }

    public static function recordFacts(Member|Invoice|Expense $record): array
    {
        $tenant = (int) $record->tenant_id;
        $lookup = function (string $table, $row) use ($tenant): string {
            return $row && (int) $row->tenant_id === $tenant ? app(LookupTranslations::class)->label($table, $row, tenant: $tenant) : '–';
        };
        if ($record instanceof Member) {
            return [
                ['label' => trans('module_ui.member_number'), 'value' => $record->member_number ?? $record->legacy_id ?? $record->id],
                ['label' => trans('module_ui.status'), 'value' => $lookup('member_statuses', $record->status)],
                ['label' => trans('module_ui.type'), 'value' => $lookup('member_types', $record->type)],
            ];
        }
        $invoice = $record instanceof Invoice;
        $cents = (int) round((float) ($invoice ? $record->active_lines_sum_net_amount : $record->active_lines_sum_amount) * 100) + ($invoice ? (int) $record->rounding_cents : 0);
        $facts = [
            ['label' => trans('module_ui.total'), 'value' => self::money($cents)],
            ['label' => trans('module_ui.status'), 'value' => $lookup($invoice ? 'invoice_statuses' : 'expense_statuses', $record->status)],
        ];
        if ($invoice) {
            $facts[] = ['label' => trans('module_ui.workflow'), 'value' => $lookup('invoice_workflow_statuses', $record->workflowStatus)];
            $balance = $record->bexioBalance;
            $facts[] = ['label' => trans('module_ui.balance'), 'value' => $balance && (int) $balance->tenant_id === $tenant ? self::money((int) $balance->remaining_cents) : trans('module_ui.no_balance')];
        } else {
            foreach (['released_on' => 'released', 'payment_released_on' => 'payment_released', 'paid_on' => 'paid'] as $field => $label) {
                if ($record->$field) { $facts[] = ['label' => trans('module_ui.'.$label), 'value' => $record->$field->format('d.m.Y')]; }
            }
        }

        return $facts;
    }
}
