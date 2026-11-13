<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ActiveTenant, ModulePermissionService, SocialMetaTestPublisher, SocialStudioData};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Throwable;

final class SocialStudioController
{
    private const WRITES = [
        'upload' => 'upload', 'media-archive' => 'deactivate', 'media-restore' => 'restore',
        'template-save' => 'update', 'template-copy' => 'copy',
        'template-archive' => 'deactivate', 'template-restore' => 'restore',
        'confirm-result' => 'approve', 'post-save' => 'update',
        'post-approve' => 'approve', 'post-archive' => 'deactivate', 'publish' => 'send',
        'job-save' => 'schedule',
    ];

    public function __construct(private SocialStudioData $data, private SocialMetaTestPublisher $meta) {}

    public function __invoke(Request $request, string $operation)
    {
        abort_unless(in_array($operation, $request->isMethod('GET')
            ? ['state', 'media', 'image', 'template', 'jobs', 'job-preview'] : array_keys(self::WRITES), true), 404);
        $tenant = Filament::getTenant();
        $actor = $request->user('clubunio')?->fresh();
        abort_unless($actor instanceof Account && $tenant instanceof Tenant && ! $actor->api_only
            && $actor->canAccessTenant($tenant) && ! $request->attributes->has('clubunio_impersonation'), 403);
        $context = $request->validate([
            'context' => 'required|array:tenant,revision,host', 'context.tenant' => 'required|integer|min:1',
            'context.revision' => 'required|uuid', 'context.host' => 'sometimes|required|string|max:253',
        ])['context'];
        $context['tenant'] = (int) $context['tenant'];
        app(ActiveTenant::class)->assertSnapshot($request, $context);
        $permissions = app(ModulePermissionService::class);
        $permissions->authorize($actor, $tenant, 'social_media', 'view');
        if ($request->isMethod('GET')) {
            $response = match ($operation) {
                'state' => $this->state($request, $tenant, $actor),
                'template' => $this->template($request, $tenant),
                'media', 'image' => $this->privateImage($request, $tenant, $operation),
                'jobs' => app(\App\ClubUnio\Services\SocialJobService::class)->index($actor, $tenant),
                'job-preview' => app(\App\ClubUnio\Services\SocialJobService::class)->preview($actor, $tenant, $request->all()),
            };
        } else {
            $function = in_array($operation, ['template-save', 'post-save'], true) && ! $request->filled('id')
                ? 'create' : self::WRITES[$operation];
            if ($operation !== 'post-save') {
                $permissions->authorize($actor, $tenant, 'social_media', $function);
            }
            $response = match ($operation) {
                'upload' => $this->upload($request, $tenant),
                'template-save', 'template-copy', 'template-archive', 'template-restore' => $this->writeTemplate($request, $tenant, $actor, $operation),
                'media-archive', 'media-restore' => $this->archiveMedia($request, $tenant, $operation === 'media-archive'),
                'confirm-result' => $this->confirmResult($request, $tenant, $actor),
                'post-save' => $this->savePost($request, $tenant, $actor),
                'post-approve', 'post-archive' => $this->updatePost($request, $tenant, $actor, $operation),
                'publish' => $this->publish($request, $tenant, $actor),
                'job-save' => app(\App\ClubUnio\Services\SocialJobService::class)->save($actor, $tenant, $request->all()),
            };
        }

        return (is_array($response) ? response()->json($response) : $response)->header('Cache-Control', 'private, no-store');
    }

    public function testImage(int $tenantId, string $id)
    {
        abort_unless($this->meta->target($tenantId, 'instagram'), 404);
        abort_unless($this->data->table($tenantId, 'deliveries')->where('post_id', $id)
            ->where('channel', 'instagram')->where('state', 'sending')->exists(), 404);
        $post = $this->data->table($tenantId, 'posts')->where('id', $id)->where('state', 'approved')->first();
        abort_unless($post, 404);

        return $this->file($post->image_path, 'image/jpeg');
    }

