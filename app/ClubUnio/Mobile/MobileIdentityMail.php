<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ClubUnioMailTheme;
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
    private ?string $renderedHtml = null;

    private function mailLocale(): string
    {
        $locale = $this->delivery['locale'] ?? null;

        return isset(config('clubunio-languages.enabled')[$locale]) ? $locale : config('clubunio-languages.default', 'de');
    }

    private function purpose(): string
    {
        return match ($this->delivery['purpose']) {
            'register' => 'register',
            'enroll' => 'enroll',
            default => 'reset',
        };
    }

    private function copy(string $key, array $replace = []): string
    {
        return trans('mail_theme.'.$key, $replace, $this->mailLocale());
    }

    public function subjectLine(): string
    {
        return $this->delivery['tenant_name'].' Â· '.$this->copy($this->purpose().'.subject');
    }
    public function actionUrl(): string
    {
        return $this->delivery['origin'].'/api/mobile/v1/public/identity/clubs/'.$this->tenant.'/'.match($this->delivery['purpose']) {'register'=>'activate','enroll'=>'confirm-email',default=>'reset-password'}.'#'.$this->delivery['token'];
    }
    private function messageBody(): string
    {
        $body = $this->copy($this->purpose().'.body');
        $names = $this->delivery['account_names'] ?? [];
        if ($names) {
            $body .= "\n".$this->copy(count($names) === 1 ? 'username' : 'usernames', ['names' => implode(', ', $names)]);
        }

        return $body."\n\n".$this->copy('ignore');
    }

    public function textBody(): string
    {
        return $this->subjectLine()."\n\n".$this->messageBody()."\n\n".$this->actionUrl()."\n";
    }

    public function htmlBody(): string
    {
        return $this->renderedHtml ??= app(ClubUnioMailTheme::class)->render(
            Tenant::find($this->tenant),
            $this->subjectLine(),
            $this->messageBody(),
            $this->actionUrl(),
            $this->copy('open'),
            $this->mailLocale(),
        );
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
