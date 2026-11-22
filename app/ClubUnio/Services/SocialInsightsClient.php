<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Http;

/** Read-only Graph adapter. Never follows provider-supplied pagination URLs. */
final class SocialInsightsClient
{
    private ?float $deadline = null;

    public function get(string $token, string $path, array $query = [], bool $optional = false): ?array
    {
        abort_if($this->deadline !== null && microtime(true) > $this->deadline,504,trans('social_insights.failed'));
        abort_unless(preg_match('~\A(?:me|[0-9_]+)(?:/[a-z_]+)?\z~', $path), 422);
        try {
            $secret = (string) config('clubunio-social-insights.app_secret');
            if ($secret !== '') $query['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
            $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->get('https://graph.facebook.com/'.config('clubunio-social-insights.version').'/'.$path, $query);
        } catch (\Throwable) {
            abort(503, trans('social_insights.failed'));
        }
        if (! $response->successful() || ! is_array($response->json()) || $response->json('error')) {
            $code = (int) $response->json('error.code');
            if ($optional && in_array($code, [10, 100, 200], true) && $response->clientError()) return null;
            abort(match (true) { $code === 190 => 401, $response->status() === 429 || in_array($code, [4, 17, 32, 613], true) => 429, default => 502 }, trans('social_insights.failed'));
        }
        return $response->json();
    }

    public function exchange(array $parameters): array
    {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->post('https://graph.facebook.com/'.config('clubunio-social-insights.version').'/oauth/access_token', [
                    'client_id' => config('clubunio-social-insights.app_id'),
                    'client_secret' => config('clubunio-social-insights.app_secret'), ...$parameters,
                ]);
            $data = $response->json();
        } catch (\Throwable) { abort(503, trans('social_insights.failed')); }
        abort_unless($response->successful() && is_array($data) && is_string($data['access_token'] ?? null)
            && strlen($data['access_token']) >= 10, 502, trans('social_insights.failed'));
        return $data;
    }

    public function pages(string $token): array
    {
        $rows = []; $after = null;
        for ($i = 0; $i < 3; $i++) {
            $data = $this->get($token, 'me/accounts', array_filter([
                'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 50, 'after' => $after,
            ]));
            $rows = [...$rows, ...($data['data'] ?? [])];
            $after = isset($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
            if (! is_string($after) || strlen($after) > 2000) break;
        }
        return $rows;
    }

    public function posts(object $connection, string $token): array
    {
        $this->deadline = microtime(true) + 120;
        $ig = $connection->platform === 'instagram';
        $fields = $ig ? 'id,caption,media_type,permalink,timestamp,like_count,comments_count'
            : 'id,message,created_time,permalink_url,status_type,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares';
        $rows = []; $after = null; $cutoff = now()->subDays(90);
        for ($i = 0; $i < 4; $i++) {
            $data = $this->get($token, $connection->external_id.($ig ? '/media' : '/published_posts'), array_filter([
                'fields' => $fields, 'limit' => 25, 'after' => $after, 'since' => $ig ? null : $cutoff->timestamp,
            ]));
            foreach ($data['data'] ?? [] as $row) {
                $date = $row[$ig ? 'timestamp' : 'created_time'] ?? null;
                abort_unless(is_string($date) && is_string($row['id'] ?? null) && preg_match('/\A[0-9_]{1,100}\z/', $row['id']), 502);
                try { $published = \Illuminate\Support\Carbon::parse($date); } catch (\Throwable) { abort(502); }
                if ($published->lt($cutoff)) continue;
                $rows[] = ['external_id' => $row['id'], 'caption' => mb_substr((string) ($row[$ig ? 'caption' : 'message'] ?? ''), 0, 20000),
                    'permalink' => self::safeLink($row[$ig ? 'permalink' : 'permalink_url'] ?? null),
                    'media_type' => mb_substr((string) ($row[$ig ? 'media_type' : 'status_type'] ?? 'POST'), 0, 30),
                    'published_at' => $published->toIso8601String(), 'metrics' => $this->metrics($token, $row, $ig)];
            }
            $after = isset($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
            if (! is_string($after) || strlen($after) > 2000) break;
        }
        return $rows;
    }

    public static function safeLink(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2000) return null;
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && in_array(strtolower($parts['host'] ?? ''), ['www.instagram.com','instagram.com','www.facebook.com','facebook.com'], true)
            && ! isset($parts['query']) ? $url : null;
    }

    public function metrics(string $token, array $row, bool $ig): array
    {
        $metrics = ['likes' => self::number($ig ? ($row['like_count'] ?? null) : data_get($row, 'reactions.summary.total_count')),
            'comments' => self::number($ig ? ($row['comments_count'] ?? null) : data_get($row, 'comments.summary.total_count')),
            'shares' => $ig ? null : self::number(data_get($row, 'shares.count')),
            'saves' => null, 'views' => null, 'reach' => null];
        // Separate optional metrics so one unsupported metric cannot erase the others.
        $names = $ig ? ['views'=>'views','reach'=>'reach','shares'=>'shares','saves'=>'saved']
            : ['views'=>'post_media_view','reach'=>'post_total_media_view_unique'];
        foreach ($names as $key => $name) {
            $data = $this->get($token, $row['id'].'/insights', ['metric' => $name], true);
            $item = $data['data'][0] ?? null;
            $metrics[$key] = is_array($item) && ($item['name'] ?? null) === $name
                ? self::number(data_get($item, 'values.0.value', data_get($item, 'total_value.value'))) : null;
        }
        return $metrics;
    }

    public static function number(mixed $value): ?int
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= PHP_INT_MAX
            && floor((float) $value) === (float) $value ? (int) $value : null;
    }
}
