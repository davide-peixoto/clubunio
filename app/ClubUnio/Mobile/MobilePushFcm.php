<?php

namespace App\ClubUnio\Mobile;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/** One FCMv1 attempt. Transport uncertainty must never trigger an automatic resend. */
final class MobilePushFcm
{
    public function send(array $serverApp, string $registrationToken, MobilePushEnvelope $envelope, ?array $notification = null): array
    {
        $project = $serverApp['project_id'] ?? '';
        if (! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $project)
            || ! is_string($registrationToken) || strlen($registrationToken) < 20 || strlen($registrationToken) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $registrationToken)) {
            throw new \InvalidArgumentException('Invalid server push configuration');
        }
        // Explicit private operator path only: no ADC fallback, posted JSON, remote URI or metadata fetch.
        $path = $serverApp['credentials_file'] ?? '';
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 32768
            || str_contains($path, '://')) throw new \LogicException('Push credentials unavailable');
        $credential = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (($credential['type'] ?? '') !== 'service_account' || ($credential['project_id'] ?? '') !== $project
            || ($credential['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token'
            || ! preg_match('/^[^\s@]+@[^\s@]+\.iam\.gserviceaccount\.com$/D', $credential['client_email'] ?? '')
            || ! is_string($credential['private_key'] ?? null)) throw new \LogicException('Invalid push service account');
        try {
            $auth = new ServiceAccountCredentials(['https://www.googleapis.com/auth/firebase.messaging'], $credential);
            $token = $auth->fetchAuthToken(function (RequestInterface $request) {
                if ((string) $request->getUri() !== 'https://oauth2.googleapis.com/token' || $request->getMethod() !== 'POST') {
                    throw new \LogicException('Unexpected OAuth destination');
                }
                $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()
                    ->withBody((string) $request->getBody(), 'application/x-www-form-urlencoded')
                    ->post('https://oauth2.googleapis.com/token');
                return new Response($response->status(), ['Content-Type' => 'application/json'], $response->body());
            });
        } catch (\Throwable) {
            return ['state' => 'attention', 'code' => 'oauth_unavailable'];
        }
        if (! is_string($token['access_token'] ?? null) || strlen($token['access_token']) > 8192
            || ($token['expires_in'] ?? 0) <= 0) return ['state' => 'attention', 'code' => 'oauth_unavailable'];
        try {
            $response = Http::withToken($token['access_token'])->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', ['message' => [
                    'token' => $registrationToken, 'data' => $envelope->data(),
                    'notification' => $notification ?? app(MobilePushPresentation::class)->notification(config('clubunio-languages.default', 'de')),
                    'android' => ['priority' => 'high', 'ttl' => '3600s'],
                    'apns' => ['headers' => ['apns-expiration' => (string) (time() + 3600), 'apns-priority' => '10', 'apns-push-type' => 'alert'],
                        'payload' => ['aps' => ['sound' => 'default']]],
                ]]);
        } catch (\Throwable) {
            return ['state' => 'attention', 'code' => 'delivery_uncertain'];
        }
        $reference = $response->json('name');
        if ($response->successful() && is_string($reference)
            && preg_match('~^projects/'.preg_quote($project, '~').'/messages/[a-zA-Z0-9:%._-]{1,180}$~D', $reference)) {
            return ['state' => 'accepted', 'code' => 'provider_accepted', 'reference' => $reference];
        }
        $details = $response->json('error.details');
        foreach (is_array($details) ? $details : [] as $detail) {
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? '') === 'UNREGISTERED') return ['state' => 'discarded', 'code' => 'token_unregistered'];
        }
        return ['state' => 'attention', 'code' => 'provider_rejected'];
    }
}
