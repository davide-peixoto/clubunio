<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Member, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

/** Authoritative card data shared by the private app, public verification and Wallet. */
class MemberCardService
{
    public function enabled(): bool
    {
        return config('clubunio-member-cards.verification_enabled', false) === true;
    }

    public function origin(): string
    {
        $origin = rtrim((string) config('clubunio-member-cards.public_origin', config('app.url')), '/');
        $parts = parse_url($origin);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (! isset($parts['path']) || $parts['path'] === '') && ! isset($parts['port']), 503);
        return $origin;
    }

    private function cards()
    {
        return DB::connection('clubunio')->table('clubora.member_cards');
    }

    public function member(Tenant $tenant, int $id): Member
    {
        return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with(['status', 'type'])->findOrFail($id);
    }

    /** Uses semantic status policy, never localized labels, legacy IDs or type names. */
    public function membershipValid(Tenant $tenant, Member $member): bool
    {
        $today = now('Europe/Zurich')->startOfDay();
        return ! in_array($tenant->slug, config('clubunio-member-cards.disabled_tenant_slugs', []), true)
            && $member->tenant_id == $tenant->id && $member->archived_at === null
            && $member->status !== null && (bool) $member->status->active
            && (bool) $member->status->member_card_eligible && ! (bool) $member->status->ends_membership
            && $member->status->tenant_id == $tenant->id
            && $member->type !== null && (bool) $member->type->active && $member->type->tenant_id == $tenant->id
            && ($member->exit_date === null || $member->exit_date->copy()->startOfDay()->greaterThanOrEqualTo($today));
    }

    /** PNG/JPEG/WebP bytes only from the tenant's configured local logo; never fetch remote URLs. */
    public function logoBytes(Tenant $tenant): ?string
    {
        $design = app(SignageBrandService::class)->design($tenant);
        $path = $design['logo'];
        $bytes = null;
        if (preg_match('~^/club-logo/'.$tenant->id.'/([a-f0-9]{64})\.png$~D', $path, $match)) {
            try { $bytes = app(TenantLogoService::class)->response((int) $tenant->id, $match[1])->getContent(); }
            catch (\Throwable) { return null; }
        } elseif (preg_match('~^/(?:images|brand)/[a-zA-Z0-9_./-]+\.(?:png|jpe?g|webp)$~Di', $path) && ! str_contains($path, '..')) {
            $public = realpath(public_path()); $file = realpath(public_path(ltrim($path, '/')));
            if (! $public || ! $file || ! str_starts_with($file, $public.DIRECTORY_SEPARATOR) || filesize($file) > 5 * 1024 * 1024) return null;
            $bytes = file_get_contents($file);
        }
        if (! is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024 || ! function_exists('imagecreatefromstring')) return null;
        $size = @getimagesizefromstring($bytes);
        if (! $size || min($size[0], $size[1]) < 1 || max($size[0], $size[1]) > 2048
            || $size[0] * $size[1] > 4_194_304 || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return null;
        $image = @imagecreatefromstring($bytes); if (! $image) return null;
        $scale = min(1, 512 / max($size[0], $size[1]));
        $target = imagecreatetruecolor(max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale)));
        imagealphablending($target, false); imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $size[0], $size[1]);
        ob_start(); imagepng($target); $png = ob_get_clean(); imagedestroy($target); imagedestroy($image);
        return is_string($png) && strlen($png) <= 1_048_576 ? $png : null;
    }

    public function brand(Tenant $tenant): array
    {
        $design = app(SignageBrandService::class)->design($tenant);
        $bytes = $this->logoBytes($tenant);
        $logoUrl = null;
        if ($bytes && str_starts_with($design['logo'], '/')) {
            try { $logoUrl = $this->origin().$design['logo']; }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException) { /* Inline logo works before public verification setup. */ }
        }
        // A missing club logo is represented as initials by clients, never the app's build logo.
        return ['PrimaryColor' => $design['color'], 'LogoData' => $bytes ? base64_encode($bytes) : null,
            'LogoMime' => $bytes ? 'image/png' : null,
            'LogoUrl' => $logoUrl];
    }

    public function snapshot(Tenant $tenant, int $memberId): array
    {
        $member = $this->member($tenant, $memberId);
        $brand = $this->brand($tenant);
        $card = $this->enabled() ? $this->cards()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->first() : null;
        $valid = $this->membershipValid($tenant, $member) && (! $card || $card->revoked_at === null);
        $version = hash('sha256', json_encode([MemberService::version($member), $tenant->name,
            $member->status?->name, $member->type?->name, $valid, $brand,
            $card?->token_hash, $card?->revision, $card?->revoked_at], JSON_THROW_ON_ERROR));
        return ['member' => $member, 'Brand' => $brand, 'Valid' => $valid, 'CheckedAt' => now()->toIso8601String(),
            'SourceVersion' => $version, 'Name' => trim($member->first_name.' '.$member->last_name),
            'TenantName' => $tenant->name, 'StatusText' => $member->status?->name, 'TypeText' => $member->type?->name];
    }

    /** First issuance only. Revoked cards are never silently reissued by GET. */
    public function ensureCard(Tenant $tenant, int $memberId): object
    {
        abort_unless($this->enabled(), 503);
        $this->member($tenant, $memberId);
        $row = $this->cards()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->first();
        if ($row) return $row;
        $token = bin2hex(random_bytes(32));
        $this->cards()->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'member_id' => $memberId,
            'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token)]);
        return $this->cards()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->firstOrFail();
    }

    public function verification(Tenant $tenant, int $memberId, ?array $snapshot = null): ?array
    {
        if (! $this->enabled()) return null;
        $row = $this->ensureCard($tenant, $memberId);
        $snapshot ??= $this->snapshot($tenant, $memberId);
        return ['Url' => $this->origin().'/member-card/'.Crypt::decryptString($row->token_encrypted),
            'Valid' => $snapshot['Valid'], 'CheckedAt' => $snapshot['CheckedAt']];
    }

    public function revoke(Tenant $tenant, int $memberId): void
    {
        $this->ensureCard($tenant, $memberId);
        $this->cards()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now(), 'revision' => DB::connection('clubunio')->raw('revision + 1')]);
    }

    public function rotate(Tenant $tenant, int $memberId): void
    {
        $this->ensureCard($tenant, $memberId); $token = bin2hex(random_bytes(32));
        $this->cards()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->update([
            'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
            'revoked_at' => null, 'updated_at' => now(), 'revision' => DB::connection('clubunio')->raw('revision + 1')]);
    }

    /** Public bearer link reveals no record IDs or contacts, and queries current state for every scan. */
    public function resolve(string $token): array
    {
        $unknown = ['Known' => false, 'Valid' => false, 'CheckedAt' => now()->toIso8601String()];
        abort_unless($this->enabled(), 503);
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) return $unknown;
        $row = $this->cards()->where('token_hash', hash('sha256', $token))->first();
        if (! $row) return $unknown;
        $tenant = Tenant::find($row->tenant_id);
        if (! $tenant) return $unknown;
        $member = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($row->member_id)->first();
        if (! $member) return $unknown;
        $snapshot = $this->snapshot($tenant, (int) $row->member_id);
        // Invalid/revoked records disclose no personal name. The bearer is no longer useful.
        return ['Known' => true, 'Valid' => $snapshot['Valid'], 'CheckedAt' => $snapshot['CheckedAt'],
            'Name' => $snapshot['Valid'] ? $snapshot['Name'] : null, 'TenantName' => $tenant->name,
            'Brand' => $snapshot['Brand']];
    }
}
