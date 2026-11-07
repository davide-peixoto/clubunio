<?php

namespace App\ClubUnio\Services\Storage;

use App\ClubUnio\Services\AzureDocumentReader;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class ProviderBytes
{
    public static function read(StreamInterface $stream): string
    {
        $data = '';
        $deadline = microtime(true) + 15;
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if (($chunk === '' && ! $stream->eof()) || microtime(true) > $deadline || strlen($data) + strlen($chunk) > AzureDocumentReader::MAX_BYTES) {
                    throw self::unavailable();
                }
                $data .= $chunk;
            }
        } finally {
            $stream->close();
        }

        return $data;
    }

    public static function unavailable(int $code = 503): RuntimeException
    {
        return new RuntimeException('Document storage unavailable', $code);
    }

    public static function requireSame(string $existing, string $bytes): void
    {
        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $existing))) {
            throw self::unavailable(409);
        }
    }
}
