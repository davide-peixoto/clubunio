<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Mail\Mailable;

/** Only this final mail type may enter the separately approved identity transport. */
final class MobileIdentityMail extends Mailable
{
    public function __construct(private string $challenge, private int $tenant, private array $delivery) {}
    public function challenge(): string { return $this->challenge; }
    public function tenant(): int { return $this->tenant; }
    public function delivery(): array { return $this->delivery; }
    public static function canonical(array $data): string { ksort($data); return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    public function fingerprint(): string { return hash('sha256', self::canonical($this->delivery)); }
    public function signature(): string { return hash_hmac('sha256', $this->challenge.'|'.$this->tenant.'|'.$this->fingerprint(), (string) config('app.key')); }
    public function subjectLine(): string { return $this->delivery['tenant_name'].' · '.match($this->delivery['purpose']) {'register'=>'E-Mail-Adresse bestätigen','enroll'=>'Recovery-E-Mail bestätigen',default=>'Passwort zurücksetzen'}; }
    public function actionUrl(): string
    {
        return $this->delivery['origin'].'/api/mobile/v1/public/identity/clubs/'.$this->tenant.'/'.match($this->delivery['purpose']) {'register'=>'activate','enroll'=>'confirm-email',default=>'reset-password'}.'#'.$this->delivery['token'];
    }
    public function textBody(): string
    {
        return $this->subjectLine()."\n\n".match($this->delivery['purpose']) {
            'register'=>'Bestätige deine E-Mail-Adresse. Die Zuordnung zu Mitgliedern und weitergehende Rechte prüft anschliessend der Verein.',
            'enroll'=>'Du hast nach Anmeldung und Passwortprüfung diese Recovery-Adresse angefordert. Bestätige sie nur, wenn du das Konto selbst verwaltest. Dein Benutzername bleibt unverändert.',
            default=>'Mit diesem einmaligen Link kannst du dein Passwort ändern. Es gilt für dein gesamtes persönliches Konto, auch in weiteren Vereinen.'}
            .(!empty($this->delivery['account_names'])?"\nBenutzername".(count($this->delivery['account_names'])>1?'n':'').': '.implode(', ',$this->delivery['account_names']):'')
            ."\n\n".$this->actionUrl()."\n\nFalls du dies nicht angefordert hast, ignoriere diese Nachricht.\n";
    }
    public function htmlBody(): string
    {
        return '<!doctype html><html lang="de"><meta charset="utf-8"><h1>'.htmlspecialchars($this->subjectLine(), ENT_QUOTES, 'UTF-8').'</h1><p>'
            .nl2br(htmlspecialchars($this->textBody(), ENT_QUOTES, 'UTF-8')).'</p><p><a href="'.htmlspecialchars($this->actionUrl(), ENT_QUOTES, 'UTF-8').'">Vorgang öffnen</a></p></html>';
    }
    public function build()
    {
        return $this->from(config('clubunio-mobile.public_identity.from'), $this->delivery['tenant_name'])->to($this->delivery['email'])
            ->subject($this->subjectLine())->html($this->htmlBody())->withSymfonyMessage(function ($message) {
                $message->text($this->textBody());
                $message->getHeaders()->addTextHeader('X-ClubUnio-Identity', $this->challenge);
                $message->getHeaders()->addTextHeader('X-ClubUnio-Identity-Proof', $this->signature());
            });
    }
    public function buildViewData()
    {
        return parent::buildViewData() + ['clubunio_identity_mail' => $this];
    }
    public function __toString(): string { return 'MobileIdentityMail(redacted)'; }
}
