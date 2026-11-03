<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, ExpenseLine, Tenant};
use App\ClubUnio\Services\{DocumentUploadService, ExpenseService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Validator};
use Illuminate\Support\Str;

final class MobileExpenseReceipts
{
    public function table(Tenant $tenant) { return DB::connection('clubunio')->table('clubora.mobile_expense_receipts')->where('tenant_id', $tenant->id); }
    public function owned(Account $actor, Tenant $tenant, string $id): object {
        abort_unless(app(ExpenseService::class)->portalAllowed($actor, $tenant), 403);
        return $this->table($tenant)->where('account_id', $actor->id)->where('id', $id)->firstOrFail();
    }
    public function expire(Tenant $tenant): int {
        return $this->table($tenant)->where('state', 'clean')->where('expires_at', '<=', now()->toIso8601String())
            ->update(['state' => 'expired', 'bytes_encrypted' => null, 'revision' => DB::connection('clubunio')->raw('revision + 1')]);
    }
    public function stage(Account $actor, Tenant $tenant, string $clientId, UploadedFile $file): array {
        abort_unless(config('clubunio-mobile.uploads_enabled') && $tenant->slug === config('clubunio-integrations.azure.tenant_slug'), 503);
        abort_unless(app(ExpenseService::class)->portalAllowed($actor, $tenant), 403);
        Validator::make(['client_id' => $clientId, 'file' => $file], ['client_id' => 'required|uuid',
            'file' => 'required|file|max:5120|mimetypes:application/pdf,image/jpeg,image/png'])->validate();
        $bytes = $file->get(); $size = strlen($bytes);
        abort_unless($size > 0 && $size <= 5242880, 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        abort_unless($ext, 422);
        if ($mime === 'application/pdf') { abort_unless(str_starts_with($bytes, '%PDF-') && str_contains(substr($bytes, -2048), '%%EOF'), 422); }
        else { $image = @getimagesizefromstring($bytes); abort_unless($image && $image[0] <= 10000 && $image[1] <= 10000 && $image[0] * $image[1] <= 25000000, 422); }
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 120);
        $name = preg_replace('/\.[^.]*$/u', '', $name).'.'.$ext;
        $sha = hash('sha256', $bytes);
        $this->expire($tenant);
        $old = $this->table($tenant)->where('account_id', $actor->id)->where('client_id', $clientId)->first();
        if ($old) {
            abort_unless(hash_equals($old->sha256, $sha) && $old->mime_type === $mime && Crypt::decryptString($old->filename_encrypted) === $name, 409);
            abort_unless(in_array($old->state, ['clean','bound'], true), 409, 'Belegentwurf abgelaufen oder verworfen.');
            return $this->metadata($old);
        }
        $held = $this->table($tenant)->where('state', 'clean');
        abort_if((clone $held)->count() >= 100 || (int) (clone $held)->sum('byte_count') + $size > 104857600, 429);
        $verdict = app(MalwareScanner::class)->scan($bytes);
        abort_unless(isset($verdict['clean'], $verdict['version']) && is_bool($verdict['clean']) && is_string($verdict['version']) && $verdict['version'] !== '', 503);
        abort_unless($verdict['clean'], 422, 'Belegdatei wurde abgelehnt.');
        $id = (string) Str::uuid();
        $this->table($tenant)->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'client_id' => $clientId,
            'filename_encrypted' => Crypt::encryptString($name), 'bytes_encrypted' => Crypt::encryptString($bytes), 'sha256' => $sha,
            'byte_count' => $size, 'mime_type' => $mime, 'state' => 'clean', 'scanner_version' => mb_substr($verdict['version'], 0, 160),
            'scanned_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(24)->toIso8601String()]);
        return $this->metadata($this->owned($actor, $tenant, $id));
    }
    public function metadata(object $row): array {
        return ['receiptToken' => $row->id, 'revision' => (int) $row->revision, 'state' => $row->state,
            'fileName' => Crypt::decryptString($row->filename_encrypted), 'size' => (int) $row->byte_count, 'contentType' => $row->mime_type];
    }
    public function fingerprint(array $input): string { ksort($input); return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)); }
    public function replay(Account $actor, Tenant $tenant, string $id, string $fingerprint): ?int {
        $row = $this->owned($actor, $tenant, $id);
        if ($row->state !== 'bound') { return null; }
        abort_unless(hash_equals($row->binding_fingerprint, $fingerprint), 409, 'Dieser Beleg ist bereits anders zugeordnet.');
        $line = ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->findOrFail($row->bound_line_id);
        abort_unless(app(ExpenseService::class)->visible($actor, $tenant)->whereKey($line->expense_id)->exists(), 404);
        return (int) $line->id;
    }
    public function bind(Account $actor, Tenant $tenant, string $id, int $revision, ExpenseLine $line, string $fingerprint): int {
        $row = $this->owned($actor, $tenant, $id);
        abort_unless($row->state === 'clean' && $row->bytes_encrypted && (int) $row->revision === $revision && now()->isBefore($row->expires_at), 409, 'Belegentwurf ist nicht mehr verfuegbar.');
        $bytes = Crypt::decryptString($row->bytes_encrypted);
        abort_unless(strlen($bytes) === (int) $row->byte_count && hash_equals($row->sha256, hash('sha256', $bytes)), 409);
        $temp = tempnam(sys_get_temp_dir(), 'cuexp');
        abort_unless($temp, 503); chmod($temp, 0600);
        try {
            abort_unless(file_put_contents($temp, $bytes) === strlen($bytes), 503);
            $upload = app(DocumentUploadService::class)->stage($actor, $tenant, 'expense', (int) $line->id,
                ['revision' => (int) $line->revision, 'expense_revision' => (int) $line->expense->revision],
                new UploadedFile($temp, Crypt::decryptString($row->filename_encrypted), $row->mime_type, null, true));
        } finally { unlink($temp); }
        $this->table($tenant)->where('id', $id)->update(['state' => 'bound', 'bytes_encrypted' => null, 'bound_line_id' => $line->id,
            'bound_upload_id' => $upload, 'binding_fingerprint' => $fingerprint, 'revision' => $row->revision + 1]);
        return $upload;
    }
    public function discard(Account $actor, Tenant $tenant, string $id): void {
        $row = $this->owned($actor, $tenant, $id);
        abort_if($row->state === 'bound', 409, 'Beleg ist bereits einer Position zugeordnet.');
        if (in_array($row->state, ['discarded','expired'], true)) { return; }
        $this->table($tenant)->where('id', $id)->update(['state' => 'discarded', 'bytes_encrypted' => null, 'revision' => $row->revision + 1]);
    }
}
