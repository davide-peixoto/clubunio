<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Http, Storage, URL};
use Throwable;

final class SocialMetaTestPublisher
{
    public function publish(Tenant $tenant, Account $actor, string $id, int $revision, string $channel, ?string $origin = null, ?callable $validate = null): string
    {
        $target = $this->target($tenant->id, $channel, $origin);
        abort_unless($target, 403);
        $data = app(SocialStudioData::class);
        $delivery = $data->table($tenant->id, 'deliveries')->where('post_id', $id)->where('channel', $channel);
        $post = DB::connection('clubunio')->transaction(function () use ($tenant, $actor, $id, $revision, $channel, $target, $data, $delivery, $validate) {
            app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'social_media', 'send');
            $post = $data->table($tenant->id, 'posts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($post, 404);
            abort_unless($post->state === 'approved' && (int) $post->revision === $revision
                && in_array($channel, $data->decode($post->targets), true), 409);
            $validate?->__invoke($post);
            $previous = $delivery->first();
            abort_unless(! $previous || ($previous->state === 'failed' && $previous->target === $target['id']), 409);
            $delivery->updateOrInsert(['tenant_id' => $tenant->id, 'post_id' => $id, 'channel' => $channel],
                ['state' => 'sending', 'target' => $target['id'], 'actor_id' => $actor->id, 'error_code' => null, 'updated_at' => now()]);

            return $post;
        });
        try {
            $result = $this->deliver($tenant->id, $post, $channel, $target);
        } catch (Throwable) {
            $result = ['state' => 'unknown', 'error_code' => 'check_meta'];
        }
        $delivery->update([...$result, 'updated_at' => now()]);

        return $result['state'];
    }

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
