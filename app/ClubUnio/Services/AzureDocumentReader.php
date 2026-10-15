<?php

namespace App\ClubUnio\Services;

use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Sas\SasProtocol;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class AzureDocumentReader
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private ?ClientInterface $client = null) {}

    public function blobPath(string $folder, string $filename): string
    {
        $prefix = match ($folder) {
            'azure.blob://files/' => 'spesen/',
            'upload/anmeldungen/' => 'upload/anmeldungen/',
            'upload/mitglieder/anhaenge/' => 'upload/mitglieder/anhaenge/',
            'var/member-documents/' => 'member-documents/',
            default => preg_match('~^clubunio/(registrations|members)/[1-9][0-9]*/[1-9][0-9]*/$~D', $folder) ? $folder : throw new \InvalidArgumentException('Unsupported document folder'),
        };
        if ($filename === '' || $filename === '.' || $filename === '..' || preg_match('~[/\\\\:\x00-\x1f\x7f]~', $filename)) {
            throw new \InvalidArgumentException('Invalid document filename');
        }

        return $prefix.$filename;
    }

    public function read(string $folder, string $filename): string
    {
        $path = $this->blobPath($folder, $filename);
        $config = config('clubunio-integrations.azure');
        $container = $config['container'];
        if (! $config['enabled'] || ! preg_match('/^[a-z0-9]{3,24}$/', $config['account']) || ! $config['key']) {
            throw new \RuntimeException('Document storage disabled');
        }
        // SDK-generated, read-only SAS stays server-side. A single bounded GET, no retry loop.
        $sas = BlobSasBuilder::new()->setContainerName($container)->setBlobName($path)
            ->setPermissions('r')->setProtocol(SasProtocol::HTTPS)->setExpiresOn(now()->addMinutes(2))
            ->build(new StorageSharedKeyCredential($config['account'], $config['key']));
        $url = 'https://'.$config['account'].'.blob.core.windows.net/'.$container.'/'
            .implode('/', array_map('rawurlencode', explode('/', $path))).'?'.$sas;
        $body = null;
        try {
            $response = ($this->client ?? new Client)->request('GET', $url, [
                'timeout' => 15, 'connect_timeout' => 5, 'read_timeout' => 15,
                'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            ]);
            $body = $response->getBody();
            if ($response->getStatusCode() === 404) {
                throw new \RuntimeException('Document not found', 404);
            }
            if ($response->getStatusCode() !== 200 || (int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                throw new \RuntimeException('Document read rejected');
            }
            $bytes = '';
            $deadline = microtime(true) + 20;
            while (! $body->eof()) {
                $bytes .= $body->read(65536);
                if (strlen($bytes) > self::MAX_BYTES || microtime(true) > $deadline) {
                    throw new \RuntimeException('Document limit exceeded');
                }
            }

            return $bytes;
        } catch (\Throwable $error) {
            // Never propagate a signed URL or Azure response into logs or an error page.
            throw new \RuntimeException('Document storage unavailable', $error->getCode() === 404 ? 404 : 503);
        } finally {
            $body?->close();
        }
    }
}
