<?php

namespace App\ClubUnio\Services\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Throwable;

class S3DocumentProvider implements DocumentProvider
{
    protected function client(object $profile, array $credentials): S3Client
    {
        [$region] = explode(':', $profile->account, 2);

        return new S3Client(['version' => 'latest', 'region' => $region, 'credentials' => [
            'key' => $credentials['access_key_id'], 'secret' => $credentials['secret_access_key'],
        ], 'retries' => 0, 'http' => ['connect_timeout' => 4, 'timeout' => 20, 'read_timeout' => 5, 'allow_redirects' => false]]);
    }

    private function target(object $profile): array
    {
        return ['Bucket' => $profile->container, 'ExpectedBucketOwner' => explode(':', $profile->account, 2)[1]];
    }

    private function privateBucket(S3Client $client, array $target): void
    {
        $public = $client->getPublicAccessBlock($target)['PublicAccessBlockConfiguration'] ?? [];
        foreach (['BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets'] as $rule) {
            if (($public[$rule] ?? null) !== true) {
                throw ProviderBytes::unavailable();
            }
        }
        try {
            if (($client->getBucketPolicyStatus($target)['PolicyStatus']['IsPublic'] ?? null) !== false) {
                throw ProviderBytes::unavailable();
            }
        } catch (AwsException $error) {
            if ($error->getAwsErrorCode() !== 'NoSuchBucketPolicy') {
                throw $error;
            }
        }
    }

    public function check(object $profile, array $credentials): void
    {
        $this->privateBucket($this->client($profile, $credentials), $this->target($profile));
    }

    public function read(object $profile, array $credentials, string $key): string
    {
        $client = $this->client($profile, $credentials);
        $disk = new Filesystem(new AwsS3V3Adapter($client, $profile->container, options: $this->target($profile)));
        try {
            return ProviderBytes::read(Utils::streamFor($disk->readStream($key)));
        } catch (Throwable $error) {
            $cause = $error->getPrevious();
            throw ProviderBytes::unavailable($cause instanceof AwsException && $cause->getAwsErrorCode() === 'NoSuchKey' ? 404 : 503);
        }
    }

    public function put(object $profile, array $credentials, string $key, string $bytes, string $mime): void
    {
        $client = $this->client($profile, $credentials);
        $target = $this->target($profile);
        $this->privateBucket($client, $target);
        try {
            $client->putObject($target + ['Key' => $key, 'Body' => $bytes, 'ContentType' => $mime, 'IfNoneMatch' => '*']);
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 412) {
                throw $error;
            }
            ProviderBytes::requireSame($this->read($profile, $credentials, $key), $bytes);
        }
    }
}
