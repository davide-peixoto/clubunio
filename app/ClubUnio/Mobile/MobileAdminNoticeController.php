<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

final class MobileAdminNoticeController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileAdminNoticeReady::class]; }
    private function actor(Request $request) { return $request->attributes->get('mobile.actor'); }
    private function tenant(Request $request) { return $request->attributes->get('mobile.tenant'); }
    private function input(Request $request, bool $send): array
    {
        $keys = ['target_type', 'target_value', 'title', 'body', ...($send ? ['request_key', 'revision', 'confirm'] : [])];
        abort_if(strlen($request->getContent()) > 16000 || array_diff(array_keys($request->all()), $keys), 422);
        $data = $request->validate(['target_type' => 'required|in:all,office,trainer_office,account,member,role',
            'target_value' => 'nullable|string|max:40', 'title' => 'required|string|min:1|max:120', 'body' => 'required|string|min:1|max:1000',
            ...($send ? ['request_key' => 'required|uuid', 'revision' => 'required|regex:/^[a-f0-9]{64}$/', 'confirm' => 'required|accepted'] : [])]);
        $value = $data['target_value'] ?? null;
        if (in_array($data['target_type'], ['account','member'], true)) abort_unless(is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value), 422);
        elseif ($data['target_type'] === 'role') abort_unless(is_string($value) && array_key_exists($value, MobileAdminNoticeAccess::ROLES), 422);
        else abort_unless($value === null, 422);
        abort_unless(trim($data['title']) !== '' && trim($data['body']) !== '' && (! $send || $request->input('confirm') === true), 422);
        return $data;
    }
    public function options(Request $request)
    {
        return response()->json(['data' => app(MobileAdminNoticeService::class)->options($this->actor($request), $this->tenant($request))]);
    }
    public function lookup(Request $request)
    {
        abort_if(array_diff(array_keys($request->query()), ['type', 'q']), 422);
        $data = $request->validate(['type' => 'required|in:account,member', 'q' => 'nullable|string|max:120']);
        return response()->json(['data' => ['rows' => app(MobileAdminNoticeService::class)->lookup($this->actor($request), $this->tenant($request), $data['type'], trim($data['q'] ?? ''))]]);
    }
    public function preview(Request $request)
    {
        return response()->json(['data' => app(MobileAdminNoticeService::class)->preview($this->actor($request), $this->tenant($request), $this->input($request, false))]);
    }
    public function send(Request $request)
    {
        $data = $this->input($request, true);
        return DB::connection('clubunio')->transaction(function () use ($request, $data) {
            $tenant = $this->tenant($request); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            return response()->json(['data' => app(MobileAdminNoticeService::class)->create($actor, $tenant, $data)]);
        });
    }
    public function receipt(Request $request)
    {
        return response()->json(['data' => app(MobileAdminNoticeService::class)->receipt($this->actor($request), $this->tenant($request), (int) $request->route('notice'))]);
    }
    public function content(Request $request)
    {
        $tenant = $this->tenant($request); $notice = app(MobileAdminNoticeAccess::class)->notice($this->actor($request), $tenant, (int) $request->route('notice'));
        return response()->json(['data' => ['noticeId' => (int) $notice->id, 'tenantId' => (int) $tenant->id,
            'title' => Crypt::decryptString($notice->title_encrypted), 'body' => Crypt::decryptString($notice->body_encrypted)]])
            ->header('Cache-Control', 'private, no-store');
    }
}
