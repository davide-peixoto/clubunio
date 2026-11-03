<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{MemberChangeService, MemberService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MobileMemberController
{
    public function show(Request $r)
    {
        return response()->json(['data' => ['Member' => app(MobileMemberData::class)->details($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'))]]);
    }

    public function options(Request $r)
    {
        return response()->json(['data' => app(MobileMemberData::class)->options($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('member'))]);
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
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
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
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            $data = app(MobileMemberData::class);
            $id = (int) $r->route('member');
            $member = $data->member($actor, $tenant, $id);
            $direct = $data->direct($actor, $tenant);
            abort_unless(($direct || $data->represented($actor, $tenant, $id)) && ! $member->archived_at, 403);
            $allowed = array_keys(MobileMemberData::CONTACT);
            if (! $direct) { $allowed = array_values(array_diff($allowed, ['Homepage'])); }
            if (array_diff(array_keys($r->all()), [...$allowed, '_version'])) {
                throw ValidationException::withMessages(['member' => 'Nicht erlaubtes Aenderungsfeld.']);
            }
            $r->validate(['_version' => 'required|regex:/^[0-9a-f]{64}$/']);
            $input = ['_version' => $r->input('_version')];
            foreach ($allowed as $key) {
                if (! $r->exists($key)) { continue; }
                $value = $r->input($key);
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
                return response()->json(['data' => ['success' => true, 'action' => 'member-contact-update', 'Member' => $data->details($actor, $tenant, $id)]]);
            }
            $change = app(MemberChangeService::class)->submit($actor, $tenant, $id, $input);
            return response()->json(['data' => ['success' => true, 'action' => 'member-contact-change-request', 'request_id' => (int) $change->id,
                'request_revision' => (int) $change->revision, 'state' => $change->state]]);
        });
    }
}
