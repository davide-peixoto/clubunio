<?php

namespace App\ClubUnio\Services;

use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Sas\AccountSasBuilder;
use AzureOss\Storage\Common\Sas\SasProtocol;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class RegistrationBlobStore
{
    public function __construct(private ?ClientInterface $client = null) {}

    public function put(string $folder, string $filename, string $bytes, string $mime): void
    {
        $this->putAt($folder, $filename, $bytes, $mime, null);
    }

    public function checkAt(array $destination): void
    {
        try {
            $client = $this->client ?? new Client;
            $credential = new StorageSharedKeyCredential($destination['account'], $destination['key']);
            $this->privateContainer($client, $credential, $this->base($destination));
        } catch (\Throwable) {
            throw new \RuntimeException('Private document upload unavailable');
        }
    }

    private function base(array $config): string
    {
        return 'https://'.$config['account'].'.blob.core.windows.net/'.$config['container'];
    }

    private function privateContainer(ClientInterface $client, StorageSharedKeyCredential $credential, string $base): void
    {
        $probe = AccountSasBuilder::new()->setServices('b')->setResourceTypes('c')->setPermissions('r')
            ->setProtocol(SasProtocol::HTTPS)->setExpiresOn(now()->addMinutes(2))->build($credential);
        $properties = $client->request('HEAD', $base.'?restype=container&'.$probe, [
            'headers' => ['x-ms-version' => '2023-11-03'], 'timeout' => 8, 'connect_timeout' => 5,
            'allow_redirects' => false, 'http_errors' => false]);
        if ($properties->getStatusCode() !== 200 || $properties->hasHeader('x-ms-blob-public-access')) {
            throw new \RuntimeException('Container is not verified private');
        }
    }

    public function putAt(string $folder, string $filename, string $bytes, string $mime, ?array $destination): void
    {
        if (! ($destination ? TenantDocumentStorage::parse($folder) : preg_match('~^clubunio/(registrations|members|expenses)/[1-9][0-9]*/[1-9][0-9]*/$~D', $folder))
            || ! preg_match('/^[a-f0-9-]{36}\.(pdf|png|jpg|webp)$/D', $filename)
            || strlen($bytes) === 0 || strlen($bytes) > AzureDocumentReader::MAX_BYTES) {
            throw new \InvalidArgumentException('Invalid registration document');
        }
        $config = $destination ?? config('clubunio-integrations.azure');
        $validContainer = $destination ? preg_match('/^[a-z0-9-]{3,63}$/D', $config['container']) : $config['container'] === 'svs-prod-private';
        if (! $config['enabled'] || ! $validContainer || ! preg_match('/^[a-z0-9]{3,24}$/D', $config['account']) || ! $config['key']) {
            throw new \RuntimeException('Private document storage disabled');
        }
        try {
            $client = $this->client ?? new Client;
            $credential = new StorageSharedKeyCredential($config['account'], $config['key']);
            $base = $this->base($config);
            if ($destination) {
                $this->privateContainer($client, $credential, $base);
            }
            $path = $folder.$filename;
            $sas = BlobSasBuilder::new()->setContainerName($config['container'])->setBlobName($path)
                ->setPermissions('c')->setProtocol(SasProtocol::HTTPS)->setExpiresOn(now()->addMinutes(2))->build($credential);
            $response = $client->request('PUT', $base.'/'.$path.'?'.$sas, ['body' => $bytes,
                'headers' => ['x-ms-blob-type' => 'BlockBlob', 'If-None-Match' => '*', 'Content-Type' => $mime, 'Content-MD5' => base64_encode(md5($bytes, true))],
                'timeout' => 25, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false]);
            $md5 = $response->getHeaderLine('Content-MD5');
            if ($response->getStatusCode() !== 201 || ($md5 !== '' && ! hash_equals(base64_encode(md5($bytes, true)), $md5))) {
                throw new \RuntimeException('Upload rejected');
            }
        } catch (\Throwable) {
            // Signed URLs and provider bodies must never escape into logs or UI.
            throw new \RuntimeException('Private document upload unavailable');
        }
    }
}
