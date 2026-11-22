<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\{QRCode, QROptions};

final class EventCheckinPoster
{
    public function qrDataUri(string $url): string
    {
        $parts = parse_url($url);
        abort_unless(($parts['scheme'] ?? null) === 'https' && isset($parts['host'])
            && strlen($url) <= 2048 && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~^/event-check-in/[a-f0-9]{64}$~D', $parts['path'] ?? ''), 409);
        $options = new QROptions(['outputInterface' => QRMarkupSVG::class, 'outputBase64' => false,
            'eccLevel' => EccLevel::M, 'addQuietzone' => true, 'drawCircularModules' => false]);
        $svg = (new QRCode($options))->render($url);
        abort_unless(is_string($svg) && str_contains($svg, '<svg'), 503);
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function data(Account $actor, Tenant $tenant, int $event): array
    {
        $card = app(EventCheckinService::class)->qr($actor->fresh(), $tenant, $event);
        abort_unless(is_string($card['Url']) && $card['Event']['Enabled'], 409, __('Configure event check-in before printing.'));
        return ['card' => $card, 'brand' => app(MemberCardService::class)->brand($tenant),
            'tenantName' => $tenant->name, 'qrImage' => $this->qrDataUri($card['Url'])];
    }
}
