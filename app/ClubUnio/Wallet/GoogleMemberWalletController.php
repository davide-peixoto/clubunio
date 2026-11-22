<?php

namespace App\ClubUnio\Wallet;

use App\ClubUnio\Mobile\MobileMemberIdCardData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class GoogleMemberWalletController
{
    public function save(Request $request)
    {
        $version = $request->validate(['SourceVersion' => ['required', 'regex:/\A[0-9a-f]{64}\z/D']])['SourceVersion'];
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        $id = (int) $request->route('member');
        $cards = app(MobileMemberIdCardData::class);
        $before = $cards->show($actor, $tenant, $id);
        abort_unless(hash_equals($before['SourceVersion'], $version), 409);
        $service = app(GoogleMemberWalletService::class);
        try { $pass = $service->issue($tenant, $id); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { throw $error; }
        catch (\Throwable) { abort(503); }
        $after = $cards->show($actor->fresh(), $tenant->fresh(), $id);
        if (! hash_equals($after['SourceVersion'], $version)) {
            $service->passes()->where('id', $pass->id)->update(['dirty' => true, 'checked_at' => null, 'next_attempt_at' => null]);
            abort(409);
        }
        return response()->json(['data' => ['SaveUrl' => app(GoogleWalletClient::class)->saveUrl($pass->object_id),
            'SourceVersion' => $version]])->header('Cache-Control', 'private, no-store');
    }

    public function artwork(Request $request)
    {
        $client = app(GoogleWalletClient::class);
        abort_unless($client->available() && $request->isSecure()
            && hash_equals($client->origin(), $request->getSchemeAndHttpHost()), 404);
        $pass = app(GoogleMemberWalletService::class)->passes()->where('id', $request->route('pass'))
            ->where('environment', $client->environment())->where('logo_hash', $request->route('hash'))->first();
        abort_unless($pass && $pass->logo_encrypted, 404);
        $bytes = Crypt::decryptString($pass->logo_encrypted);
        abort_unless(hash_equals(hash('sha256', $bytes), $request->route('hash')), 404);
        return response($bytes)->withHeaders(['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'", 'X-Robots-Tag' => 'noindex']);
    }
}
