<?php
namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobilePushReady
{
    public function available(?Request $request = null): bool
    {
        $request ??= request();
        if (! config('clubunio-mobile-push.enabled', false) || ! config('clubunio-mobile-push.delivery_enabled', false)
            || ! in_array('personal-push-v1', explode(',', $request->header('X-ClubUnio-Mobile-Features', '')), true)) return false;
        foreach (config('clubunio-mobile-push.apps', []) as $id => $app) {
            if (! is_array($app) || ($app['origin'] ?? null) !== $request->getSchemeAndHttpHost()) continue;
            try {
                app(MobilePushRegistry::class)->request($request, $id, $app['platform'] ?? '');
                $path = $app['credentials_file'] ?? '';
                if (! is_string($path) || str_contains($path, '://') || ! is_file($path) || ! is_readable($path) || filesize($path) > 32768) continue;
                $credential = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                if (($credential['type'] ?? '') !== 'service_account' || ($credential['project_id'] ?? '') !== ($app['project_id'] ?? null)
                    || ! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $app['project_id'] ?? '')
                    || ($credential['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token'
                    || ! preg_match('/^[^\s@]+@[^\s@]+\.iam\.gserviceaccount\.com$/D', $credential['client_email'] ?? '')
                    || ! openssl_pkey_get_private($credential['private_key'] ?? '')) continue;
                return true;
            } catch (\Throwable) { continue; }
        }
        return false;
    }
}
