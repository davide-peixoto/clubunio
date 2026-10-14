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
        abort_unless($actor->canAccessTenant($tenant), 403);
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
        if ($event === 'expense.review') {
            foreach ($tenant->accounts()->where('active', true)->wherePivot('role', 'office')->get() as $account) {
                $recipients[] = ['type' => 'account', 'id' => $account->id, 'name' => $account->display_name, 'role' => 'Geschäftsstelle'];
            }
            if ($expense->team_season_id) {
                foreach (TechnicalLead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('team_season_id', $expense->team_season_id)->where('active', true)
                    ->whereHas('member', fn ($q) => $q->whereNull('archived_at'))->with('member')->get()->unique('member_id') as $lead) {
                    $recipients[] = ['type' => 'member', 'id' => $lead->member_id, 'name' => $lead->member->display_name, 'role' => 'Technische Leitung'];
                }
            }
        } elseif ($expense->member && ! $expense->member->archived_at) {
            $recipients[] = ['type' => 'member', 'id' => $expense->member_id, 'name' => $expense->member->display_name, 'role' => 'Beleginhaber'];
        }
        $values = ['verein' => $tenant->name, 'mitglied' => $expense->member?->display_name ?? '',
            'saison' => $expense->season?->name ?? '', 'runde' => $expense->round?->name ?? '',
            'mannschaft' => $expense->teamSeason?->team?->name ?? '',
            'betrag' => 'CHF '.number_format((float) ($expense->active_lines_sum_amount ?? 0), 2, '.', "'")];
        $messages = [];
        foreach (MessageEventCatalog::get($event)['channels'] as $channel) {
            $messages[$channel] = app(MessageTemplateService::class)->render($tenant, $event, $channel, $values);
        }

        return compact('event', 'recipients', 'messages');
    }
}
