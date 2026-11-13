<?php

namespace App\ClubUnio\Jobs;

use App\ClubUnio\Services\SupportGuide;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

final class TriageSupportTicket implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $ticketId,
        public readonly int $tenantId,
        public readonly string $audience,
        public readonly string $locale,
    ) {}

    public static function enqueue(string $id, int $tenant, string $audience, string $locale): void
    {
        if (!config('clubunio-support.enabled') || !config('clubunio-support.triage_enabled')
            || !in_array($audience, ['club', 'platform'], true)) return;

        $job = new self($id, $tenant, $audience, $locale);
        DB::connection('clubunio')->afterCommit(static function () use ($job): void {
            dispatch($job->onConnection(config('clubunio-support.triage_connection'))->onQueue('support-triage'));
        });
    }

    public function handle(SupportGuide $guide): void
    {
        if (!config('clubunio-support.enabled') || !config('clubunio-support.triage_enabled')
            || !in_array($this->audience, ['club', 'platform'], true)) return;

        $db = DB::connection('clubunio');
        $ticket = $db->table('clubora.support_tickets')->where('id', $this->ticketId)
            ->where('tenant_id', $this->tenantId)->where('audience', $this->audience)
            ->where('status', '!=', 'closed')->first(['subject']);
        if (!$ticket) return;

        $results = $db->table('clubora.support_triage');
        if ((clone $results)->where('ticket_id', $this->ticketId)->exists()) return;

        // Never follow source_id or read posts, notes or attachments.
        $topic = $guide->topic($ticket->subject);
        $suggestion = $topic === null ? null : $guide->answer(
            $topic === 'login' ? 'Passkey' : 'Expense',
            $this->locale,
        );
        $results->insertOrIgnore([
            'ticket_id' => $this->ticketId,
            'topic' => $topic ?? 'manual_review',
            'draft' => $suggestion['text'] ?? null,
            'provider_generated' => $suggestion['ai'] ?? false,
        ]);
    }
}
