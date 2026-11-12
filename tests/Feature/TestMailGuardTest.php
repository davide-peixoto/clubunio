<?php

namespace Tests\Feature;

use App\ClubUnio\Services\TestMailGuard;
use App\ClubUnio\Services\TenantMailSettings;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TestMailGuardTest extends TestCase
{
    public function test_club_reply_to_uses_the_dispatch_tenant_without_changing_the_envelope(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true, 'mail.mailers.clubunio_test' => ['transport' => 'array']]);
        $this->mock(TenantMailSettings::class, function ($settings) {
            $settings->shouldReceive('replyTo')->once()->with(1)->andReturn('club-one@example.invalid');
            $settings->shouldReceive('replyTo')->once()->with(2)->andReturnNull();
        });
        $mailer = Mail::mailer('clubunio_test');
        foreach ([1 => 'club-one@example.invalid', 2 => TestMailGuard::RECIPIENT, 0 => TestMailGuard::RECIPIENT] as $id => $expected) {
            $sent = $mailer->send(['raw' => 'Synthetic'], ['clubunio_reply_to_tenant_id' => $id],
                fn ($mail) => $mail->from('from@example.invalid')->to('member@example.invalid')->subject('Reply test'));
            $this->assertSame(TestMailGuard::RECIPIENT, $sent->getSymfonySentMessage()->getEnvelope()->getRecipients()[0]->getAddress());
            $this->assertSame($expected, $sent->getOriginalMessage()->getReplyTo()[0]->getAddress());
        }
    }

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
