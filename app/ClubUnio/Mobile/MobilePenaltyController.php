<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobilePenaltyController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobilePenaltyReady::class]; }
    private function actor(Request $request) { return $request->attributes->get('mobile.actor'); }
    private function tenant(Request $request) { return $request->attributes->get('mobile.tenant'); }
    private function input(Request $request, array $keys, int $maximum = 1000): void
    {
        abort_if(strlen($request->getContent()) > $maximum || array_diff(array_keys($request->all()), $keys), 422, 'Ungueltige Spielanfrage.');
    }
    private function write(Request $request, callable $action)
    {
        return DB::connection('clubunio')->transaction(function () use ($request, $action) {
            $tenant = $this->tenant($request);
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobilePenaltyAccess::class)->authorize($actor, $tenant);
            return response()->json(['data' => $action($actor, $tenant, app(MobilePenaltyService::class))]);
        });
    }
    public function options(Request $request)
    {
        return response()->json(['data' => app(MobilePenaltyService::class)->options($this->actor($request), $this->tenant($request))]);
    }
    public function leaderboard(Request $request)
    {
        $this->input($request, ['mode', 'start', 'recperpage']);
        $data = $request->validate(['mode' => 'required|in:shoot,save', 'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:50']);
        return response()->json(['data' => app(MobilePenaltyService::class)->leaderboard($this->actor($request), $this->tenant($request), $data['mode'], (int) ($data['start'] ?? 0), (int) ($data['recperpage'] ?? 20))]);
    }
    public function start(Request $request)
    {
        $this->input($request, ['mode', 'command_key']);
        $data = $request->validate(['mode' => 'required|in:shoot,save', 'command_key' => 'required|uuid']);
        return $this->write($request, fn ($actor, $tenant, $service) => $service->start($actor, $tenant, $data['mode'], $data['command_key']));
    }
    public function finish(Request $request)
    {
        $this->input($request, ['choices']);
        $data = $request->validate(['choices' => 'required|array|size:10', 'choices.*' => 'required|integer|between:-1,2']);
        abort_unless(array_is_list($data['choices']) && count(array_filter($data['choices'], 'is_int')) === 10, 422);
        return $this->write($request, fn ($actor, $tenant, $service) => $service->finish($actor, $tenant, $request->route('round'), $data['choices']));
    }
    public function reset(Request $request)
    {
        $this->input($request, ['challenge', 'confirm', 'notification'], 16000);
        $data = $request->validate(['challenge' => 'required|integer|min:1|max:2147483645', 'confirm' => 'required|accepted',
            'notification' => 'required|array:title,body', 'notification.title' => 'required|string|min:1|max:120', 'notification.body' => 'required|string|min:1|max:1000']);
        abort_unless(is_int($data['challenge']) && $request->input('confirm') === true && trim($data['notification']['title']) !== '' && trim($data['notification']['body']) !== '', 422);
        $response = $this->write($request, fn ($actor, $tenant, $service) => $service->reset($actor, $tenant, $data['challenge'], $data['notification']['title'], $data['notification']['body']));
        if (config('clubunio-mobile-push.enabled', false)) {
            $tenant = $this->tenant($request); $resetId = (int) $response->getData(true)['data']['resetId'];
            DB::connection('clubunio')->afterCommit(function () use ($tenant, $resetId): void {
                try { app(MobilePushSources::class)->challenge($tenant, $resetId); }
                catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('clubunio.penalty.notification_queue_failed', ['tenant_id' => $tenant->id, 'reset_id' => $resetId]); }
            });
        }
        return $response;
    }
}
