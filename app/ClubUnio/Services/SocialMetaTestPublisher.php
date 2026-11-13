<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\{Http, Storage, URL};

final class SocialMetaTestPublisher
{
    public function target(int $tenant, string $channel, ?string $origin = null): ?array
    {
        $origin ??= request()->getSchemeAndHttpHost();
        if (! in_array($channel, SocialStudioData::CHANNELS, true)
            || ! config('clubunio-social.test_enabled') || $origin !== config('clubunio-social.dev_origin')
            || rtrim((string) config('app.url'), '/') !== config('clubunio-social.dev_origin')
            || (string) $tenant !== (string) config('clubunio-social.tenant_id')) {
            return null;
        }
        $target = config('clubunio-social.'.$channel);

        return is_array($target) && preg_match('/\A[0-9]{5,32}\z/', (string) ($target['id'] ?? ''))
            && is_string($target['token'] ?? null) && trim($target['token']) !== '' ? $target : null;
    }

    public function deliver(int $tenant, object $post, string $channel, array $target): array
    {
        $api = 'https://'.($channel === 'instagram' ? 'graph.instagram.com' : 'graph.facebook.com')
            .'/'.config('clubunio-social.version').'/'.$target['id'];
        $http = Http::withToken($target['token'])->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
        $caption = '[ClubUnio DEV TEST] '.mb_substr($post->caption, 0, 2179);
        if ($channel === 'instagram') {
            $image = URL::temporarySignedRoute('clubunio.social.test-image', now()->addMinutes(15), [
                'tenantId' => $tenant, 'id' => $post->id,
            ]);
            $container = $http->post($api.'/media', ['image_url' => $image, 'caption' => $caption]);
            $id = $container->json('id');
            if (! $container->successful() || ! is_string($id) || ! ctype_digit($id)) {
                // Container creation itself cannot publish a post.
                return ['state' => 'failed', 'error_code' => 'container'];
            }
            $response = $http->post($api.'/media_publish', ['creation_id' => $id]);
        } else {
            $bytes = Storage::disk('local')->get($post->image_path);
            if ($bytes === null) {
                return ['state' => 'failed', 'error_code' => 'image'];
            }
            $response = $http->attach('source', $bytes, 'clubunio-test.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($api.'/photos', ['caption' => $caption]);
        }
        $id = $response->json('post_id') ?? $response->json('id');
        if ($response->successful() && is_string($id) && preg_match('/\A[0-9_]{1,100}\z/', $id)) {
            return ['state' => 'published', 'remote_id' => $id];
        }

        return $response->clientError() && is_array($response->json('error'))
            ? ['state' => 'failed', 'error_code' => 'rejected']
            : ['state' => 'unknown', 'error_code' => 'check_meta'];
    }
}
