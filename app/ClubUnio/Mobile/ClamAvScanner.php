<?php

namespace App\ClubUnio\Mobile;

final class ClamAvScanner implements MalwareScanner
{
    public static function verdict(string $reply, string $version): array
    {
        abort_unless(strlen($reply) <= 1024 && str_ends_with($reply, "\0"), 503);
        $reply = substr($reply, 0, -1);
        if ($reply === 'stream: OK') { return ['clean' => true, 'version' => $version]; }
        if (preg_match('/^stream: [^\x00-\x1f]{1,300} FOUND$/D', $reply)) {
            return ['clean' => false, 'version' => $version];
        }
        abort(503); // Timeout, limit, ERROR or unknown response never means clean.
    }

    public function scan(string $bytes): array
    {
        $path = config('clubunio-mobile.attachments.clamd_socket');
        abort_unless(is_string($path) && preg_match('#^/[a-zA-Z0-9_./-]+$#D', $path), 503);
        $socket = @stream_socket_client('unix://'.$path, $error, $description, 2);
        abort_unless($socket !== false, 503);
        stream_set_timeout($socket, 10);
        $write = function (string $data) use ($socket): void {
            for ($offset = 0; $offset < strlen($data); $offset += $written) {
                $written = @fwrite($socket, substr($data, $offset));
                abort_unless($written !== false && $written > 0, 503);
            }
        };
        try {
            $write("zINSTREAM\0");
            for ($offset = 0; $offset < strlen($bytes); $offset += 16384) {
                $chunk = substr($bytes, $offset, 16384); $write(pack('N', strlen($chunk)).$chunk);
            }
            $write(pack('N', 0));
            $reply = '';
            while (! feof($socket) && strlen($reply) <= 1024 && ! str_contains($reply, "\0")) {
                $part = @fread($socket, 1024);
                abort_unless(is_string($part) && $part !== '' && ! stream_get_meta_data($socket)['timed_out'], 503);
                $reply .= $part;
            }
            return self::verdict($reply, 'clamd-instream-v1');
        } finally { fclose($socket); }
    }
}