    private function state(Request $r, Tenant $tenant, Account $actor): array
    {
        $id = $tenant->id;
        $date = $r->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? now('Europe/Zurich')->toDateString();
        $permissions = app(ModulePermissionService::class);
        $archive = $r->boolean('archived');
        $postList = $this->data->table($id, 'posts')->where('state', $archive ? '=' : '!=', 'archived')
            ->orderByDesc('updated_at')->simplePaginate(20, ['id', 'title', 'kind', 'caption', 'state', 'revision', 'targets', 'snapshot', 'updated_at'], 'posts_page');
        $deliveries = $this->data->table($id, 'deliveries')->whereIn('post_id', collect($postList->items())->pluck('id'))
            ->get(['post_id', 'channel', 'state', 'remote_id'])->groupBy('post_id');
        $posts = collect($postList->items())->map(function ($row) use ($deliveries) {
            $row->targets = $this->data->decode($row->targets);
            $row->snapshot = $this->data->decode($row->snapshot);
            $row->deliveries = $deliveries->get($row->id, collect())->values();

            return $row;
        });
        $templates = $this->data->table($id, 'templates')->where('archived', $archive)->orderByDesc('updated_at')
            ->simplePaginate(30, ['id', 'name', 'revision', 'archived'], 'templates_page');
        $media = $this->data->table($id, 'media')->where('archived', $archive)->orderByDesc('created_at')
            ->simplePaginate(30, ['id', 'name', 'archived'], 'media_page');

        return [
            'date' => $date, 'club' => $tenant->name, 'fixtures' => $this->data->fixtures($tenant, $date),
            'posts' => $posts, 'templates' => $templates->items(), 'media' => $media->items(),
            'more' => ['posts' => $postList->hasMorePages(), 'templates' => $templates->hasMorePages(), 'media' => $media->hasMorePages()],
            'permissions' => collect(['create', 'update', 'upload', 'download', 'copy', 'approve', 'deactivate', 'restore', 'send', 'schedule'])
                ->mapWithKeys(fn ($fn) => [$fn => $permissions->allows($actor, $tenant, 'social_media', $fn)]),
            'test_channels' => array_values(array_filter(SocialStudioData::CHANNELS, fn ($channel) => $this->meta->target($id, $channel))),
        ];
    }

    private function template(Request $r, Tenant $tenant): array
    {
        $input = $r->validate(['id' => 'required|uuid', 'version' => 'nullable|integer|min:1', 'post' => 'nullable|boolean']);
        if ($r->boolean('post')) {
            $post = $this->data->table($tenant->id, 'posts')->where('id', $input['id'])->first();
            abort_unless($post, 404);

            return ['design' => $this->data->decode($post->design), 'revision' => (int) $post->revision];
        }
        $template = $this->data->table($tenant->id, 'templates')->where('id', $input['id'])->first();
        abort_unless($template, 404);
        $version = $this->data->table($tenant->id, 'template_versions')->where('template_id', $template->id)
            ->where('revision', $input['version'] ?? $template->revision)->first();
        abort_unless($version, 404);

        return ['design' => $this->data->decode($version->design), 'revision' => (int) $template->revision, 'name' => $template->name];
    }

    private function privateImage(Request $r, Tenant $tenant, string $operation)
    {
        $input = $r->validate(['id' => 'required|uuid']);
        if ($r->boolean('download')) {
            app(ModulePermissionService::class)->authorize($r->user('clubunio'), $tenant, 'social_media', 'download');
        }
        $row = $this->data->table($tenant->id, $operation === 'media' ? 'media' : 'posts')->where('id', $input['id'])->first();
        abort_unless($row, 404);

        return $this->file($row->path ?? $row->image_path, $operation === 'media' ? 'image/png' : 'image/jpeg', $r->boolean('download'));
    }

