<?php

namespace App\ClubUnio\Wallet;

use App\ClubUnio\Mobile\MobileMemberIdCardData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class MemberWalletController
{
    public function download(Request $request)
    {
        $version = $request->validate(['SourceVersion' => ['required', 'regex:/\A[0-9a-f]{64}\z/D']])['SourceVersion'];
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        $id = (int) $request->route('member');
        $fresh = app(MobileMemberIdCardData::class)->show($actor, $tenant, $id);
        abort_unless(hash_equals($fresh['SourceVersion'], $version), 409);
        $service = app(MemberWalletService::class);
        $pass = $service->issue($tenant, $id);
        $bytes = $service->package($pass);
        // Reauthorize after signing too. Never return another member/context or
        // obsolete snapshot while native signing was running.
        $after = app(MobileMemberIdCardData::class)->show($actor->fresh(), $tenant->fresh(), $id);
        abort_unless(hash_equals($after['SourceVersion'], $version), 409);
        return response($bytes)->withHeaders([
            'Content-Type' => 'application/vnd.apple.pkpass', 'Content-Length' => strlen($bytes),
            'Content-Disposition' => 'attachment; filename="membership.pkpass"',
            'X-Content-SHA256' => hash('sha256', $bytes), 'X-Member-Card-Source-Version' => $version,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function protocol(Request $request): MemberWalletService
    {
        abort_unless(config('clubunio-wallet.enabled'), 503);
        $origin = app(ApplePassBundle::class)->origin();
        abort_unless($request->isSecure() && hash_equals(strtolower($origin), strtolower($request->getSchemeAndHttpHost())), 404);
        abort_if(strlen($request->getContent()) > 10_240, 413);
        return app(MemberWalletService::class);
    }

    private function device(Request $request): string
    {
        $device = (string) $request->route('device');
        abort_unless(preg_match('/\A[A-Za-z0-9._-]{16,256}\z/D', $device), 404);
        return hash('sha256', $device);
    }

    public function register(Request $request)
    {
        $service = $this->protocol($request); $device = $this->device($request);
        $pass = $service->authorizedPass((string) $request->route('type'), (string) $request->route('serial'), $request->header('Authorization'));
        $data = $request->validate(['pushToken' => ['required', 'regex:/\A[0-9a-f]{64,200}\z/iD']]);
        $key = ['serial' => $pass->serial, 'device_hash' => $device];
        $existing = $service->registrations()->where($key)->exists();
        $service->registrations()->upsert([$key + [
            'push_encrypted' => Crypt::encryptString($data['pushToken']), 'updated_at' => now(),
        ]], ['serial', 'device_hash'], ['push_encrypted', 'updated_at']);
        return response('', $existing ? 200 : 201);
    }

    public function unregister(Request $request)
    {
        $service = $this->protocol($request); $device = $this->device($request);
        $pass = $service->authorizedPass((string) $request->route('type'), (string) $request->route('serial'), $request->header('Authorization'));
        $service->registrations()->where('serial', $pass->serial)->where('device_hash', $device)->delete();
        return response('', 200);
    }

    public function updates(Request $request)
    {
        $service = $this->protocol($request); $device = $this->device($request);
        abort_unless($request->route('type') === config('clubunio-wallet.pass_type_identifier'), 404);
        $since = $request->query('passesUpdatedSince');
        abort_if($since !== null && (! is_string($since) || ! preg_match('/\A[0-9]{1,18}\z/D', $since)), 400);
        $passes = $service->passes()->join('clubunio.member_wallet_registrations as r', 'r.serial', '=', 'member_wallet_passes.serial')
            ->where('r.device_hash', $device)->where('member_wallet_passes.pass_type', $request->route('type'))
            ->when($since !== null, fn ($q) => $q->where('update_tag', '>', (int) $since))
            ->orderBy('update_tag')->get(['member_wallet_passes.serial', 'member_wallet_passes.update_tag']);
        if ($passes->isEmpty()) { return response('', 204); }
        return response()->json(['serialNumbers' => $passes->pluck('serial')->all(), 'lastUpdated' => (string) $passes->max('update_tag')])
            ->header('Cache-Control', 'private, no-store');
    }

    public function pass(Request $request)
    {
        $service = $this->protocol($request);
        $pass = $service->authorizedPass((string) $request->route('type'), (string) $request->route('serial'), $request->header('Authorization'));
        $bytes = $service->package($pass);
        return response($bytes)->withHeaders([
            'Content-Type' => 'application/vnd.apple.pkpass', 'Content-Length' => strlen($bytes),
            'Last-Modified' => gmdate('D, d M Y H:i:s', strtotime($service->passes()->where('serial', $pass->serial)->value('updated_at'))).' GMT',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logs(Request $request)
    {
        $this->protocol($request);
        abort_if(strlen($request->getContent()) > 10_240, 413);
        // PassKit diagnostic strings can contain tokens or member data. Discard.
        return response('', 200);
    }
}
