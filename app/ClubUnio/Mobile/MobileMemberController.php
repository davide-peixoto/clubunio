<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{MemberChangeService, MemberService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MobileMemberController
{
    private function teamContext(Request $r): ?int
    {
        if (array_diff(array_keys($r->query()), ['team_id'])) { throw ValidationException::withMessages(['context' => 'Nicht erlaubter Mannschaftskontext.']); }
        $context = \Illuminate\Support\Facades\Validator::make($r->query(), ['team_id' => 'sometimes|required|integer|min:1'])->validate();
        return isset($context['team_id']) ? (int)$context['team_id'] : null;
    }

    public function show(Request $r)
    {
        return response()->json(['data' => ['Member' => app(MobileMemberData::class)->details($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'), $this->teamContext($r))]]);
    }

    public function photo(Request $r, \App\ClubUnio\Services\ProfilePhotoService $photos)
    {
        $actor = $r->attributes->get('mobile.actor');
        $tenant = $r->attributes->get('mobile.tenant');
        $id = (int) $r->route('member');
        $details = app(MobileMemberData::class)->details($actor, $tenant, $id, $this->teamContext($r));
        abort_unless($details['PhotoAvailable'] ?? false, 404);
        $image = $photos->member($tenant, $id)?->image_encrypted;
        abort_unless($image, 404);

        return $photos->response(\Illuminate\Support\Facades\Crypt::decryptString($image));
    }

    public function options(Request $r)
    {
        return response()->json(['data' => app(MobileMemberData::class)->options($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'), $this->teamContext($r))]);
    }

    public function invoices(Request $r)
    {
        return response()->json(['data' => app(MobileMemberData::class)->invoices($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'))]);
    }

    public function changes(Request $r)
    {
        return response()->json(['data' => app(MobileMemberData::class)->changes($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'))]);
    }

    public function decideChange(Request $r)
    {
        return DB::connection('clubunio')->transaction(function () use ($r) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            $member = (int) $r->route('member');
            app(MobileMemberData::class)->member($actor, $tenant, $member);
            $d = $r->validate(['Revision' => 'required|integer|min:1', 'approve' => 'required|boolean', 'Begruendung' => 'nullable|string|max:2000']);
            $change = MemberChangeRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $member)->findOrFail((int) $r->route('change'));
            $change = app(MemberChangeService::class)->review($actor, $tenant, $change->id, $d['Revision'], $d['approve'], $d['Begruendung'] ?? null);
            return response()->json(['data' => ['success' => true, 'status' => MobileMemberData::STATES[$change->state], 'Revision' => (int) $change->revision]]);
        });
    }

    public function contact(Request $r)
    {
        return DB::connection('clubunio')->transaction(function () use ($r) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            $data = app(MobileMemberData::class);
            $id = (int) $r->route('member');
            $teamId = $this->teamContext($r);
            $detail = $data->details($actor, $tenant, $id, $teamId);
            $member = $teamId === null ? $data->member($actor, $tenant, $id)
                : app(\App\ClubUnio\Services\MemberTeamAccessService::class)->member($actor, $tenant, $teamId, $id)[0];
            $direct = $detail['ChangeMode'] === 'direct';
            abort_unless($detail['CanEdit'] && !$member->archived_at, 403);
            $allowed = $detail['EditableFields'];
            $payload = $r->isJson() ? $r->json()->all() : $r->request->all();
            if (array_diff(array_keys($payload), [...$allowed, '_version'])) {
                throw ValidationException::withMessages(['member' => 'Nicht erlaubtes Aenderungsfeld.']);
            }
            \Illuminate\Support\Facades\Validator::make($payload, ['_version' => 'required|regex:/^[0-9a-f]{64}$/'])->validate();
            $input = ['_version' => $payload['_version']];
            foreach ($allowed as $key) {
                if (! array_key_exists($key, $payload)) { continue; }
                $value = $payload[$key];
                if (in_array($key, ['Land', 'Nationalitaet'], true)) {
                    if ($value === '' || $value === null) { $value = null; }
                    else {
                        if (! is_string($value) || ! preg_match($key === 'Land' ? '/^[A-Z]{2}$/D' : '/^[A-Z]{3}$/D', $value)) {
                            throw ValidationException::withMessages([$key => 'Ungueltiger Laendercode.']);
                        }
                        $rows = $data->table($tenant, 'countries')->where($key === 'Land' ? 'code' : 'iso3', $value)->get(['id']);
                        if ($rows->count() !== 1) { throw ValidationException::withMessages([$key => 'Land nicht eindeutig vorhanden.']); }
                        $value = $rows->sole()->id;
                    }
                }
                $input[MobileMemberData::CONTACT[$key]] = $value;
            }
            if ($direct) {
                app(MemberService::class)->update($actor, $tenant, $id, $input);
                return response()->json(['data' => ['success' => true, 'action' => 'member-contact-update', 'Member' => $data->details($actor, $tenant, $id, $teamId)]]);
            }
            $change = app(MemberChangeService::class)->submit($actor, $tenant, $id, $input, $teamId);
            return response()->json(['data' => ['success' => true, 'action' => 'member-contact-change-request', 'request_id' => (int) $change->id,
                'request_revision' => (int) $change->revision, 'state' => $change->state]]);
        });
    }
}
