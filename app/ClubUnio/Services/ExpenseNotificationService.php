<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;

/** Read-only workflow planning. Delivery is deliberately not connected in the migration pilot. */
class ExpenseNotificationService
{
    public function preview(Account $actor, Tenant $tenant, int $expenseId): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'expenses', 'view');
        $expense = Expense::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($expenseId)
            ->with(['member', 'status', 'season', 'round', 'teamSeason.team'])->withSum('activeLines', 'amount')->firstOrFail();
        $event = match ($expense->status?->code) {
            'F' => 'expense.review', 'Z' => 'expense.payment_released', 'B' => 'expense.paid',
            'R' => 'expense.rejected', 'O' => 'expense.reopened', default => null,
        };
        if (! $event) {
            return ['event' => null, 'recipients' => [], 'messages' => []];
        }
        $recipients = [];
        $languages = app(MessageLanguageService::class);
        if ($event === 'expense.review') {
            foreach ($tenant->accounts()->where('active', true)->wherePivot('role', 'office')->get() as $account) {
                $recipients[] = ['type' => 'account', 'id' => $account->id, 'name' => $account->display_name, 'role' => __('Geschäftsstelle'),
                    'locale' => $languages->resolve($tenant, $account->preferred_locale)];
            }
            if ($expense->team_season_id) {
                foreach (TechnicalLead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('team_season_id', $expense->team_season_id)->where('active', true)
                    ->whereHas('member', fn ($q) => $q->whereNull('archived_at'))->with('member')->get()->unique('member_id') as $lead) {
                    $recipients[] = ['type' => 'member', 'id' => $lead->member_id, 'name' => $lead->member->display_name, 'role' => __('Technische Leitung'),
                        'locale' => $languages->email($tenant, (string) $lead->member->email)];
                }
            }
        } elseif ($expense->member && ! $expense->member->archived_at) {
            $recipients[] = ['type' => 'member', 'id' => $expense->member_id, 'name' => $expense->member->display_name, 'role' => __('Beleginhaber'),
                'locale' => $languages->email($tenant, (string) $expense->member->email)];
        }
        $values = ['verein' => $tenant->name, 'mitglied' => $expense->member?->display_name ?? '',
            'saison' => $expense->season?->name ?? '', 'runde' => $expense->round ? new MessageLookupValue('expense_rounds', $expense->round->getAttributes()) : '',
            'mannschaft' => $expense->teamSeason?->team?->name ?? '',
            'betrag' => 'CHF '.number_format((float) ($expense->active_lines_sum_amount ?? 0), 2, '.', "'")];
        $variants = [];
        foreach (array_unique(array_column($recipients, 'locale') ?: [$languages->resolve($tenant)]) as $language) {
            foreach (MessageEventCatalog::get($event)['channels'] as $channel) {
                $variants[$language][$channel] = app(MessageTemplateService::class)->render($tenant, $event, $channel, $values, $language);
            }
        }
        $messages = reset($variants);

        return compact('event', 'recipients', 'messages', 'variants');
    }
}
