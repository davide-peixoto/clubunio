<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ClubhouseMenus
{
    public function query(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.clubhouse_menus')->where('tenant_id', $tenant->id);
    }

    public function decode(mixed $value): ?array
    {
        return $value === null ? null : (is_array($value) ? $value : json_decode($value, true, flags: JSON_THROW_ON_ERROR));
    }

    public function row(object $row): array
    {
        $data = (array) $row;
        foreach (['channels', 'document', 'published_snapshot'] as $key) {
            $data[$key] = $this->decode($data[$key] ?? null);
        }
        // Storage locators never belong in client payloads, including management responses.
        $data['has_document'] = $data['document'] !== null;
        unset($data['document']);
        $data['is_live'] = $data['published_snapshot'] !== null;
        unset($data['published_snapshot']);

        return $data;
    }

    public function get(Account $actor, Tenant $tenant, int $id): array
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'view');

        return $this->row($this->query($tenant)->where('id', $id)->firstOrFail());
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): int
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'menus.write');
        $data = Validator::make($input, ['title_de' => 'required|string|max:160', 'title_en' => 'nullable|string|max:160',
            'body_de' => 'nullable|string|max:20000', 'body_en' => 'nullable|string|max:20000',
            'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:valid_from',
            'channels' => 'present|array|max:2', 'channels.*' => 'required|in:web,app|distinct',
            'revision' => $id ? 'required|integer|min:1' : 'prohibited'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, 'menus.write');
            $this->ensureStatuses($tenant);
            $old = $id ? $this->query($tenant)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            $this->revision($old, $data['revision'] ?? 0);
            if ($old?->status === 'archived') {
                throw ValidationException::withMessages(['status' => trans('clubhouse.restore_first')]);
            }
            $values = collect($data)->except(['revision', 'channels'])->all() + [
                'channels' => json_encode(array_values($data['channels']), JSON_THROW_ON_ERROR),
                'status' => 'draft', 'revision' => (int) ($old->revision ?? 0) + 1, 'updated_at' => now(),
            ];
            // The public snapshot is deliberately not part of the editable working copy.
            if ($old) {
                $this->query($tenant)->where('id', $id)->update($values);
            } else {
                $id = $this->query($tenant)->insertGetId($values + ['tenant_id' => $tenant->id, 'created_at' => now()]);
            }
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'menu', $id, 'saved', ['revision' => $values['revision']]);

            return (int) $id;
        });
    }

    public function upload(Account $actor, Tenant $tenant, int $id, int $revision, UploadedFile $file): array
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'menus.write');
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf|mimetypes:application/pdf|max:10240'])->validate();
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['file' => trans('clubhouse.invalid_pdf')]);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $bytes) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, 'menus.write');
            $menu = $this->query($tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->revision($menu, $revision);
            abort_if($menu->status === 'archived', 422);
            app(DocumentSafety::class)->assertClean($bytes);
            $storage = app(TenantDocumentStorage::class);
            $folder = $storage->folder($tenant, 'clubhouse', $id);
            $filename = Str::uuid().'.pdf';
            $storage->put($tenant, $folder, $filename, $bytes, 'application/pdf');
            $document = ['folder' => $folder, 'filename' => $filename, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            $this->query($tenant)->where('id', $id)->update(['document' => json_encode($document, JSON_THROW_ON_ERROR),
                'status' => 'draft', 'revision' => $revision + 1, 'updated_at' => now()]);
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'menu', $id, 'uploaded', ['size' => strlen($bytes), 'sha256' => $document['sha256']]);

            return $this->row($this->query($tenant)->where('id', $id)->firstOrFail());
        });
    }

    public function removeDocument(Account $actor, Tenant $tenant, int $id, int $revision): array
    {
        return $this->mutate($actor, $tenant, $id, $revision, 'menus.write', 'document_removed',
            fn ($row) => ['document' => null, 'status' => 'draft']);
    }

    public function transition(Account $actor, Tenant $tenant, int $id, int $revision, string $operation): array
    {
        abort_unless(in_array($operation, ['publish', 'withdraw', 'archive', 'restore'], true), 422);
        $ability = in_array($operation, ['publish', 'withdraw'], true) ? 'menus.publish' : 'menus.write';

        return $this->mutate($actor, $tenant, $id, $revision, $ability, $operation, function ($row) use ($actor, $tenant, $operation) {
            if ($operation === 'publish') {
                abort_if($row->status === 'archived', 422);
                $channels = $this->decode($row->channels);
                if ($channels === [] || (! trim((string) $row->body_de) && ! trim((string) $row->body_en) && ! $row->document)) {
                    throw ValidationException::withMessages(['body_de' => trans('clubhouse.content_required')]);
                }
                $document = $this->decode($row->document);
                if ($document) {
                    // Recheck existing documents, including files uploaded before mandatory scanning.
                    app(DocumentSafety::class)->assertClean($this->documentBytes($tenant, $row->id, $document));
                }
                $snapshot = collect((array) $row)->only(['title_de', 'title_en', 'body_de', 'body_en', 'valid_from', 'valid_until'])->all()
                    + ['channels' => $channels, 'document' => $document];

                return ['published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'published_at' => now(), 'status' => 'published'];
            }
            if ($operation === 'archive' && $row->published_snapshot !== null) {
                app(ClubhouseAccess::class)->authorize($actor, $tenant, 'menus.publish');
            }
            if ($operation === 'restore') {
                abort_unless($row->status === 'archived', 422);

                return ['status' => 'draft', 'published_snapshot' => null, 'published_at' => null];
            }

            return ['status' => $operation === 'archive' ? 'archived' : 'draft', 'published_snapshot' => null, 'published_at' => null];
        });
    }

    private function mutate(Account $actor, Tenant $tenant, int $id, int $revision, string $ability, string $operation, callable $change): array
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $ability, $operation, $change) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, $ability);
            $row = $this->query($tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->revision($row, $revision);
            $values = $change($row);
            $this->query($tenant)->where('id', $id)->update($values + ['revision' => $revision + 1, 'updated_at' => now()]);
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'menu', $id, $operation, ['revision' => $revision + 1]);

            return $this->row($this->query($tenant)->where('id', $id)->firstOrFail());
        });
    }

    private function revision(?object $row, int $revision): void
    {
        if ((int) ($row->revision ?? 0) !== $revision) {
            throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
        }
    }

    public function ensureStatuses(Tenant $tenant): void
    {
        foreach (['draft' => 'gray', 'published' => 'success', 'archived' => 'warning'] as $code => $color) {
            DB::connection('clubunio')->table('clubora.clubhouse_menu_statuses')->insertOrIgnore(['tenant_id' => $tenant->id,
                'code' => $code, 'label_key' => 'clubhouse.status_'.$code, 'color' => $color, 'sort_order' => array_search($code, ['draft', 'published', 'archived']) * 10]);
        }
    }

    public function published(Tenant $tenant, string $channel): array
    {
        abort_unless(in_array($channel, ['web', 'app'], true), 422);
        $date = now(app(ClubhouseHours::class)->timezone($tenant))->toDateString();
        $rows = $this->query($tenant)->whereNotNull('published_snapshot')
            ->where('published_snapshot->valid_from', '<=', $date)->where('published_snapshot->valid_until', '>=', $date)
            ->whereJsonContains('published_snapshot->channels', $channel)
            ->orderByDesc('published_at')->limit(501)->get(['id', 'published_snapshot']);
        abort_if($rows->count() > 500, 503);
        $result = [];
        foreach ($rows as $row) {
            $snapshot = $this->decode($row->published_snapshot);
            if (! in_array($channel, $snapshot['channels'], true) || $date < $snapshot['valid_from'] || $date > $snapshot['valid_until']) {
                continue;
            }
            $locale = app()->getLocale() === 'en' ? 'en' : 'de';
            $other = $locale === 'en' ? 'de' : 'en';
            $result[] = ['id' => (int) $row->id, 'title' => $snapshot['title_'.$locale] ?: $snapshot['title_'.$other],
                'body' => $snapshot['body_'.$locale] ?: ($snapshot['body_'.$other] ?? ''),
                'language_fallback' => ! $snapshot['title_'.$locale] || (! $snapshot['body_'.$locale] && (bool) $snapshot['body_'.$other]),
                'valid_from' => $snapshot['valid_from'], 'valid_until' => $snapshot['valid_until'], 'has_document' => $snapshot['document'] !== null];
        }

        return $result;
    }

    public function document(Account $actor, Tenant $tenant, int $id): string
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'view');
        $row = $this->query($tenant)->where('id', $id)->firstOrFail();
        $document = $this->decode($row->document);
        abort_unless($document, 404);

        return $this->documentBytes($tenant, $id, $document);
    }

    public function publishedDocument(Tenant $tenant, int $id, string $channel): string
    {
        abort_unless(collect($this->published($tenant, $channel))->contains('id', $id), 404);
        $row = $this->query($tenant)->where('id', $id)->firstOrFail();
        $document = $this->decode($row->published_snapshot)['document'];
        abort_unless($document, 404);

        return $this->documentBytes($tenant, $id, $document);
    }

    private function documentBytes(Tenant $tenant, int $id, array $document): string
    {
        $folder = $document['folder'] ?? '';
        $path = TenantDocumentStorage::parse($folder);
        $owned = $path ? $path['namespace'] === 'clubhouse' && $path['tenant'] === (string) $tenant->id && $path['object'] === (string) $id
            : $folder === 'clubunio/clubhouse/'.$tenant->id.'/'.$id.'/';
        abort_unless($owned && TenantDocumentStorage::filename($document['filename'] ?? '') && str_ends_with($document['filename'], '.pdf'), 404);
        $bytes = app(TenantDocumentStorage::class)->read($tenant, $folder, $document['filename']);
        abort_unless(str_starts_with($bytes, '%PDF-') && hash_equals($document['sha256'], hash('sha256', $bytes)), 503);

        return $bytes;
    }
}
