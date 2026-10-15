<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BexioAttachmentService
{
    public const MAX_FILES = 10;

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['dispatch' => $message]);
    }

    // Called after document authorization. Never accept paths supplied by the browser.
    public function plan(Tenant $tenant, Collection $lines): array
    {
        $plan = [];
        $files = [];
        if (app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'expense')->where('state', 'pending')->whereIn('object_id', $lines->pluck('id'))->exists()) {
            $this->invalid('Ein Belegupload ist noch offen. Bitte Synchronisation zuerst abschliessen.');
        }
        foreach ($lines as $line) {
            $type = $line->expenseType;
            if ($line->tenant_id != $tenant->id || ! $type || $type->tenant_id != $tenant->id || ! $type->active) {
                $this->invalid('Ungültige Spesenposition für die Belegprüfung.');
            }
            $names = array_values(array_filter(array_map('trim', explode(',', (string) $line->storage_reference)), fn ($name) => $name !== ''));
            if ($type->receipt_required && $names === []) {
                $this->invalid('Ein Pflichtbeleg fehlt bei Position #'.$line->id.'.');
            }
            // A display filename without a storage reference must not be silently omitted.
            if ($names === [] && (trim((string) $line->filename) !== '' || trim((string) $line->storage_reference) !== '')) {
                $this->invalid('Die Speicherzuordnung des Belegs fehlt bei Position #'.$line->id.'.');
            }
            foreach ($names as $name) {
                if (str_starts_with($name, 'clubunio/') && ! str_starts_with($name, 'clubunio/expenses/'.$tenant->id.'/'.$line->id.'/')) {
                    $this->invalid('Belegreferenz gehört nicht zu dieser Position.');
                }
                try {
                    app(AzureDocumentReader::class)->blobPath('azure.blob://files/', $name);
                } catch (\Throwable) {
                    $this->invalid('Ungültige Belegreferenz bei Position #'.$line->id.'.');
                }
                $files[$name] = true;
                if (count($files) > self::MAX_FILES) {
                    $this->invalid('Maximal 10 unterschiedliche Belege pro Versandprüfung.');
                }
            }
            $plan[] = ['line_id' => $line->id, 'type_id' => $type->id, 'required' => (bool) $type->receipt_required, 'files' => $names];
        }

        return ['source_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), 'names' => array_keys($files)];
    }

    public function prepare(Tenant $tenant, int $expenseId, array $plan): array
    {
        if ($plan['names'] !== [] && $tenant->slug !== config('clubunio-integrations.azure.tenant_slug')) {
            abort(404);
        }
        $manifest = [];
        $total = 0;
        $deadline = microtime(true) + 20;
        foreach ($plan['names'] as $name) {
            if (microtime(true) > $deadline) {
                $this->invalid('Zeitlimit der Belegprüfung erreicht. Bitte später erneut prüfen.');
            }
            try {
                $bytes = app(AzureDocumentReader::class)->read('azure.blob://files/', $name);
            } catch (\Throwable $error) {
                $reference = (string) Str::uuid();
                Log::warning('clubunio.bexio.attachment_read_failed', ['reference' => $reference, 'tenant_id' => $tenant->id,
                    'expense_id' => $expenseId, 'code' => $error->getCode() === 404 ? 404 : 503]);
                $this->invalid('Spesenbeleg nicht verfügbar. Referenz: '.$reference);
            }
            if (microtime(true) > $deadline) {
                unset($bytes);
                $this->invalid('Zeitlimit der Belegprüfung erreicht. Bitte später erneut prüfen.');
            }
            $size = strlen($bytes);
            $total += $size;
            if ($size === 0 || $size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                $this->invalid('Beleg leer oder Größenlimit überschritten (10 MiB pro Datei, 20 MiB gesamt).');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = match ($mime) {
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => null,
            };
            if (! $extension) {
                $this->invalid('Für Bexio-Belege werden PDF, JPEG oder PNG benötigt.');
            }
            $manifest[] = ['source' => $name, 'filename' => 'Spesen-'.$expenseId.'-'.(count($manifest) + 1).'.'.$extension,
                'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $bytes)];
            unset($bytes);
        }

        return ['source_hash' => $plan['source_hash'], 'files' => $manifest, 'bytes' => $total];
    }
}
