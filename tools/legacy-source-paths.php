<?php

// CLI migration inputs stay outside the standalone ClubUnio checkout.
function clubunioLegacyBackendPath(string $relative): string
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':')
        || str_contains($relative, "\0") || in_array('..', explode('/', $relative), true)) {
        throw new InvalidArgumentException('Expected a relative legacy input path');
    }
    $override = getenv('CLUBUNIO_LEGACY_BACKEND_ROOT');
    $root = $override !== false && $override !== '' ? $override
        : (PHP_OS_FAMILY === 'Windows' ? 'C:/Projects/SVS/backend' : dirname(__DIR__, 2));

    return rtrim(str_replace('\\', '/', $root), '/').'/'.$relative;
}
