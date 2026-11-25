<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MalwareScanner;
use Illuminate\Validation\ValidationException;

final class DocumentSafety
{
    /** An unavailable or inconclusive scanner never authorizes publication. */
    public function assertClean(string $bytes): void
    {
        try {
            $verdict = app(MalwareScanner::class)->scan($bytes);
        } catch (\Throwable) {
            abort(503, trans('document_safety.unavailable'));
        }
        abort_unless(is_bool($verdict['clean'] ?? null)
            && is_string($verdict['version'] ?? null) && trim($verdict['version']) !== '',
            503, trans('document_safety.unavailable'));
        if (! $verdict['clean']) {
            throw ValidationException::withMessages(['file' => trans('document_safety.rejected')]);
        }
    }
}
