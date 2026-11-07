<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ManagedDocumentDisk
{
    private function path(string $key): string
    {
        if (! TenantDocumentStorage::parse(dirname($key).'/') || ! TenantDocumentStorage::filename(basename($key))) {
            throw new RuntimeException('Invalid storage reference', 404);
        }

        return Storage::disk('local')->path('managed-documents/'.$key);
    }

    public function read(string $key): string
    {
        $path = $this->path($key);
        if (! is_file($path)) {
            throw new RuntimeException('Document not found', 404);
        }
        $bytes = @file_get_contents($path, false, null, 0, AzureDocumentReader::MAX_BYTES + 1);
        if ($bytes === false || $bytes === '' || strlen($bytes) > AzureDocumentReader::MAX_BYTES) {
            throw new RuntimeException('Document storage unavailable', 503);
        }

        return $bytes;
    }

    public function put(string $key, string $bytes): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if ($bytes === '' || strlen($bytes) > AzureDocumentReader::MAX_BYTES
            || (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir))) {
            throw new RuntimeException('Document storage unavailable', 503);
        }
        $tmp = @tempnam($dir, '.pending-');
        if ($tmp === false) {
            throw new RuntimeException('Document storage unavailable', 503);
        }
        try {
            if (realpath(dirname($tmp)) !== realpath($dir) || @file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Document storage unavailable', 503);
            }
            // Publish complete bytes without replacing an existing destination, even across workers.
            if (! @link($tmp, $path) && (! is_file($path) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $this->read($key))))) {
                throw new RuntimeException('Document storage unavailable', 503);
            }
        } finally {
            @unlink($tmp);
        }
    }
}
