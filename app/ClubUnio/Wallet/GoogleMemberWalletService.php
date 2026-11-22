<?php

namespace App\ClubUnio\Wallet;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberCardService;
use Illuminate\Support\Facades\{Cache, Crypt, DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GoogleMemberWalletService
{
    public function passes() { return DB::connection('clubunio')->table('clubora.member_google_wallet_passes'); }

    public function available(?Tenant $tenant = null): bool
    {
        return app(MemberCardService::class)->enabled() && app(GoogleWalletClient::class)->available()
            && ($tenant === null || app(MemberCardService::class)->logoBytes($tenant) !== null);
    }

    public function issue(Tenant $tenant, int $member): object
    {
        abort_unless($this->available($tenant), 503);
        $card = app(MemberCardService::class)->ensureCard($tenant, $member);
        $client = app(GoogleWalletClient::class);
        $row = DB::connection('clubunio')->transaction(function () use ($card, $tenant, $member, $client) {
            DB::connection('clubunio')->table('clubora.member_cards')->where('id', $card->id)->lockForUpdate()->firstOrFail();
            $existing = $this->passes()->where('card_id', $card->id)->first();
            if ($existing) return $existing;
            $id = (string) Str::uuid();
            $this->passes()->insert(['id' => $id, 'card_id' => $card->id, 'tenant_id' => $tenant->id,
                'member_id' => $member, 'environment' => $client->environment(), 'object_id' => $client->objectId($id)]);
            return $this->passes()->where('id', $id)->firstOrFail();
        });
        $this->synchronize($row->id, true);
        return $this->passes()->where('id', $row->id)->firstOrFail();
    }

    private function projection(object $row): array
    {
        $cards = app(MemberCardService::class);
        $tenant = Tenant::withoutGlobalScopes()->find($row->tenant_id);
        try {
            abort_unless($tenant, 404);
            $snapshot = $cards->snapshot($tenant, (int) $row->member_id);
            $url = $cards->verification($tenant, (int) $row->member_id, $snapshot)['Url'] ?? null;
            abort_unless(is_string($url) && str_starts_with($url, $cards->origin().'/member-card/'), 503);
            $logo = $cards->logoBytes($tenant);
            if ($logo === null && $row->logo_encrypted) $logo = Crypt::decryptString($row->logo_encrypted);
            abort_unless(is_string($logo) && strlen($logo) <= 1_048_576, 503);
            return [['Name' => $snapshot['Name'], 'TenantName' => $snapshot['TenantName'],
                'Valid' => $snapshot['Valid'] === true, 'VerificationUrl' => $url,
                'PrimaryColor' => $snapshot['Brand']['PrimaryColor'], 'SourceVersion' => $snapshot['SourceVersion']], $logo];
        } catch (HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            if ((! $error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException && ! in_array($error->getStatusCode(), [403, 404, 410], true))
                || ! $row->projection_encrypted || ! $row->logo_encrypted) throw $error;
            $previous = json_decode(Crypt::decryptString($row->projection_encrypted), true, 16, JSON_THROW_ON_ERROR);
            $previous['Valid'] = false;
            $previous['Name'] = '';
            $previous['SourceVersion'] = hash('sha256', 'unavailable:'.$row->card_id);
            return [$previous, Crypt::decryptString($row->logo_encrypted)];
        }
    }

    private function localized(array $values): array
    {
        $translations = [];
        foreach ($values as $locale => $value) $translations[] = ['language' => $locale, 'value' => $value];
        return ['defaultValue' => $translations[0], 'translatedValues' => $translations];
    }

    public function payload(object $row, array $projection, string $logoHash): array
    {
        $client = app(GoogleWalletClient::class);
        $catalogs = app(ApplePassBundle::class)->localizations();
        $label = fn (string $key) => $this->localized(array_map(fn ($catalog) => $catalog[$key], $catalogs));
        $literal = fn (string $value) => $this->localized(array_fill_keys(array_keys($catalogs), $value));
        return ['id' => $row->object_id, 'classId' => $client->classId(), 'genericType' => 'GENERIC_OTHER',
            'state' => $projection['Valid'] ? 'ACTIVE' : 'INACTIVE',
            'cardTitle' => $literal($projection['TenantName']),
            'header' => $projection['Valid'] ? $literal($projection['Name']) : $label('wallet.member_card'),
            'subheader' => $label('wallet.member_card'), 'hexBackgroundColor' => $projection['PrimaryColor'],
            'logo' => ['sourceUri' => ['uri' => $client->origin().'/wallet/google/artwork/'.$row->id.'/'.$logoHash.'.png'],
                'contentDescription' => $literal($projection['TenantName'])],
            'barcode' => ['type' => 'QR_CODE', 'value' => $projection['VerificationUrl']],
            'textModulesData' => [
                ['id' => 'membership-status', 'localizedHeader' => $label('wallet.status'),
                    'localizedBody' => $label($projection['Valid'] ? 'wallet.valid' : 'wallet.invalid')],
                ['id' => 'live-verification', 'localizedHeader' => $label('wallet.note'), 'localizedBody' => $label('wallet.live')],
            ],
            'linksModuleData' => ['uris' => [['uri' => $projection['VerificationUrl'], 'localizedDescription' => $label('wallet.verify')]]],
        ];
    }

    /** Network I/O is serialized per object, never inside a database transaction. */
    public function synchronize(string $id, bool $immediate = false): void
    {
        $client = app(GoogleWalletClient::class);
        Cache::lock('google-wallet:'.$client->environment().':'.$id, 120)->block(2, function () use ($id, $client, $immediate) {
            $row = $this->passes()->where('id', $id)->firstOrFail();
            abort_unless($row->environment === $client->environment() && hash_equals($client->objectId($row->id), $row->object_id), 503);
            try {
                [$projection, $logo] = $this->projection($row);
                $logoHash = hash('sha256', $logo);
                $payload = $this->payload($row, $projection, $logoHash);
                $fingerprint = hash('sha256', json_encode([$projection, $payload], JSON_THROW_ON_ERROR));
                $changed = $row->desired_fingerprint !== $fingerprint;
                $this->passes()->where('id', $id)->update(['checked_at' => now(), 'updated_at' => now(),
                    'desired_fingerprint' => $fingerprint,
                    'projection_encrypted' => Crypt::encryptString(json_encode($projection, JSON_THROW_ON_ERROR)),
                    'logo_encrypted' => Crypt::encryptString($logo), 'logo_hash' => $logoHash]);
                if ($row->fingerprint === $fingerprint) {
                    $this->passes()->where('id', $id)->update(['dirty' => false, 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);
                    return;
                }
                if (! $immediate && ! $changed && $row->next_attempt_at && now()->lt($row->next_attempt_at)) return;
                $this->passes()->where('id', $id)->update(['dirty' => true]);
                $client->upsert($payload);
                // If membership changed during the provider call, leave a durable retry.
                [$after] = $this->projection($this->passes()->where('id', $id)->firstOrFail());
                if ($after !== $projection) {
                    $this->passes()->where('id', $id)->update(['dirty' => true, 'next_attempt_at' => null, 'checked_at' => null]);
                    abort(409);
                }
                $this->passes()->where('id', $id)->where('desired_fingerprint', $fingerprint)->update([
                    'fingerprint' => $fingerprint, 'dirty' => false, 'attempts' => 0,
                    'next_attempt_at' => null, 'last_error' => null, 'synced_at' => now()]);
            } catch (\Throwable $error) {
                $attempts = min(20, (int) $row->attempts + 1);
                $this->passes()->where('id', $id)->update(['dirty' => true, 'checked_at' => now(), 'updated_at' => now(),
                    'attempts' => $attempts, 'next_attempt_at' => now()->addSeconds(min(3600, 30 * 2 ** min(7, $attempts - 1))),
                    'last_error' => 'synchronization_failed']);
                throw $error;
            }
        });
    }

    public function refresh(int $limit = 100): array
    {
        $counts = ['checked' => 0, 'failed' => 0];
        if (! $this->available()) return $counts;
        $deadline = microtime(true) + 40;
        $rows = $this->passes()->where('environment', app(GoogleWalletClient::class)->environment())
            ->orderByRaw('coalesce(checked_at, created_at) asc')->limit(max(1, min(500, $limit)))->get(['id']);
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) break;
            try { $this->synchronize($row->id); } catch (\Throwable) { $counts['failed']++; }
            $counts['checked']++;
        }
        return $counts;
    }
}
