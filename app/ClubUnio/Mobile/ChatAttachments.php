<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ChatAttachments
{
    private function service(): ChatService { return app(ChatService::class); }
    private function enabled(): void { abort_unless(config('clubunio-mobile.uploads_enabled'), 503); }

    public function stage(Account $actor, Tenant $tenant, int $chat, string $clientId, UploadedFile $file): array
    {
        $this->enabled(); $s = $this->service(); $s->chat($actor, $tenant, $chat, true);
        Validator::make(['client_id' => $clientId, 'file' => $file],
            ['client_id' => 'required|uuid', 'file' => 'required|file|max:5120|mimetypes:application/pdf,image/jpeg,image/png,image/webp'])->validate();
        $bytes = $file->get(); $size = strlen($bytes);
        abort_unless($size > 0 && $size <= 5242880, 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        abort_unless($ext, 422);
        if ($mime === 'application/pdf') {
            abort_unless(str_starts_with($bytes, '%PDF-') && str_contains(substr($bytes, -2048), '%%EOF'), 422);
        } else {
            $image = @getimagesizefromstring($bytes);
            abort_unless($image && $image[0] <= 10000 && $image[1] <= 10000 && $image[0] * $image[1] <= 25000000, 422);
        }
        $sha = hash('sha256', $bytes);
        $old = $s->table($tenant, 'chat_attachments')->where('account_id', $actor->id)->where('client_id', $clientId)->first();
        if ($old) {
            abort_unless((int) $old->conversation_id === $chat && hash_equals($old->sha256, $sha), 409);
            return ['id' => $old->id, 'state' => $old->state];
        }
        $held = $s->table($tenant, 'chat_attachments')->whereNotNull('bytes_encrypted');
        abort_if((clone $held)->count() >= 100 || (int) (clone $held)->sum('byte_count') + $size > 104857600, 429);
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name), 0, 120);
        $name = preg_replace('/\.[^.]*$/u', '', $name).'.'.$ext;
        $id = (string) Str::uuid();
        $s->table($tenant, 'chat_attachments')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'conversation_id' => $chat,
            'account_id' => $actor->id, 'client_id' => $clientId, 'filename_encrypted' => Crypt::encryptString($name),
            'bytes_encrypted' => Crypt::encryptString($bytes), 'sha256' => $sha, 'byte_count' => $size, 'mime_type' => $mime,
            'state' => 'quarantine', 'created_at' => now()->toIso8601String()]);
        return ['id' => $id, 'state' => 'quarantine'];
    }

    public function owned(Account $actor, Tenant $tenant, string $id): object
    {
        $this->enabled(); $s = $this->service();
        $row = $s->table($tenant, 'chat_attachments')->where('id', $id)->where('account_id', $actor->id)->firstOrFail();
        $s->chat($actor, $tenant, (int) $row->conversation_id, true);
        return $row;
    }

    public function scanBytes(object $row): array
    {
        abort_unless($row->state === 'quarantine' && $row->bytes_encrypted, 409);
        $bytes = Crypt::decryptString($row->bytes_encrypted);
        abort_unless(strlen($bytes) === (int) $row->byte_count && hash_equals($row->sha256, hash('sha256', $bytes)), 409);
        return app(MalwareScanner::class)->scan($bytes);
    }

    public function finishScan(Account $actor, Tenant $tenant, string $id, array $verdict): array
    {
        $row = $this->owned($actor, $tenant, $id);
        if ($row->state !== 'quarantine') { return ['id' => $id, 'state' => $row->state]; }
        abort_unless(isset($verdict['clean'], $verdict['version']) && is_bool($verdict['clean']), 503);
        $state = $verdict['clean'] ? 'clean' : 'rejected';
        $data = ['state' => $state, 'scanner_version' => mb_substr($verdict['version'], 0, 160), 'scanned_at' => now()->toIso8601String()];
        if (! $verdict['clean']) { $data['bytes_encrypted'] = null; }
        $this->service()->table($tenant, 'chat_attachments')->where('id', $id)->update($data);
        return ['id' => $id, 'state' => $state];
    }

    public function ready(Account $actor, Tenant $tenant, int $chat, string $id): object
    {
        $row = $this->owned($actor, $tenant, $id);
        abort_unless((int) $row->conversation_id === $chat && $row->state === 'clean' && ! $row->message_id && $row->bytes_encrypted, 409);
        return $row;
    }

    public function forMessage(Tenant $tenant, int $message): ?array
    {
        $row = $this->service()->table($tenant, 'chat_attachments')->where('message_id', $message)->where('state', 'clean')->first();
        return $row ? ['id' => $row->id, 'name' => Crypt::decryptString($row->filename_encrypted),
            'mime' => $row->mime_type, 'bytes' => (int) $row->byte_count, 'sha256' => $row->sha256] : null;
    }

    public function download(Account $actor, Tenant $tenant, string $id)
    {
        $this->enabled(); $s = $this->service();
        $row = $s->table($tenant, 'chat_attachments')->where('id', $id)->where('state', 'clean')->whereNotNull('message_id')->firstOrFail();
        $message = app(ChatControls::class)->visible($actor, $tenant, (int) $row->message_id);
        abort_if($message->deleted_at, 404);
        $bytes = Crypt::decryptString($row->bytes_encrypted);
        abort_unless(hash_equals($row->sha256, hash('sha256', $bytes)), 409);
        return response($bytes)->header('Content-Type', $row->mime_type)->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'")
            ->header('Content-Disposition', 'attachment; filename="attachment"')->header('Cache-Control', 'private, no-store');
    }
}
