<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ExpenseMessageService
{
    public function record(Tenant $tenant, Expense $expense): void
    {
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Expense event requires transaction');
        }
        $event = match ($expense->status?->code) {
            'F' => 'expense.review','Z' => 'expense.payment_released','B' => 'expense.paid','R' => 'expense.rejected','O' => 'expense.reopened',default => null,
        };
        if (! $event) {
            return;
        }
        $access = app(MemberAccessService::class);
        $members = $access->activeMembers($tenant);
        if ($expense->status->code === 'F') {
            $members->whereIn('id', $db->table('clubora.technical_leads')->where('tenant_id', $tenant->id)->where('team_season_id', $expense->team_season_id)->where('active', true)->select('member_id'));
        } else {
            $members->whereKey($expense->member_id);
        }
        $memberIds = $members->pluck('id');
        $accounts = $db->table('clubora.tenant_accounts as ta')->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')->where('ta.tenant_id', $tenant->id)->where('a.active', true)
            ->where(function ($q) use ($db, $tenant, $memberIds, $expense) {
                $q->whereIn('ta.account_id', $db->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('kind', 'self')->where('state', 'approved')->whereIn('member_id', $memberIds)->select('account_id'))
                    ->where('ta.portal_enabled', true);
                if ($expense->status->code === 'F') {
                    $q->orWhere('ta.role', 'office');
                }
            })->get(['ta.account_id', 'a.username']);
        $emails = $members->pluck('email')->merge($expense->status->code === 'F' ? $accounts->pluck('username') : [])
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        $values = ['verein' => $tenant->name, 'mitglied' => $expense->member?->display_name ?? '', 'saison' => $expense->season?->name ?? '',
            'runde' => $expense->round?->name ?? '', 'mannschaft' => $expense->teamSeason?->team?->name ?? '',
            'betrag' => 'CHF '.number_format((float) $expense->activeLines()->sum('amount'), 2, '.', "'")];
        $templates = app(MessageTemplateService::class);
        $mail = $templates->render($tenant, $event, 'email', $values);
        $payload = $mail && $emails ? $mail + ['intended_to' => $emails] : null;
        $id = $db->table('clubora.expense_events')->insertGetId(['tenant_id' => $tenant->id, 'expense_id' => $expense->id, 'revision' => $expense->revision, 'event_key' => $event,
            'delivery_state' => $payload ? 'ready' : 'held', 'payload_encrypted' => $payload ? Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)) : null]);
        $push = $templates->render($tenant, $event, 'push', $values);
        if ($push) {
            foreach ($accounts->unique('account_id') as $account) {
                $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account->account_id, 'expense_event_id' => $id,
                    'title' => $push['title'], 'body' => $push['body'], 'object_type' => 'expense', 'object_id' => $expense->id]);
            }
        }
    }
}
