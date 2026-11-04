<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{BexioConnectionService, SponsorRunService, SyncWorkService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileSponsorBillingController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileSponsorReady::class]; }
    private function data(): MobileSponsorBillingData { return app(MobileSponsorBillingData::class); }
    private function json(array $data) { return response()->json(['data' => ['success' => true] + $data]); }
    public function index(Request $request) {
        return $this->json($this->data()->index($request, $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'), (int) $request->route('event')));
    }
    public function prepare(Request $request) {
        return $this->guarded($request, function ($actor, $tenant) use ($request) {
            abort_if(array_diff(array_keys($request->all()), ['PledgeID', 'SourceVersion', 'Confirm']), 422);
            $d = $request->validate(['PledgeID' => 'required|integer|min:1', 'SourceVersion' => 'required|regex:/^[0-9a-f]{64}$/', 'Confirm' => 'required|accepted']);
            abort_unless($request->input('Confirm') === true, 422);
            $this->data()->enter($actor, $tenant, 'approve'); $event = (int) $request->route('event');
            $pledge = DB::connection('clubunio')->table('clubora.sponsor_run_pledges')->where('tenant_id', $tenant->id)->where('event_id', $event)->find($d['PledgeID']);
            abort_unless($pledge, 404);
            $service = $this->data()->service();
            $existing = $service->table($tenant)->where('pledge_id', $pledge->id)->first();
            if ($existing) {
                $invoice = $service->get($actor, $tenant, (int) $existing->id);
                abort_unless(hash_equals($this->data()->sourceVersion($invoice->snapshot), $d['SourceVersion']), 409, 'Angezeigte Rechnungsvorschau wurde geändert.');
            } else {
                $source = app(SponsorRunService::class)->billingSource($actor, $tenant, (int) $pledge->id);
                abort_unless(hash_equals($this->data()->sourceVersion($source), $d['SourceVersion']), 409, 'Quelldaten wurden geändert. Bitte Vorschau frisch öffnen.');
                $invoice = $service->get($actor, $tenant, $service->prepare($actor, $tenant, (int) $pledge->id));
            }
            return $this->json(['Invoice' => $this->data()->invoice($actor, $tenant, $invoice)]);
        });
    }
    public function action(Request $request) {
        $prepared = $this->guarded($request, function ($actor, $tenant) use ($request) {
            $action = (string) $request->route('action'); abort_unless(in_array($action, MobileSponsorBillingData::ACTIONS, true), 422);
            $fields = ['EventID', 'Revision', 'Confirm', ...($action === 'link-contact' ? ['ContactID'] : []),
                ...(in_array($action, ['cancel', 'confirm-sent'], true) ? ['Reason'] : [])];
            abort_if(array_diff(array_keys($request->all()), $fields), 422);
            $d = $request->validate(['EventID' => 'required|integer|min:1', 'Revision' => 'required|integer|min:1', 'Confirm' => 'required|accepted',
                'ContactID' => $action === 'link-contact' ? 'required|integer|min:1|max:2147483647' : 'prohibited',
                'Reason' => in_array($action, ['cancel', 'confirm-sent'], true) ? 'required|string|min:10|max:1000' : 'prohibited']);
            abort_unless($request->input('Confirm') === true, 422);
            $this->data()->enter($actor, $tenant, $this->data()->function($action));
            abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
            $invoice = $this->data()->service()->get($actor, $tenant, (int) $request->route('invoice'));
            abort_unless((int) $invoice->event_id === (int) $d['EventID'], 404);
            $input = [];
            if (isset($d['ContactID'])) $input['contact_id'] = (int) $d['ContactID'];
            if (isset($d['Reason'])) $input['reason'] = trim($d['Reason']);
            $revision = (int) $d['Revision'];
            // Canonical SyncWork keeps intent/results and reconciles replays instead of repeating writes.
            $work = app(SyncWorkService::class)->remember($actor, $tenant, 'sponsor_run_billing', (int) $invoice->id, compact('revision', 'action', 'input'));
            $completed = app(SyncWorkService::class)->table()->where('tenant_id', $tenant->id)->where('id', $work)->where('state', 'succeeded')->exists();
            return [$tenant, (int) $invoice->id, $revision, $action, $input, $work, $completed];
        });
        [$tenant, $id, $revision, $action, $input, $work, $completed] = $prepared;
        // No outer SQL transaction around provider I/O: uncertain outcomes must remain persistent.
        $actor = app(MobileSessions::class)->authenticate($request); $this->data()->enter($actor, $tenant, $this->data()->function($action));
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        if ($completed) {
            return $this->json(['Invoice' => $this->data()->invoice($actor, $tenant, $this->data()->service()->get($actor, $tenant, $id)),
                'WorkID' => $work, 'Replayed' => true]);
        }
        $invoice = $this->data()->service()->execute($actor, $tenant, $id, $revision, $action, $input);
        $actor = app(MobileSessions::class)->authenticate($request); $this->data()->enter($actor, $tenant);
        return $this->json(['Invoice' => $this->data()->invoice($actor, $tenant, $invoice), 'WorkID' => $work]);
    }
    public function pdf(Request $request) {
        $d = $request->validate(['revision' => 'required|integer|min:1', 'event' => 'required|integer|min:1']);
        $actor = $request->attributes->get('mobile.actor'); $tenant = $request->attributes->get('mobile.tenant');
        $this->data()->enter($actor, $tenant, 'download');
        $invoice = $this->data()->service()->get($actor, $tenant, (int) $request->route('invoice'));
        abort_unless((int) $invoice->event_id === (int) $d['event'], 404);
        abort_unless((int) $invoice->revision === (int) $d['revision'], 409, 'Rechnung wurde inzwischen geändert.');
        $response = $this->data()->service()->pdf($actor, $tenant, (int) $invoice->id);
        $fresh = $this->data()->service()->get($actor->fresh(), $tenant, (int) $invoice->id);
        abort_unless((int) $fresh->revision === (int) $d['revision'], 409);
        $bytes = $response->getContent();
        abort_unless(is_string($bytes) && strlen($bytes) >= 5 && strlen($bytes) <= 20971520 && str_starts_with($bytes, '%PDF-'), 502);
        $response->headers->set('X-Content-SHA256', hash('sha256', $bytes));
        $response->headers->set('Content-Length', (string) strlen($bytes));
        $response->headers->set('X-Sponsor-Billing-Revision', (string) $d['revision']);
        return $response;
    }
    private function guarded(Request $request, callable $operation) {
        abort_if(strlen($request->getContent()) > 20000, 422);
        return DB::connection('clubunio')->transaction(function () use ($request, $operation) {
            $tenant = $request->attributes->get('mobile.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request); app(MobileAccess::class)->enter($actor, $tenant);
            return $operation($actor, $tenant);
        });
    }
}
