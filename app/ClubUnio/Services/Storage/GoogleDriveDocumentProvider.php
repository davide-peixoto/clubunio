<?php

namespace App\ClubUnio\Services\Storage;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

class GoogleDriveDocumentProvider extends DriveDocumentProvider
{
    private const DRIVE = 'https://www.googleapis.com/drive/v3/';

    private function session(array $credentials): string
    {
        $auth = new ServiceAccountCredentials('https://www.googleapis.com/auth/drive', $credentials);
        $token = $auth->fetchAuthToken(fn (RequestInterface $request) => $this->http()
            ->withHeaders($request->getHeaders())->withBody((string) $request->getBody(), 'application/x-www-form-urlencoded')
            ->post('https://oauth2.googleapis.com/token')->toPsrResponse())['access_token'] ?? null;
        if (! is_string($token) || ! preg_match('/^[!-~]+$/D', $token)) {
            throw ProviderBytes::unavailable();
        }

        return $token;
    }

    private function request(string $token): PendingRequest
    {
        return $this->http()->withToken($token);
    }

    private function privateDrive(object $profile, string $token): void
    {
        $drive = $this->expect($this->request($token)->get(self::DRIVE.'drives/'.rawurlencode($profile->container), [
            'fields' => 'id,restrictions(driveMembersOnly),capabilities(canAddChildren)',
        ]), 200);
        if ($drive->json('id') !== $profile->container || $drive->json('restrictions.driveMembersOnly') !== true
            || $drive->json('capabilities.canAddChildren') !== true) {
            throw ProviderBytes::unavailable();
        }
    }

    public function check(object $profile, array $credentials): void
    {
        $this->privateDrive($profile, $this->session($credentials));
    }

    public function read(object $profile, array $credentials, string $key): string
    {
        return $this->download($profile, $key, $this->session($credentials));
    }

    private function download(object $profile, string $key, string $token): string
    {
        $id = $this->index->id($profile, $key) ?? throw ProviderBytes::unavailable(404);
        $url = self::DRIVE.'files/'.rawurlencode($id);
        $metadata = $this->request($token)->get($url, ['fields' => 'id,driveId,name,trashed', 'supportsAllDrives' => 'true']);
        if ($metadata->status() === 404) {
            throw ProviderBytes::unavailable(404);
        }
        $this->expect($metadata, 200);
        if ($metadata->json('id') !== $id || $metadata->json('driveId') !== $profile->container || $metadata->json('trashed') === true) {
            throw ProviderBytes::unavailable();
        }
        $content = $this->expect($this->request($token)->withOptions(['stream' => true])->get($url, [
            'alt' => 'media', 'supportsAllDrives' => 'true',
        ]), 200);

        return ProviderBytes::read($content->toPsrResponse()->getBody());
    }

    public function put(object $profile, array $credentials, string $key, string $bytes, string $mime): void
    {
        $token = $this->session($credentials);
        $this->privateDrive($profile, $token);
        $id = $this->index->id($profile, $key);
        if ($id === null) {
            $generated = $this->expect($this->request($token)->get(self::DRIVE.'files/generateIds', ['count' => 1, 'space' => 'drive']), 200);
            // Commit the ID before sending bytes so timeouts and concurrent retries use the same object.
            $id = $this->index->remember($profile, $key, $generated->json('ids.0'));
        }
        $response = $this->request($token)->withHeaders(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) strlen($bytes)])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,driveId', [
                'id' => $id, 'name' => $this->index->filename($key), 'parents' => [$profile->container],
            ]);
        if ($response->status() === 200) {
            $url = $response->header('Location');
            if (! preg_match('~^https://www\.googleapis\.com/upload/drive/v3/files\?[\x21-\x7e]+$~D', $url)) {
                throw ProviderBytes::unavailable();
            }
            $response = $this->request($token)->withBody($bytes, $mime)->put($url);
        }
        if ($response->status() === 409) {
            ProviderBytes::requireSame($this->download($profile, $key, $token), $bytes);

            return;
        }
        $this->expect($response, 200, 201);
        if ($response->json('id') !== $id || $response->json('driveId') !== $profile->container) {
            throw ProviderBytes::unavailable();
        }
    }
}
