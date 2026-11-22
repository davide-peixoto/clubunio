<?php

namespace App\ClubUnio\Wallet;

/** PassKit uses the pass certificate, not Firebase or the app's APNs key. */
class AppleWalletPush
{
    public function send(string $token, string $type): int
    {
        if (! preg_match('/\A[0-9a-f]{64,200}\z/iD', $token) || $type !== config('clubunio-wallet.pass_type_identifier')
            || ! function_exists('curl_init') || ! defined('CURL_HTTP_VERSION_2_0')) {
            throw new \RuntimeException('wallet_push_unavailable');
        }
        $keys = app(ApplePassBundle::class)->credentials();
        $curl = curl_init('https://api.push.apple.com/3/device/'.$token);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $keys['certificate'], CURLOPT_SSLKEY => $keys['key'], CURLOPT_KEYPASSWD => $keys['password'],
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'apns-topic: '.$type, 'apns-push-type: background', 'apns-priority: 5'],
        ]);
        try {
            $body = curl_exec($curl);
            if ($body === false) { throw new \RuntimeException('wallet_push_retry'); }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            // BadDeviceToken is permanent too, but no response body is logged.
            if ($status === 400 && (json_decode($body, true)['reason'] ?? '') === 'BadDeviceToken') { return 410; }
            return $status;
        } finally { curl_close($curl); }
    }
}
