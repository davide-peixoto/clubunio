<?php

namespace App\ClubUnio\Services\Storage;

class GraphDocumentProvider extends DriveDocumentProvider
{
    private function session(object $profile, array $credentials): string
    {
        return $this->tokenFrom($this->http()->asForm()->post('https://login.microsoftonline.com/'.$profile->account.'/oauth2/v2.0/token', [
            'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default',
            'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'],
        ]));
    }

    private function drive(object $profile): string
    {
        return 'https://graph.microsoft.com/v1.0/drives/'.rawurlencode($profile->container);
    }

    private function itemPath(object $profile, string $key): string
    {
        return $this->drive($profile).'/root:/'.$this->index->filename($key);
    }

    private function transferUrl(mixed $url): string
    {
        // Graph returns a pre-authorized URL. Never follow redirects or attach the OAuth token there.
        $p = is_string($url) && preg_match('/^[!-~]+$/D', $url) && ! str_contains($url, '\\') ? parse_url($url) : false;
        if (! $p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['port'])
            || isset($p['fragment']) || ! preg_match('/^(?:[a-z0-9-]+\.)+(?:sharepoint\.com|1drv\.com)$/iD', $p['host'] ?? '')) {
            throw ProviderBytes::unavailable();
        }

        return $url;
    }

    private function verifyDrive(object $profile, string $token): void
    {
        $drive = $this->expect($this->http()->withToken($token)->get($this->drive($profile), ['$select' => 'id,driveType']), 200);
        if ($drive->json('id') !== $profile->container || ! in_array($drive->json('driveType'), ['documentLibrary', 'business'], true)) {
            throw ProviderBytes::unavailable();
        }
    }

    public function check(object $profile, array $credentials): void
    {
        $this->verifyDrive($profile, $this->session($profile, $credentials));
    }

    public function read(object $profile, array $credentials, string $key): string
    {
        return $this->download($profile, $key, $this->session($profile, $credentials));
    }

    private function download(object $profile, string $key, string $token): string
    {
        $id = $this->index->id($profile, $key);
        if ($id === null) {
            // Recover a committed upload if Graph responded but the worker could not persist its ID.
            $item = $this->http()->withToken($token)->get($this->itemPath($profile, $key), [
                '$select' => 'id,file,parentReference,remoteItem,name',
            ]);
            if ($item->status() === 404) {
                throw ProviderBytes::unavailable(404);
            }
            $this->expect($item, 200);
            $id = $this->remember($profile, $key, $item->json());
        }
        $response = $this->http()->withToken($token)->withOptions(['stream' => true])->get(
            $this->drive($profile).'/items/'.rawurlencode($id).'/content'
        );
        if ($response->status() === 404) {
            throw ProviderBytes::unavailable(404);
        }
        if ($response->status() === 302) {
            $response = $this->http()->withOptions(['stream' => true])->get($this->transferUrl($response->header('Location')));
        }

        return ProviderBytes::read($this->expect($response, 200)->toPsrResponse()->getBody());
    }

    private function remember(object $profile, string $key, mixed $item): string
    {
        if (! is_array($item) || ! is_array($item['file'] ?? null) || isset($item['remoteItem'])
            || ($item['parentReference']['driveId'] ?? null) !== $profile->container || ($item['name'] ?? null) !== $this->index->filename($key)) {
            throw ProviderBytes::unavailable();
        }
        $id = $this->index->remember($profile, $key, $item['id'] ?? null);
        if ($id !== $item['id']) {
            throw ProviderBytes::unavailable(409);
        }

        return $id;
    }

    public function put(object $profile, array $credentials, string $key, string $bytes, string $mime): void
    {
        $token = $this->session($profile, $credentials);
        $this->verifyDrive($profile, $token);
        $session = $this->http()->withToken($token)->post($this->itemPath($profile, $key).':/createUploadSession', [
            'item' => ['@microsoft.graph.conflictBehavior' => 'fail'],
        ]);
        if ($session->status() === 409) {
            ProviderBytes::requireSame($this->download($profile, $key, $token), $bytes);

            return;
        }
        $url = $this->transferUrl($this->expect($session, 200)->json('uploadUrl'));
        $size = strlen($bytes);
        $chunkSize = 10 * 1024 * 1024;
        for ($offset = 0; $offset < $size; $offset += $chunkSize) {
            $chunk = substr($bytes, $offset, $chunkSize);
            $end = $offset + strlen($chunk) - 1;
            $result = $this->http()->withHeaders(['Content-Range' => "bytes $offset-$end/$size"])->withBody($chunk, 'application/octet-stream')->put($url);
            if ($result->status() === 409) {
                ProviderBytes::requireSame($this->download($profile, $key, $token), $bytes);

                return;
            }
            if ($end === $size - 1) {
                $this->remember($profile, $key, $this->expect($result, 200, 201)->json());
            } else {
                $this->expect($result, 202);
            }
        }
    }
}
