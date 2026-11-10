<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{BusKeyboxAccessService, BusReservationService, MemberAccessService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileBusController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileBusReady::class]; }
    public function index(Request $r) { return response()->json(['data' => app(MobileBusData::class)->index($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function options(Request $r) { return response()->json(['data' => app(MobileBusData::class)->options($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function members(Request $r) { return response()->json(['data' => app(MobileBusData::class)->members($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function show(Request $r) {
        $actor = $r->attributes->get('mobile.actor'); $tenant = $r->attributes->get('mobile.tenant');
        $row = app(MobileBusData::class)->visible($actor, $tenant)->where('id', (int) $r->route('reservation'))->first(); abort_unless($row, 404);
        return response()->json(['data' => app(MobileBusData::class)->row($actor, $tenant, $row)]);
    }
    private function write(Request $r, callable $action) {
        return DB::connection('clubunio')->transaction(function () use ($r, $action) {
            $tenant = $r->attributes->get('mobile.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r); app(MobileAccess::class)->enter($actor, $tenant);
            return response()->json(['data' => $action($actor, $tenant)]);
        });
    }
    public function create(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $d = $r->validate(['BusID' => 'required|integer|min:1', 'TeamSaisonID' => 'required|integer|min:1', 'AntragstellerMitgliederID' => 'nullable|integer|min:1',
                'ResVon' => 'required|date', 'ResBis' => 'required|date|after:ResVon', 'Ort' => 'nullable|string|max:255', 'Descr' => 'nullable|string|max:1000', 'ReservierungsStatus' => 'nullable|in:ANGEFRAGT,GENEHMIGT']);
            abort_unless(app(MobileBusData::class)->teams($actor, $tenant)->where('id', $d['TeamSaisonID'])->exists(), 403);
            $own = app(MemberAccessService::class)->represented($actor, $tenant, true)->limit(2)->pluck('id');
            $member = $d['AntragstellerMitgliederID'] ?? ($own->count() === 1 ? $own->first() : null); abort_unless($member, 422, 'Bitte Antragsteller waehlen.');
            foreach (['ResVon', 'ResBis'] as $key) { abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $d[$key]), 422, 'Zeitzone fehlt.'); }
            $from = Time::parse($d['ResVon'])->setTimezone('Europe/Zurich'); $to = Time::parse($d['ResBis'])->setTimezone('Europe/Zurich');
            $id = app(BusReservationService::class)->create($actor, $tenant, ['bus_id' => $d['BusID'], 'team_season_id' => $d['TeamSaisonID'], 'member_id' => $member,
                'starts_at' => $from->format('Y-m-d\TH:i'), 'ends_at' => $to->format('Y-m-d\TH:i'), 'location' => $d['Ort'] ?? '', 'description' => $d['Descr'] ?? '', 'status' => $d['ReservierungsStatus'] ?? 'ANGEFRAGT']);
            $row = app(MobileBusData::class)->visible($actor, $tenant)->where('id', $id)->first(); abort_unless($row, 409);
            abort_unless(Time::parse($row->starts_at)->equalTo($from->startOfMinute()) && Time::parse($row->ends_at)->equalTo($to->startOfMinute()), 422, 'Zeitpunkt wegen Zeitumstellung nicht eindeutig.');
            return app(MobileBusData::class)->row($actor, $tenant, $row);
        });
    }
    public function decide(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $id = (int) $r->route('reservation'); abort_unless(app(MobileBusData::class)->visible($actor, $tenant)->where('id', $id)->exists(), 404);
            $d = $r->validate(['revision' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000']);
            $status = match ($r->route('action')) { 'approve' => 'GENEHMIGT', 'reject' => 'ABGELEHNT', 'cancel' => 'STORNIERT', default => abort(404) };
            app(BusReservationService::class)->decide($actor, $tenant, $id, $d['revision'], $status, $d['note'] ?? null);
            return app(MobileBusData::class)->row($actor, $tenant, app(MobileBusData::class)->visible($actor, $tenant)->where('id', $id)->first());
        });
    }
    public function keybox(Request $r) {
        $actor = $r->attributes->get('mobile.actor'); $tenant = $r->attributes->get('mobile.tenant'); $id = (int) $r->route('reservation');
        abort_unless(app(MobileBusData::class)->visible($actor, $tenant)->where('id', $id)->exists(), 404);
        $data = app(BusKeyboxAccessService::class)->inspect($actor, $tenant, $id);
        return response()->json(['data' => ['available' => false, 'issuanceEnabled' => false, 'simulationMode' => true,
            'eligible' => $data['eligible'], 'inWindow' => $data['inWindow'], 'startsAt' => $data['from']->toIso8601String(), 'expiresAt' => $data['until']->toIso8601String()]]);
    }
}
