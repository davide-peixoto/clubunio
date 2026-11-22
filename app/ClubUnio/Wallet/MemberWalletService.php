<?php

namespace App\ClubUnio\Wallet;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberCardService;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MemberWalletService
{
    public function available(): bool { return app(ApplePassBundle::class)->available(); }

    public function capabilities(?Tenant $tenant = null): array
    {
        return ['AppleAvailable' => $this->available() && ($tenant === null || app(MemberCardService::class)->logoBytes($tenant) !== null)];
    }

    public function passes() { return DB::connection('clubunio')->table('clubunio.member_wallet_passes'); }
    public function registrations() { return DB::connection('clubunio')->table('clubunio.member_wallet_registrations'); }

    private function projection(Tenant $tenant, int $member): array
    {
        $service = app(MemberCardService::class);
        $card = $service->ensureCard($tenant, $member);
        $snapshot = $service->snapshot($tenant, $member);
        $verification = $service->verification($tenant, $member);
        $url = $verification['Url'] ?? null;
        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 503);
        return [
            'CardId' => $card->id, 'Name' => $snapshot['Name'], 'TenantName' => $snapshot['TenantName'],
            'StatusText' => $snapshot['StatusText'] ?? '', 'Valid' => $snapshot['Valid'] === true && $card->revoked_at === null,
            'VerificationUrl' => $url, 'PrimaryColor' => $snapshot['Brand']['PrimaryColor'],
            'SourceVersion' => $snapshot['SourceVersion'],
            'LanguageVersion' => hash('sha256', json_encode(app(ApplePassBundle::class)->localizations(), JSON_THROW_ON_ERROR)),
            'SigningVersion' => app(ApplePassBundle::class)->signingVersion(),
        ];
    }

    private function artwork(Tenant $tenant): array
    {
        $bytes = app(MemberCardService::class)->logoBytes($tenant);
        abort_unless(is_string($bytes) && strlen($bytes) <= 5_242_880, 503);
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 16_777_216, 503);
        $source = @imagecreatefromstring($bytes);
        abort_unless($source, 503);
        try {
            $images = [];
            foreach (['icon.png' => [29, 29], 'icon@2x.png' => [58, 58], 'logo.png' => [160, 50], 'logo@2x.png' => [320, 100]] as $name => [$w, $h]) {
                $target = imagecreatetruecolor($w, $h);
                imagealphablending($target, false); imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
                $scale = min($w / $size[0], $h / $size[1]);
                $dw = max(1, (int) round($size[0] * $scale)); $dh = max(1, (int) round($size[1] * $scale));
                imagecopyresampled($target, $source, (int) (($w - $dw) / 2), (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $size[0], $size[1]);
                ob_start(); imagepng($target); $images[$name] = base64_encode(ob_get_clean()); imagedestroy($target);
            }
            return $images;
        } finally { imagedestroy($source); }
    }

    private function fingerprint(array $snapshot, array $artwork): string
    {
        return hash('sha256', json_encode([$snapshot, $artwork], JSON_THROW_ON_ERROR));
    }

    /** Called only after the mobile controller has freshly authorized the card. */
    public function issue(Tenant $tenant, int $member): object
    {
        abort_unless($this->available(), 503);
        $snapshot = $this->projection($tenant, $member);
        $artwork = $this->artwork($tenant);
        $db = DB::connection('clubunio');
        return $db->transaction(function () use ($tenant, $member, $snapshot, $artwork, $db) {
            // Serialize first issuance with the existing stable card row.
            $db->table('clubunio.member_cards')->where('id', $snapshot['CardId'])->lockForUpdate()->firstOrFail();
            $row = $this->passes()->where('card_id', $snapshot['CardId'])->lockForUpdate()->first();
            if ($row) {
                abort_unless($row->pass_type === config('clubunio-wallet.pass_type_identifier'), 503);
                return $this->saveSnapshot($row, $snapshot, $artwork);
            }
            $serial = (string) Str::uuid(); $token = bin2hex(random_bytes(32));
            $this->passes()->insert([
                'serial' => $serial, 'card_id' => $snapshot['CardId'], 'tenant_id' => $tenant->id, 'member_id' => $member,
                'pass_type' => config('clubunio-wallet.pass_type_identifier'), 'auth_hash' => hash('sha256', $token),
                'auth_encrypted' => Crypt::encryptString($token),
                'snapshot_encrypted' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'artwork_encrypted' => Crypt::encryptString(json_encode($artwork, JSON_THROW_ON_ERROR)),
                'fingerprint' => $this->fingerprint($snapshot, $artwork), 'revision' => 1,
            ]);
            $tag = $db->table('clubunio.member_wallet_changes')->insertGetId(['serial' => $serial]);
            $this->passes()->where('serial', $serial)->update(['update_tag' => $tag]);
            return $this->passes()->where('serial', $serial)->firstOrFail();
        });
    }

    private function saveSnapshot(object $row, array $snapshot, array $artwork): object
    {
        $fingerprint = $this->fingerprint($snapshot, $artwork);
        if (hash_equals($row->fingerprint, $fingerprint)) { return $row; }
        $tag = DB::connection('clubunio')->table('clubunio.member_wallet_changes')->insertGetId(['serial' => $row->serial]);
        $this->passes()->where('serial', $row->serial)->update([
            'snapshot_encrypted' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'artwork_encrypted' => Crypt::encryptString(json_encode($artwork, JSON_THROW_ON_ERROR)),
            'fingerprint' => $fingerprint, 'revision' => $row->revision + 1, 'update_tag' => $tag,
            'package_encrypted' => null, 'updated_at' => now(),
        ]);
        $this->registrations()->where('serial', $row->serial)->update(['attempts' => 0, 'next_attempt_at' => null]);
        return $this->passes()->where('serial', $row->serial)->firstOrFail();
    }

    public function refresh(object $row): object
    {
        return DB::connection('clubunio')->transaction(function () use ($row) {
            $row = $this->passes()->where('serial', $row->serial)->lockForUpdate()->firstOrFail();
            $tenant = Tenant::find($row->tenant_id);
            $artwork = json_decode(Crypt::decryptString($row->artwork_encrypted), true, flags: JSON_THROW_ON_ERROR);
            try {
                if (! $tenant) { abort(404); }
                $snapshot = $this->projection($tenant, (int) $row->member_id);
                $artwork = $this->artwork($tenant);
            } catch (\Throwable $error) {
                // A disappearance/revocation becomes a voided pass; transient storage/DB
                // failures do not manufacture invalid membership or publish stale data.
                $missing = $error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 410], true));
                if (! $missing) { throw $error; }
                $snapshot = json_decode(Crypt::decryptString($row->snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR);
                $snapshot['Valid'] = false; $snapshot['SourceVersion'] = hash('sha256', 'unavailable:'.$row->card_id);
            }
            return $this->saveSnapshot($row, $snapshot, $artwork);
        });
    }

    public function snapshot(object $row): array
    {
        return json_decode(Crypt::decryptString($row->snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    /** No birth date, address, account identifier or benefit claim is embedded. */
    public function payload(object $row): array
    {
        $snapshot = $this->snapshot($row);
        $hex = ltrim($snapshot['PrimaryColor'], '#');
        abort_unless(preg_match('/\A[0-9a-f]{6}\z/iD', $hex), 503);
        $rgb = array_map('hexdec', str_split($hex, 2));
        $light = ($rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114) / 1000 > 150;
        return [
            'formatVersion' => 1, 'passTypeIdentifier' => $row->pass_type, 'serialNumber' => $row->serial,
            'teamIdentifier' => config('clubunio-wallet.team_identifier'), 'organizationName' => $snapshot['TenantName'],
            'description' => 'wallet.member_card',
            'logoText' => $snapshot['TenantName'], 'backgroundColor' => 'rgb('.implode(', ', $rgb).')',
            'foregroundColor' => $light ? 'rgb(20, 25, 35)' : 'rgb(255, 255, 255)',
            'labelColor' => $light ? 'rgb(20, 25, 35)' : 'rgb(255, 255, 255)',
            'webServiceURL' => app(ApplePassBundle::class)->origin().'/wallet',
            'authenticationToken' => Crypt::decryptString($row->auth_encrypted), 'voided' => ! $snapshot['Valid'],
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $snapshot['VerificationUrl'], 'messageEncoding' => 'iso-8859-1']],
            'generic' => [
                'primaryFields' => [['key' => 'member', 'label' => 'wallet.member', 'value' => $snapshot['Name']]],
                'secondaryFields' => [['key' => 'status', 'label' => 'wallet.status', 'value' => $snapshot['Valid'] ? 'wallet.valid' : 'wallet.invalid', 'changeMessage' => '%@']],
                'auxiliaryFields' => [['key' => 'checked', 'label' => 'wallet.updated', 'value' => \Carbon\Carbon::parse($row->updated_at)->toIso8601String(), 'dateStyle' => 'PKDateStyleShort', 'timeStyle' => 'PKDateStyleShort']],
                'backFields' => [['key' => 'verification', 'label' => 'wallet.verify', 'value' => $snapshot['VerificationUrl']],
                    ['key' => 'live', 'label' => 'wallet.note', 'value' => 'wallet.live']],
            ],
        ];
    }

    public function package(object $row): string
    {
        $row = $this->refresh($row);
        if ($row->package_encrypted) { return Crypt::decryptString($row->package_encrypted); }
        $images = array_map('base64_decode', json_decode(Crypt::decryptString($row->artwork_encrypted), true, flags: JSON_THROW_ON_ERROR));
        $bytes = app(ApplePassBundle::class)->build($this->payload($row), $images);
        $this->passes()->where('serial', $row->serial)->where('revision', $row->revision)
            ->update(['package_encrypted' => Crypt::encryptString($bytes)]);
        return $bytes;
    }

    public function authorizedPass(string $type, string $serial, ?string $authorization): object
    {
        abort_unless($type === config('clubunio-wallet.pass_type_identifier') && Str::isUuid($serial), 401);
        abort_unless(is_string($authorization) && preg_match('/\AApplePass ([0-9a-f]{64})\z/D', $authorization, $m), 401);
        $row = $this->passes()->where('serial', $serial)->where('pass_type', $type)->first();
        abort_unless($row && hash_equals($row->auth_hash, hash('sha256', $m[1])), 401);
        return $row;
    }

    public function refreshAndNotify(int $limit = 200): array
    {
        if (! $this->available()) { return ['refreshed' => 0, 'sent' => 0, 'failed' => 0, 'configured' => false]; }
        $result = ['refreshed' => 0, 'sent' => 0, 'failed' => 0, 'configured' => true];
        $deadline = microtime(true) + 40;
        // Rotating oldest scan timestamp prevents a permanently failing member
        // or the first 200 passes from starving the remaining installed passes.
        $serials = $this->passes()->orderByRaw('coalesce(scanned_at, created_at)')->limit(max(1, min(1000, $limit)))->pluck('serial');
        foreach ($serials as $serial) {
            if (microtime(true) >= $deadline) { break; }
            try { $this->refresh($this->passes()->where('serial', $serial)->firstOrFail()); $result['refreshed']++; }
            catch (\Throwable) { $result['failed']++; }
            finally { $this->passes()->where('serial', $serial)->update(['scanned_at' => now()]); }
        }
        $pending = $this->registrations()->join('clubunio.member_wallet_passes as p', 'p.serial', '=', 'member_wallet_registrations.serial')
            ->whereColumn('notified_revision', '<', 'p.revision')
            ->where('attempts', '<', 8)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('member_wallet_registrations.id')->limit(max(1, min(1000, $limit)))
            ->get(['member_wallet_registrations.*', 'p.revision', 'p.pass_type']);
        foreach ($pending as $registration) {
            if (microtime(true) >= $deadline) { break; }
            try {
                $status = app(AppleWalletPush::class)->send(Crypt::decryptString($registration->push_encrypted), $registration->pass_type);
                if ($status === 410) { $this->registrations()->where('id', $registration->id)->delete(); }
                elseif ($status === 200) {
                    $this->registrations()->where('id', $registration->id)->where('notified_revision', '<', $registration->revision)
                        ->update(['notified_revision' => $registration->revision, 'attempts' => 0, 'next_attempt_at' => null]);
                    $result['sent']++;
                } else { throw new \RuntimeException('wallet_push_retry'); }
            } catch (\Throwable) {
                $attempt = min(20, $registration->attempts + 1);
                $this->registrations()->where('id', $registration->id)->update(['attempts' => $attempt, 'next_attempt_at' => now()->addSeconds(min(3600, 30 * 2 ** min(7, $attempt - 1)))]);
                $result['failed']++;
            }
        }
        return $result;
    }
}
