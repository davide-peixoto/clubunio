<?php

namespace App\ClubUnio\Services;

use Illuminate\Mail\Events\MessageSending;

class TestMailGuard
{
    public const RECIPIENT = 'admin@svsissach.ch';

    public function handle(MessageSending $event): ?bool
    {
        if (\App\ClubUnio\Mobile\MobileIdentityMailGuard::recognizes($event)) {
            return app(\App\ClubUnio\Mobile\MobileIdentityMailGuard::class)->handle($event);
        }
        if (($event->data['mailer'] ?? null) === 'clubunio_marketing') {
            $message = $event->message;
            $to = $message->getTo();

            return config('clubunio-marketing.mail_enabled')
                && count($to) === 1 && $to[0]->getAddress() === config('clubunio-marketing.contact')
                && !$message->getCc() && !$message->getBcc() ? null : false;
        }
        if (! app()->environment(['local', 'testing', 'staging']) || ! config('clubunio-integrations.test_mail_enabled')) {
            return false;
        }
        $message = $event->message;
        // Laravel derives the SMTP envelope AFTER this event, so no original CC/BCC survives.
        $message->to(self::RECIPIENT)->cc()->bcc()->replyTo(self::RECIPIENT);
        foreach (['Resent-To', 'Resent-Cc', 'Resent-Bcc', 'Resent-Sender', 'Resent-From', 'Return-Path'] as $header) {
            $message->getHeaders()->remove($header);
        }
        if (! str_starts_with($message->getSubject() ?? '', '[ClubUnio TEST] ')) {
            $message->subject('[ClubUnio TEST] '.($message->getSubject() ?? ''));
        }

        return null;
    }
}
