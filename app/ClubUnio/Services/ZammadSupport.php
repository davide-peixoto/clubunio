<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class ZammadSupport
{
    private function settings(): ?array
    {
        $cfg = config('clubunio-support.zammad', []);
        $url = rtrim((string) ($cfg['url'] ?? ''), '/');
        $group = filter_var($cfg['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $customer = filter_var($cfg['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!($cfg['enabled'] ?? false) || !preg_match('~^https://[a-z0-9][a-z0-9.-]*(?::[0-9]{1,5})?$~Di', $url)
            || !preg_match('/^[A-Za-z0-9_\-]{16,255}$/D', (string) ($cfg['token'] ?? '')) || !$group || !$customer) return null;
        return ['url' => $url, 'token' => $cfg['token'], 'group_id' => $group, 'customer_id' => $customer];
    }

    public function enabled(): bool
    {
        return config('clubunio-support.zammad.enabled', false) === true;
    }

    private function db() { return DB::connection('clubunio'); }
    private function exports() { return $this->db()->table('clubora.support_zammad_exports'); }

    public function summary(string $ticketId): ?object
    {
        return $this->db()->table('clubora.support_tickets as ticket')
            ->join('clubora.support_posts as post', 'ticket.id', '=', 'post.ticket_id')
            ->where('ticket.id', $ticketId)->where('ticket.audience', 'platform')->whereNotNull('ticket.source_id')
            ->where('post.internal', false)->orderBy('post.id')->first(['ticket.subject', 'post.body', 'ticket.status']);
    }

    public function detail(string $ticketId): array
    {
        $cfg = $this->settings();
        $row = $this->exports()->where('ticket_id', $ticketId)->first();
        $sameTarget = $cfg && $row && $this->sameTarget($row, $cfg);
        return [
            'ready' => (bool) $cfg,
            'status' => $row?->status,
            'reconcile' => $sameTarget && $row->status !== 'linked' && now()->subMinute()->gt($row->created_at),
            'url' => $sameTarget && $row->status === 'linked' ? $row->origin.'/#ticket/zoom/'.(int) $row->remote_id : null,
            'summary' => $cfg && !$row ? $this->summary($ticketId) : null,
        ];
    }

    public function export(string $id): bool
    {
        $cfg = $this->settings();
        abort_unless($cfg, 503);
        abort_unless(Str::isUuid($id), 422);
        $draft = $this->db()->transaction(function () use ($cfg, $id) {
            $ticket = $this->db()->table('clubora.support_tickets')->where('id', $id)->where('audience', 'platform')
                ->whereNotNull('source_id')->lockForUpdate()->first(['id', 'status']);
            abort_unless($ticket, 404);
            abort_if($ticket->status === 'closed', 409);
            $draft = $this->summary($id);
            abort_unless($draft, 404);
            abort_unless($this->exports()->insertOrIgnore(['ticket_id' => $id, 'origin' => $cfg['url'],
                'group_id' => $cfg['group_id'], 'customer_id' => $cfg['customer_id'], 'status' => 'sending']) === 1, 409);
            return $draft;
        });
        $marker = $this->marker($id);
        try {
            $response = $this->client($cfg)->post('/api/v1/tickets', [
                'title' => $marker.$draft->subject,
                'group_id' => $cfg['group_id'],
                'customer_id' => $cfg['customer_id'],
                'article' => ['subject' => $draft->subject, 'body' => nl2br(e($draft->body), false),
                    'content_type' => 'text/html', 'type' => 'note', 'internal' => true],
            ]);
            if ($response->created() && $this->validReceipt($response->json(), $cfg, $id)) {
                return $this->link($id, $response->json('id'), 'sending');
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // An uncertain write must never be sent again automatically.
        }
        $this->exports()->where('ticket_id', $id)->where('status', 'sending')->update(['status' => 'attention']);
        return false;
    }

    public function reconcile(string $id, int $remote): bool
    {
        $cfg = $this->settings();
        abort_unless($cfg, 503);
        $row = $this->exports()->where('ticket_id', $id)->first();
        abort_unless($row && $this->sameTarget($row, $cfg) && $row->status !== 'linked'
            && now()->subMinute()->gt($row->created_at), 409);
        try {
            $response = $this->client($cfg)->get('/api/v1/tickets/'.$remote);
            return $response->ok() && $this->validReceipt($response->json(), $cfg, $id)
                && $response->json('id') === $remote && $this->link($id, $remote, $row->status);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return false;
        }
    }

    private function client(array $cfg)
    {
        return Http::baseUrl($cfg['url'])->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(10)
            ->withHeaders(['Authorization' => 'Token token='.$cfg['token'], 'X-Zammad-Suppress-Notifications' => 'true']);
    }

    private function marker(string $id): string { return '[ClubUnio DEV '.$id.'] '; }

    private function sameTarget(object $row, array $cfg): bool
    {
        return $row->origin === $cfg['url'] && (int) $row->group_id === $cfg['group_id']
            && (int) $row->customer_id === $cfg['customer_id'];
    }

    private function validReceipt(mixed $receipt, array $cfg, string $id): bool
    {
        return is_array($receipt) && is_int($receipt['id'] ?? null) && $receipt['id'] > 0
            && ($receipt['group_id'] ?? null) === $cfg['group_id'] && ($receipt['customer_id'] ?? null) === $cfg['customer_id']
            && is_string($receipt['title'] ?? null) && str_starts_with($receipt['title'], $this->marker($id));
    }

    private function link(string $id, int $remote, string $previous): bool
    {
        return $this->exports()->where('ticket_id', $id)->where('status', $previous)
            ->update(['status' => 'linked', 'remote_id' => $remote]) === 1;
    }
}
