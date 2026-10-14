<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SignageFeedService
{
    public function verifyInstagram(Account $actor, Tenant $tenant): array
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        $reference = (string) Str::uuid();
        $result = ['state' => 'missing', 'item_count' => 0, 'http_status' => null, 'reference' => $reference];
        $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'integrations')->where('key', 'INSTAGRAM_ACCESS_TOKEN')->first();
        try {
            $token = $setting ? trim(Crypt::decryptString($setting->encrypted_value)) : '';
            if ($token !== '') {
                $items = $this->instagram($token);
                $result['state'] = 'ready';
                $result['item_count'] = count($items);
                $result['http_status'] = 200;
            }
        } catch (\Throwable $error) {
            $result['state'] = 'failed';
            $result['http_status'] = $error->getCode() >= 400 && $error->getCode() <= 599 ? (int) $error->getCode() : null;
            Log::warning('clubunio.instagram.check_failed', ['tenant_id' => $tenant->id, 'reference' => $reference, 'http_status' => $result['http_status']]);
        }
        DB::connection('clubunio')->table('clubora.integration_checks')->insert($result + ['kind' => 'instagram', 'tenant_id' => $tenant->id, 'actor_id' => $actor->id]);

        return $result;
    }

    public function read(Account $actor, Tenant $tenant, string $feed): array
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        abort_unless(in_array($feed, ['weather', 'instagram'], true), 404);
        $empty = ['state' => 'disabled', 'data' => null, 'updated' => null];
        try {
            $keys = $feed === 'weather' ? ['SIGNAGE_WEATHER_ENABLED', 'SIGNAGE_WEATHER_LAT', 'SIGNAGE_WEATHER_LON', 'SIGNAGE_WEATHER_LABEL'] : ['INSTAGRAM_ACCESS_TOKEN'];
            $settings = Setting::where('tenant_id', $tenant->id)->where('area', 'integrations')->whereIn('key', $keys)->where('enabled', true)->get();
            $values = $settings->mapWithKeys(fn ($row) => [$row->key => trim(Crypt::decryptString($row->encrypted_value))])->all();
            if ($feed === 'weather' && ($values['SIGNAGE_WEATHER_ENABLED'] ?? '0') !== '1') {
                return $empty;
            }
            if ($feed === 'instagram' && empty($values['INSTAGRAM_ACCESS_TOKEN'])) {
                return $empty;
            }
            // Includes tenant and configuration fingerprint; old credentials never reuse old posts.
            $key = 'clubunio.signage.v1.'.$tenant->id.'.'.$feed.'.'.hash('sha256', json_encode($values));
            $cache = Cache::store();
            $saved = $cache->get($key);
            $fresh = is_array($saved) && ($saved['at'] ?? 0) > now()->timestamp - 900;
            $recent = is_array($saved) && ($saved['at'] ?? 0) > now()->timestamp - 21600;
            if ($fresh) {
                return ['state' => 'ready', 'data' => $saved['data'], 'updated' => $saved['updated']];
            }
            $fallback = $recent ? ['state' => 'stale', 'data' => $saved['data'], 'updated' => $saved['updated']] : ['state' => 'unavailable', 'data' => null, 'updated' => null];
            // Cooldown applies to success, failures and concurrent displays, including refresh clicks.
            $lock = $cache->lock($key.'.lock', 15);
            if (! $lock->get()) {
                return $fallback;
            }
            try {
                if (! $cache->add($key.'.attempt', true, 300)) {
                    return $fallback;
                }
                $data = $feed === 'weather' ? $this->weather($values) : $this->instagram($values['INSTAGRAM_ACCESS_TOKEN']);
                $saved = ['at' => now()->timestamp, 'updated' => now('Europe/Zurich')->format('d.m.Y H:i'), 'data' => $data];
                $cache->put($key, $saved, 21600);

                return ['state' => 'ready', 'data' => $data, 'updated' => $saved['updated']];
            } catch (\Throwable $e) {
                // Never log upstream payloads, URLs, headers or exception messages containing tokens.
                Log::warning('ClubUnio signage feed unavailable', ['tenant_id' => $tenant->id, 'feed' => $feed, 'http_status' => $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : null]);

                return $fallback;
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('ClubUnio signage feed configuration/cache unavailable', ['tenant_id' => $tenant->id, 'feed' => $feed]);

            return ['state' => 'unavailable', 'data' => null, 'updated' => null];
        }
    }

    private function client()
    {
        return Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting();
    }

    private function weather(array $values): array
    {
        Validator::make($values, ['SIGNAGE_WEATHER_LAT' => 'required|numeric|between:-90,90', 'SIGNAGE_WEATHER_LON' => 'required|numeric|between:-180,180', 'SIGNAGE_WEATHER_LABEL' => 'required|string|max:100'])->validate();
        $response = $this->client()->get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $values['SIGNAGE_WEATHER_LAT'], 'longitude' => $values['SIGNAGE_WEATHER_LON'],
            'current' => 'temperature_2m,weather_code,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
            'timezone' => 'Europe/Zurich', 'forecast_days' => 3, 'models' => 'meteoswiss_icon_seamless',
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Weather unavailable', $response->status());
        }
        $data = $response->json();
        Validator::make(is_array($data) ? $data : [], [
            'current.temperature_2m' => 'required|numeric|between:-100,70', 'current.weather_code' => 'required|integer|between:0,99', 'current.wind_speed_10m' => 'required|numeric|between:0,500',
            'current.time' => 'required|date_format:Y-m-d\TH:i',
            'daily.time' => 'required|array|size:3', 'daily.time.*' => 'required|date_format:Y-m-d',
            'daily.weather_code' => 'required|array|size:3', 'daily.weather_code.*' => 'required|integer|between:0,99',
            'daily.temperature_2m_max' => 'required|array|size:3', 'daily.temperature_2m_max.*' => 'required|numeric|between:-100,70',
            'daily.temperature_2m_min' => 'required|array|size:3', 'daily.temperature_2m_min.*' => 'required|numeric|between:-100,70',
        ])->validate();
        $days = [];
        foreach ($data['daily']['time'] as $i => $date) {
            $days[] = ['date' => $date, 'text' => $this->weatherText($data['daily']['weather_code'][$i]), 'max' => round($data['daily']['temperature_2m_max'][$i]), 'min' => round($data['daily']['temperature_2m_min'][$i])];
        }

        return ['label' => $values['SIGNAGE_WEATHER_LABEL'], 'temperature' => round($data['current']['temperature_2m'], 1), 'wind' => round($data['current']['wind_speed_10m']), 'text' => $this->weatherText($data['current']['weather_code']), 'time' => $data['current']['time'], 'days' => $days];
    }

    private function weatherText(int $code): string
    {
        return match ($code) {
            0 => 'Klar', 1 => 'Überwiegend klar', 2 => 'Teilweise bewölkt', 3 => 'Bedeckt',
            45, 48 => 'Nebel', 51, 53, 55 => 'Nieselregen', 56, 57 => 'Gefrierender Nieselregen',
            61, 63, 65 => 'Regen', 66, 67 => 'Gefrierender Regen', 71, 73, 75, 77 => 'Schnee',
            80, 81, 82 => 'Regenschauer', 85, 86 => 'Schneeschauer', 95, 96, 99 => 'Gewitter', default => 'Unbekannt',
        };
    }

    private function instagram(string $token): array
    {
        if (preg_match('/[\r\n]/', $token)) {
            throw new \RuntimeException('Invalid configuration');
        }
        $response = $this->client()->withToken($token)->get('https://graph.instagram.com/v25.0/me/media', ['fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink', 'limit' => 8]);
        if (! $response->successful()) {
            throw new \RuntimeException('Instagram unavailable', $response->status());
        }
        $body = $response->json();
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data']) || isset($body['error'])) {
            throw new \RuntimeException('Invalid media response');
        }
        $items = [];
        foreach (array_slice($body['data'], 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $image = ($row['media_type'] ?? '') === 'VIDEO' ? ($row['thumbnail_url'] ?? '') : ($row['media_url'] ?? '');
            $link = $row['permalink'] ?? '';
            if (! $this->safeUrl($image, ['cdninstagram.com', 'fbcdn.net']) || ! $this->safeUrl($link, ['instagram.com'])) {
                continue;
            }
            $items[] = ['image' => $image, 'link' => $link, 'caption' => mb_substr(is_string($row['caption'] ?? null) ? $row['caption'] : '', 0, 300)];
        }

        return $items;
    }

    private function safeUrl(mixed $url, array $hosts): bool
    {
        if (! is_string($url) || strlen($url) > 4096 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return collect($hosts)->contains(fn ($allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed));
    }
}
