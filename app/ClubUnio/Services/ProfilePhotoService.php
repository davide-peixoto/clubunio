<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB};

final class ProfilePhotoService
{
    public function enabled(): bool
    {
        return app()->environment('staging') && config('app.url') === 'https://stage.clubunio.com'
            && config('clubunio-profile-photos.enabled') && function_exists('imagecreatefromstring');
    }

    private function target(Account $actor, ?Tenant $bound): ?array
    {
        $links = DB::connection('clubunio')->table('clubunio.account_member_links as l')
            ->join('clubunio.tenants as t', 't.id', '=', 'l.tenant_id')
            ->where('l.account_id', $actor->id)->where('l.kind', 'self')->where('l.state', 'approved')
            ->get(['l.tenant_id', 'l.member_id', 't.slug']);
        if ($links->isEmpty()) return ['account_profile_photos', ['account_id' => $actor->id]];
        $links = $links->filter(fn ($row) => (! $bound || (int) $bound->id === (int) $row->tenant_id)
            && \App\ClubUnio\Mobile\StageMobilePilot::allowsTenant($actor->username, $row->slug))->values();
        if ($links->count() !== 1) return null;
        $link = $links->first();
        $tenant = Tenant::findOrFail($link->tenant_id);
        if (! app(MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($link->member_id)->exists()) return null;

        return ['member_profile_photos', ['tenant_id' => $link->tenant_id, 'member_id' => $link->member_id]];
    }

    private function table(string $name)
    {
        return DB::connection('clubunio')->table('clubunio.'.$name);
    }

    public function canUpload(Account $actor, ?Tenant $bound): bool
    {
        return $this->enabled() && $this->target($actor, $bound) !== null;
    }

    public function personal(Account $actor, ?Tenant $bound): string
    {
        abort_unless($this->enabled() && ($target = $this->target($actor, $bound)), 404);
        $image = $this->table($target[0])->where($target[1])->value('image_encrypted');
        abort_unless($image, 404);

        return Crypt::decryptString($image);
    }

    public function upload(Account $actor, ?Tenant $bound, UploadedFile $file): string
    {
        abort_unless($this->enabled(), 404);
        $bytes = $file->get();
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            && $info[0] <= 2500 && $info[1] <= 2500 && $info[0] * $info[1] <= 4000000, 422);
        $decoded = @imagecreatefromstring($bytes);
        abort_unless($decoded, 422);
        ob_start();
        try {
            abort_unless(imagejpeg($decoded, null, 85), 422);
            $image = Crypt::encryptString(ob_get_contents());
        } finally {
            ob_end_clean();
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $bound, $image) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && ! $fresh->api_only && ($target = $this->target($fresh, $bound)), 403);
            [$table, $key] = $target;
            if ($table === 'account_profile_photos') {
                $this->table($table)->updateOrInsert($key, ['image_encrypted' => $image]);
                return 'saved';
            }
            $row = $this->table($table)->where($key)->lockForUpdate()->first();
            $this->table($table)->updateOrInsert($key, [
                'pending_encrypted' => $image, 'submitted_by' => $actor->id, 'revision' => 1 + ($row->revision ?? 0),
            ]);
            return 'pending';
        });
    }

    public function review(Account $actor, Tenant $tenant, int $memberId, int $revision, bool $approve): void
    {
        abort_unless($this->enabled() && app(MemberCampaignService::class)->allowed($actor, $tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $memberId, $revision, $approve) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(MemberCampaignService::class)->allowed($actor, $tenant), 403);
            $query = $this->table('member_profile_photos')->where(['tenant_id' => $tenant->id, 'member_id' => $memberId]);
            $row = $query->lockForUpdate()->firstOrFail();
            abort_unless($row->pending_encrypted && (int) $row->revision === $revision, 409);
            if ($approve) {
                $submitter = Account::findOrFail($row->submitted_by);
                abort_unless($submitter->active && app(MemberAccessService::class)->represented($submitter, $tenant, true)->whereKey($memberId)->exists(), 409);
            }
            $query->update(['image_encrypted' => $approve ? $row->pending_encrypted : $row->image_encrypted,
                'pending_encrypted' => null, 'submitted_by' => null, 'revision' => $revision + 1]);
            $this->table('object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'member_profile_photo', 'object_id' => $memberId,
                'after_value' => json_encode(['approved' => $approve, 'revision' => $revision + 1], JSON_THROW_ON_ERROR)]);
        });
    }

    public function member(Tenant $tenant, int $memberId): ?object
    {
        return $this->enabled() ? $this->table('member_profile_photos')->where([
            'tenant_id' => $tenant->id, 'member_id' => $memberId,
        ])->whereExists(fn ($q) => $q->selectRaw('1')->from('clubunio.members as member')
            ->where('member.tenant_id', $tenant->id)->where('member.id', $memberId)->whereNull('member.archived_at'))->first() : null;
    }

    public function response(string $image)
    {
        return response($image, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