    private function file(string $path, string $mime, bool $download = false)
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $mime === 'image/jpeg' ? 'clubunio-social.jpg' : 'clubunio-social.png', [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
        ], $download ? 'attachment' : 'inline');
    }

    private function upload(Request $r, Tenant $tenant): array
    {
        $input = $r->validate(['name' => 'required|string|max:120', 'file' => 'required|file|max:8192']);
        $id = (string) Str::uuid();
        $path = $this->data->storeImage($tenant->id, $input['file']);
        try {
            $this->data->table($tenant->id, 'media')->insert(['tenant_id' => $tenant->id, 'id' => $id, 'name' => $input['name'], 'path' => $path]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return ['id' => $id];
    }

    private function archiveMedia(Request $r, Tenant $tenant, bool $archived): array
    {
        $input = $r->validate(['id' => 'required|uuid']);
        abort_unless($this->data->table($tenant->id, 'media')->where('id', $input['id'])->update(['archived' => $archived]), 404);

        return [];
    }

    private function writeTemplate(Request $r, Tenant $tenant, Account $actor, string $operation): array
    {
        $input = $r->validate([
            'id' => ($operation === 'template-save' ? 'nullable' : 'required').'|uuid',
            'revision' => 'required_with:id|nullable|integer|min:1',
            'name' => (in_array($operation, ['template-copy', 'template-save'], true) ? 'required' : 'nullable').'|string|max:120',
            'design' => ($operation === 'template-save' ? 'required' : 'nullable').'|array',
        ]);
        $tenantId = $tenant->id;
        $design = $operation === 'template-save' ? $this->data->design($tenantId, $input['design']) : null;

        return DB::connection('clubunio')->transaction(function () use ($input, $tenantId, $actor, $operation, $design) {
            $id = $input['id'] ?? (string) Str::uuid();
            $revision = 1;
            if (! empty($input['id'])) {
                $row = $this->data->table($tenantId, 'templates')->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_unless((int) $row->revision === (int) $input['revision'], 409);
                if (in_array($operation, ['template-archive', 'template-restore'], true)) {
                    $this->data->table($tenantId, 'templates')->where('id', $id)
                        ->update(['archived' => $operation === 'template-archive', 'updated_at' => now()]);

                    return [];
                }
                if ($operation === 'template-copy') {
                    $design = $this->data->decode($this->data->table($tenantId, 'template_versions')
                        ->where('template_id', $id)->where('revision', $row->revision)->value('design'));
                    $id = (string) Str::uuid();
                } else {
                    abort_if($row->archived, 409);
                    $revision = (int) $row->revision + 1;
                }
            }
            $this->data->table($tenantId, 'templates')->updateOrInsert(['tenant_id' => $tenantId, 'id' => $id],
                ['name' => $input['name'], 'revision' => $revision, 'updated_at' => now()]);
            $this->data->table($tenantId, 'template_versions')->insert([
                'tenant_id' => $tenantId, 'template_id' => $id, 'revision' => $revision,
                'design' => $this->data->json($design), 'actor_id' => $actor->id,
            ]);

            return ['id' => $id, 'revision' => $revision];
        });
    }

    private function confirmResult(Request $r, Tenant $tenant, Account $actor): array
    {
        $input = $r->validate([
            'date' => 'required|date_format:Y-m-d', 'fixture' => 'required|integer|min:1',
            'host_score' => 'required|integer|between:0,999', 'guest_score' => 'required|integer|between:0,999',
            'seen' => 'required|array',
        ]);
        $fixture = collect($this->data->fixtures($tenant, $input['date']))->firstWhere('id', $input['fixture']);
        $this->data->require($fixture && $fixture == $input['seen'] && $fixture['time'] !== ''
            && $fixture['date'].' '.$fixture['time'] <= now('Europe/Zurich')->format('Y-m-d H:i'), 'result_required');
        unset($fixture['score']);
        $db = DB::connection('clubunio');
        $db->transaction(function () use ($tenant, $input, $fixture, $actor) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $current = collect($this->data->fixtures($tenant, $input['date']))->firstWhere('id', $input['fixture']);
            abort_unless($current == $input['seen'], 409);
            $this->data->table($tenant->id, 'results')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'competition_id' => $input['fixture']], [
                    'host_score' => $input['host_score'], 'guest_score' => $input['guest_score'],
                    'fixture' => $this->data->json($fixture), 'actor_id' => $actor->id, 'updated_at' => now(),
                ]);
        });

        return [];
    }

    private function savePost(Request $r, Tenant $tenant, Account $actor): array
    {
        $metadata = $r->validate(['metadata' => 'required|string|max:100000'])['metadata'];
        $payload = json_decode($metadata, true, 20);
        $this->data->require(is_array($payload), 'invalid');
        $r->merge(array_intersect_key($payload, array_flip(['id', 'revision', 'kind', 'title', 'caption', 'targets', 'design', 'date', 'fixtures', 'seen'])));
        $input = $r->validate([
            'id' => 'nullable|uuid', 'revision' => 'required_with:id|nullable|integer|min:1',
            'kind' => 'required|in:day,result,free', 'title' => 'required|string|max:120', 'caption' => 'nullable|string|max:2200',
            'targets' => 'required|array|min:1|max:2', 'targets.*' => 'required|distinct|in:facebook,instagram',
            'design' => 'required|array', 'image' => 'required|file|max:6144',
            'date' => 'required|date_format:Y-m-d', 'fixtures' => 'present|array|max:12',
            'fixtures.*' => 'integer|min:1|distinct', 'seen' => 'present|array',
        ]);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'social_media', empty($input['id']) ? 'create' : 'update');
        $id = $input['id'] ?? (string) Str::uuid();
        $tenantId = $tenant->id;
        $design = $this->data->design($tenantId, $input['design']);
        $snapshot = $this->data->snapshot($tenant, $input['kind'], $input['date'], $input['fixtures']);
        abort_unless($snapshot == $input['seen'], 409);
        $path = $this->data->storeImage($tenantId, $input['image'], true, SocialStudioData::FORMATS[$design['format']]);
        try {
            $old = DB::connection('clubunio')->transaction(function () use ($id, $tenantId, $input, $design, $snapshot, $path, $actor) {
                $query = $this->data->table($tenantId, 'posts')->where('id', $id);
                $row = isset($input['id']) ? $query->lockForUpdate()->first() : null;
                if (isset($input['id'])) {
                    abort_unless($row, 404);
                    abort_unless($row->state !== 'archived' && (int) $row->revision === (int) $input['revision']
                        && ! $this->data->table($tenantId, 'deliveries')->where('post_id', $id)->exists(), 409);
                }
                $values = [
                    'kind' => $input['kind'], 'title' => $input['title'], 'caption' => $input['caption'] ?? '',
                    'design' => $this->data->json($design), 'snapshot' => $this->data->json($snapshot),
                    'targets' => $this->data->json($input['targets']), 'image_path' => $path,
                    'state' => 'draft', 'revision' => ((int) ($row->revision ?? 0)) + 1,
                    'actor_id' => $actor->id, 'approved_by' => null, 'approved_at' => null, 'updated_at' => now(),
                ];
                $row ? $query->update($values) : $query->insert(['tenant_id' => $tenantId, 'id' => $id, ...$values]);

                return $row?->image_path;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return ['id' => $id];
    }

    private function updatePost(Request $r, Tenant $tenant, Account $actor, string $operation): array
    {
        $input = $r->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:1']);
        DB::connection('clubunio')->transaction(function () use ($input, $tenant, $actor, $operation) {
            $query = $this->data->table($tenant->id, 'posts')->where('id', $input['id']);
            $post = $query->lockForUpdate()->first();
            abort_unless($post, 404);
            abort_unless($post->state !== 'archived' && (int) $post->revision === (int) $input['revision'], 409);
            $delivery = $this->data->table($tenant->id, 'deliveries')->where('post_id', $post->id);
            if ($operation === 'post-approve') {
                abort_unless($post->state === 'draft' && ! $delivery->exists(), 409);
                $this->checkSnapshot($tenant, $post);
                $values = ['state' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()];
            } else {
                abort_if($delivery->whereIn('state', ['sending', 'unknown'])->exists(), 409);
                $values = ['state' => 'archived'];
            }
            $query->update([...$values, 'revision' => ((int) $post->revision) + 1, 'updated_at' => now()]);
        });

        return [];
    }

    private function checkSnapshot(Tenant $tenant, object $post): void
    {
        $snapshot = $this->data->decode($post->snapshot);
        $this->data->assertSnapshot($tenant, $post->kind, $snapshot);
    }

    private function publish(Request $r, Tenant $tenant, Account $actor): array
    {
        $input = $r->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'channel' => 'required|in:instagram,facebook']);
        return ['state' => $this->meta->publish($tenant, $actor, $input['id'], (int) $input['revision'], $input['channel'],
            validate: fn ($post) => $this->checkSnapshot($tenant, $post))];
    }
}
