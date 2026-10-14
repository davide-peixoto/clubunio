<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TestOutboxDispatcher
{
    public function dispatch(string $table, int $limit = 20): array
    {
        if (! in_array($table, ['registration_events', 'expense_events'], true)) {
            throw new \InvalidArgumentException('Unknown outbox');
        }
        $result = ['accepted' => 0, 'failed' => 0];
        if (! app()->environment(['local', 'testing', 'staging']) || ! config('clubunio-integrations.test_mail_enabled')) {
            return $result;
        }
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() !== 0) {
            throw new \LogicException('Dispatch requires a committed transaction');
        }
        $table = 'clubora.'.$table;
        for ($i = 0; $i < min(50, max(0, $limit)); $i++) {
            $event = $db->transaction(function () use ($db, $table) {
                $row = $db->table($table)->where('delivery_state', 'ready')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
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
                $sent = Mail::mailer('clubunio_test')->raw(str_replace('\\n', "\n", $payload['body']), function ($mail) use ($payload) {
                    $mail->to(TestMailGuard::RECIPIENT)->from(config('clubunio-integrations.from'), 'ClubUnio Test')->subject($payload['title']);
                });
                $status = $sent ? 'accepted' : 'failed';
            } catch (\Throwable) {
                // Ambiguous SMTP outcomes require review, never automatic replay.
            }
            $db->table($table)->where('id', $event->id)->where('tenant_id', $event->tenant_id)->update([
                'delivery_state' => $status, 'finished_at' => now(), 'error_code' => $status === 'failed' ? 'SMTP_SEND_FAILED' : null,
            ]);
            $result[$status]++;
        }

        return $result;
    }
}
