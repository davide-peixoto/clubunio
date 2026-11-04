<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\{Crypt, DB};

final class MobileIdentityMailGuard
{
    public static function recognizes(MessageSending $event): bool
    {
        return array_key_exists('clubunio_identity_mail', $event->data) || $event->message->getHeaders()->has('X-ClubUnio-Identity')
            || $event->message->getHeaders()->has('X-ClubUnio-Identity-Proof');
    }
    public function configured(): bool
    {
        $mailer = config('clubunio-mobile.public_identity.mailer', 'clubunio_identity');
        $configuration = config('mail.mailers.'.$mailer, []);
        return config('clubunio-mobile.public_identity.enabled', false) && config('clubunio-mobile.public_identity.mail_enabled', false)
            && is_string($mailer) && preg_match('/^[a-zA-Z0-9_]{1,50}$/D', $mailer) && $mailer !== 'clubunio_test'
            && ($configuration['transport'] ?? null) === 'smtp' && !empty($configuration['host'])
            && empty($configuration['url'])
            && (($configuration['scheme'] ?? null) === 'smtps' || (empty($configuration['scheme']) && (int) ($configuration['port'] ?? 0) === 465) || ($configuration['require_tls'] ?? null) === true)
            && (int) ($configuration['timeout'] ?? 0) >= 1 && (int) ($configuration['timeout'] ?? 0) <= 30
            && (bool) filter_var(config('clubunio-mobile.public_identity.from'), FILTER_VALIDATE_EMAIL);
    }
    /** Never redirect identity secrets to the general test recipient. Denial is final. */
    public function handle(MessageSending $event): ?bool
    {
        try {
            $mail = $event->data['clubunio_identity_mail'] ?? null;
            if (!$this->configured() || !$mail instanceof MobileIdentityMail
                || ($event->data['__laravel_mailable'] ?? null) !== MobileIdentityMail::class
                || !$mail->usesMailer(config('clubunio-mobile.public_identity.mailer', 'clubunio_identity'))) { return false; }
            $headers = $event->message->getHeaders();
            if (iterator_count($headers->all('X-ClubUnio-Identity')) !== 1 || iterator_count($headers->all('X-ClubUnio-Identity-Proof')) !== 1
                || $headers->get('X-ClubUnio-Identity')->getBodyAsString() !== $mail->challenge()
                || !hash_equals($mail->signature(), $headers->get('X-ClubUnio-Identity-Proof')->getBodyAsString())) { return false; }
            $row = DB::connection('clubunio')->table('clubora.mobile_identity_challenges')->where('id', $mail->challenge())->where('tenant_id', $mail->tenant())
                ->where('state', 'pending')->where('delivery_state', 'sending')->where('expires_at', '>', now()->toIso8601String())->first();
            if (!$row || !$row->delivery_encrypted) { return false; }
            $data = json_decode(Crypt::decryptString($row->delivery_encrypted), true, 32, JSON_THROW_ON_ERROR);
            if (!hash_equals(hash('sha256', MobileIdentityMail::canonical($data)), $mail->fingerprint()) || $data['purpose'] !== $row->purpose
                || !hash_equals($row->email_hash, app(MobileIdentityChallenges::class)->emailHash($data['email']))) { return false; }
            $policy = app(MobilePublicIdentityPolicy::class); $tenant = $policy->tenant($data['origin'], $mail->tenant());
            $policy->authorize($tenant, match($row->purpose) {'register'=>'registration','enroll'=>'email_enrollment',default=>'password_reset'});
            if (!app(MobileIdentityChallenges::class)->validSecret($row, $data['token']) || !app(MobileIdentityChallenges::class)->deliveryCurrent($row)) { return false; }
            $message = $event->message; $to = $message->getTo(); $from = $message->getFrom();
            if (count($to) !== 1 || $to[0]->getAddress() !== $data['email'] || count($from) !== 1
                || $from[0]->getAddress() !== config('clubunio-mobile.public_identity.from')
                || $message->getCc() || $message->getBcc() || $message->getReplyTo() || $message->getAttachments()
                || $message->getSubject() !== $mail->subjectLine() || $message->getTextBody() !== $mail->textBody() || $message->getHtmlBody() !== $mail->htmlBody()) { return false; }
            foreach (['Resent-To','Resent-Cc','Resent-Bcc','Resent-From','Resent-Sender','Return-Path','Sender'] as $header) {
                if ($headers->has($header)) { return false; }
            }
            return null;
        } catch (\Throwable) { return false; }
    }
}
