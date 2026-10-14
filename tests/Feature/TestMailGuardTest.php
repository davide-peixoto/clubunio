<?php

namespace Tests\Feature;

use App\ClubUnio\Services\TestMailGuard;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TestMailGuardTest extends TestCase
{
    public function test_global_guard_removes_all_real_recipients_from_smtp_envelope(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true, 'mail.mailers.safety_test' => ['transport' => 'array']]);
        $sent = Mail::mailer('safety_test')->raw('Synthetic content', function ($message) {
            $message->from('sender@example.invalid')->to('member@example.invalid')->cc('staff@example.invalid')
                ->bcc('secret@example.invalid')->replyTo('reply@example.invalid')->subject('Test');
            $message->getSymfonyMessage()->getHeaders()->addMailboxListHeader('Resent-To', ['resent@example.invalid']);
        });
        $this->assertNotNull($sent);
        $envelope = $sent->getSymfonySentMessage()->getEnvelope();
        $this->assertSame([TestMailGuard::RECIPIENT], array_map(fn ($address) => $address->getAddress(), $envelope->getRecipients()));
        $email = $sent->getOriginalMessage();
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
        $this->assertFalse($email->getHeaders()->has('Resent-To'));
        $this->assertSame('[ClubUnio TEST] Test', $email->getSubject());
    }

    public function test_disabled_switch_blocks_mail_without_transport_delivery(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => false, 'mail.mailers.safety_test' => ['transport' => 'array']]);
        $mailer = Mail::mailer('safety_test');
        $this->assertNull($mailer->raw('Synthetic', fn ($mail) => $mail->from('from@example.invalid')->to('member@example.invalid')->subject('Blocked')));
        $this->assertCount(0, $mailer->getSymfonyTransport()->messages());
    }
}
