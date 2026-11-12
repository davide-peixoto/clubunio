<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TestOutboxDispatcher
{
    public function dispatch(string $table, int $limit = 20, ?int $tenantId = null, ?int $eventId = null): array
    {
        if (! in_array($table, ['registration_events', 'expense_events', 'pitch_planning_events'], true)) {
            throw new \InvalidArgumentException('Unknown outbox');
        }
        $result = ['accepted' => 0, 'failed' => 0];
        if (! app()->environment(['local', 'testing', 'staging']) || ! config('clubunio-integrations.test_mail_enabled')) {
            return $result;
        }
        $pitch = $table === 'pitch_planning_events';
        if ($pitch && ! config('clubunio-integrations.pitch_planning_test_mail_enabled', false)) {
            return $result;
        }
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() !== 0) {
            throw new \LogicException('Dispatch requires a committed transaction');
        }
        $table = 'clubora.'.$table;
        for ($i = 0; $i < min(50, max(0, $limit)); $i++) {
            $event = $db->transaction(function () use ($db, $table, $tenantId, $eventId, $pitch) {
                $row = $db->table($table)
                    ->when($pitch, fn ($q) => $q->whereIn('delivery_state', ['ready', 'held'])->where('action', 'requested')
                        ->whereNotNull('notifications_recorded_at')->whereNotNull('payload_encrypted'),
                        fn ($q) => $q->where('delivery_state', 'ready'))
                    ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->when($eventId !== null, fn ($q) => $q->where('id', $eventId))
                    ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($row) {
                    $db->table($table)->where('id', $row->id)->update(['delivery_state' => 'sending']);
                }

                return $row;
            });
            if (! $event) {
                break;
            }
            $status = 'failed';
            try {
                $payload = json_decode(Crypt::decryptString($event->payload_encrypted), true, 512, JSON_THROW_ON_ERROR);
                $deliveries = $payload['deliveries'] ?? [$payload];
                if (! is_array($deliveries) || ! $deliveries) {
                    throw new \UnexpectedValueException('Empty delivery snapshot');
                }
                $status = 'accepted';
                foreach ($deliveries as $delivery) {
                    $sent = Mail::mailer('clubunio_test')->send(['raw' => str_replace('\\n', "\n", $delivery['body'])], [
                        'clubunio_reply_to_tenant_id' => (int) $event->tenant_id,
                    ], function ($mail) use ($delivery) {
                        $mail->to(TestMailGuard::RECIPIENT)->from(config('clubunio-integrations.from'), 'ClubUnio Test')->subject($delivery['title']);
                    });
                    if (! $sent) {
                        $status = 'failed';
                        break;
                    }
                }
            } catch (\Throwable) {
                $status = 'failed';
                // Ambiguous SMTP outcomes require review, never automatic replay.
            }
            $db->table($table)->where('id', $event->id)->where('tenant_id', $event->tenant_id)->update([
                'delivery_state' => $pitch && $status === 'failed' ? 'attention' : $status,
                'finished_at' => now(), 'error_code' => $status === 'failed' ? 'SMTP_SEND_FAILED' : null,
            ]);
            $result[$status]++;
        }

        return $result;
    }
}
