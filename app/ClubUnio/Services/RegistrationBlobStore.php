<?php

namespace App\ClubUnio\Services;

use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Sas\SasProtocol;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class RegistrationBlobStore
{
    public function __construct(private ?ClientInterface $client = null) {}

    public function put(string $folder, string $filename, string $bytes, string $mime): void
    {
        if (! preg_match('~^clubunio/registrations/[1-9][0-9]*/[1-9][0-9]*/$~D', $folder)
            || ! preg_match('/^[a-f0-9-]{36}\.(pdf|png|jpg|webp)$/D', $filename)
            || strlen($bytes) === 0 || strlen($bytes) > AzureDocumentReader::MAX_BYTES) {
            throw new \InvalidArgumentException('Invalid registration document');
        }
        $config = config('clubunio-integrations.azure');
        if (! $config['enabled'] || $config['container'] !== 'svs-prod-private' || ! preg_match('/^[a-z0-9]{3,24}$/D', $config['account']) || ! $config['key']) {
            throw new \RuntimeException('Private document storage disabled');
        }
        $path = $folder.$filename;
        $sas = BlobSasBuilder::new()->setContainerName($config['container'])->setBlobName($path)
            ->setPermissions('c')->setProtocol(SasProtocol::HTTPS)->setExpiresOn(now()->addMinutes(2))
            ->build(new StorageSharedKeyCredential($config['account'], $config['key']));
        $url = 'https://'.$config['account'].'.blob.core.windows.net/'.$config['container'].'/'.$path.'?'.$sas;
        try {
            $response = ($this->client ?? new Client)->request('PUT', $url, ['body' => $bytes,
                'headers' => ['x-ms-blob-type' => 'BlockBlob', 'If-None-Match' => '*', 'Content-Type' => $mime],
                'timeout' => 25, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false]);
            if ($response->getStatusCode() !== 201) {
                throw new \RuntimeException('Upload rejected');
            }
        } catch (\Throwable) {
            // Signed URLs and provider bodies must never escape into logs or UI.
            throw new \RuntimeException('Private document upload unavailable');
        }
    }
}
