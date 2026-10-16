<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MaterialReceiptService
{
    public const CONFIRMATION = 'Ich bestätige, die aufgeführten Gegenstände in der angegebenen Menge und im beschriebenen Zustand erhalten zu haben.';

    public function states(Tenant $tenant): array
    {
        return DB::connection('clubunio')->table('clubora.material_receipt_statuses')->where('tenant_id', $tenant->id)->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    public function visible(Account $actor, Tenant $tenant, bool $administration = false): Builder
    {
        $actor = $actor->fresh();
        $query = MaterialReceipt::where('tenant_id', $tenant->id);
        if ($administration) {
            abort_unless($actor && ($actor->hasUnrestrictedBackofficeAccess($tenant) || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', 'view')), 403);

            return $query;
        }
        $access = app(MemberAccessService::class);
        abort_unless($actor && $access->canEnter($actor, $tenant), 403);

        return $query->where('state', '<>', 'draft')->whereIn('member_id', $access->represented($actor, $tenant)->select('clubora.members.id'));
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): MaterialReceipt
    {
        abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant) || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', $id ? 'update' : 'create'), 403);
        $data = Validator::make($input, ['revision' => 'required|integer|min:0', 'member_id' => 'required|integer|min:1',
            'title' => 'required|string|max:200', 'handover_on' => 'required|date_format:Y-m-d', 'note' => 'nullable|string|max:3000',
            'items' => 'required|array|min:1|max:100', 'items.*' => 'array:description,quantity,reference,condition',
            'items.*.description' => 'required|string|max:300', 'items.*.quantity' => 'required|integer|min:1|max:10000',
            'items.*.reference' => 'nullable|string|max:120', 'items.*.condition' => 'nullable|string|max:500'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant) || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', $id ? 'update' : 'create'), 403);
            $record = $id ? MaterialReceipt::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new MaterialReceipt;
            if ($id) {
                $this->version($record, $data['revision']);
                abort_unless($record->state === 'draft', 409);
            } else {
                abort_unless((int) $data['revision'] === 0, 409);
                $record->forceFill(['tenant_id' => $tenant->id, 'created_by' => $actor->id, 'state' => 'draft', 'revision' => 0]);
            }
            Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->findOrFail($data['member_id']);
            abort_unless(DB::connection('clubunio')->table('clubora.material_receipt_statuses')->where('tenant_id', $tenant->id)->where('code', 'draft')->where('active', true)->exists(), 409);
            unset($data['revision']);
            $data['items'] = array_values($data['items']);
            $data['note'] = $data['note'] ?? '';
            $record->forceFill($data + ['note' => '']);
            $record->revision++;
            $record->save();
            $this->audit($actor, $tenant, $record, 'save_draft');

            return $record->refresh();
        });
    }

    public function transition(Account $actor, Tenant $tenant, int $id, int $revision, string $operation, array $input = []): MaterialReceipt
    {
        abort_unless(in_array($operation, ['issue', 'confirm', 'cancel'], true), 422);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $operation, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            if ($operation !== 'confirm') {
                abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant) || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', $operation === 'issue' ? 'send' : 'cancel'), 403);
            }
            $record = $this->visible($actor, $tenant, $operation !== 'confirm')->lockForUpdate()->findOrFail($id);
            $this->version($record, $revision);
            if ($operation === 'issue') {
                abort_unless($record->state === 'draft', 409);
                $member = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($record->member_id);
                $snapshot = ['club' => $tenant->name, 'member' => $member->display_name, 'member_id' => $member->id,
                    'title' => $record->title, 'handover_on' => $record->handover_on->toDateString(), 'note' => $record->note,
                    'items' => $record->items, 'issuer' => $actor->display_name, 'confirmation' => self::CONFIRMATION];
                $record->forceFill(['state' => 'issued', 'issued_by' => $actor->id, 'issued_at' => now(), 'snapshot' => $snapshot,
                    'snapshot_hash' => $this->snapshotHash($snapshot)]);
            } elseif ($operation === 'confirm') {
                abort_unless($record->state === 'issued', 409);
                Validator::make($input, ['accepted' => 'accepted|required'])->validate();
                abort_unless($record->snapshot && hash_equals($record->snapshot_hash, $this->snapshotHash($record->snapshot)), 409);
                $access = app(MemberAccessService::class);
                $own = $access->represented($actor, $tenant, true)->whereKey($record->member_id)->exists();
                $record->forceFill(['state' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                    'confirmation_name' => $actor->display_name, 'confirmation_kind' => $own ? 'self' : 'parent',
                    'confirmation_text' => $record->snapshot['confirmation']]);
            } else {
                abort_unless(in_array($record->state, ['draft', 'issued'], true), 409, 'Bestätigte Quittungen bleiben unverändert.');
                $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
                $record->forceFill(['state' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $data['reason']]);
            }
            abort_unless(DB::connection('clubunio')->table('clubora.material_receipt_statuses')->where('tenant_id', $tenant->id)->where('code', $record->state)->where('active', true)->exists(), 409, 'Zielstatus ist nicht aktiv.');
            $record->revision++;
            $record->save();
            $this->audit($actor, $tenant, $record, $operation);

            return $record->refresh();
        });
    }

    private function version(MaterialReceipt $record, int $revision): void
    {
        if ($record->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Die Übergabe wurde geändert. Bitte neu laden.']);
        }
    }

    private function snapshotHash(array $value): string
    {
        $sort = function (array $values) use (&$sort): array {
            if (! array_is_list($values)) {
                ksort($values);
            }
            foreach ($values as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $values;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    private function audit(Account $actor, Tenant $tenant, MaterialReceipt $record, string $operation): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'material_receipts', 'object_id' => $record->id,
            'after_value' => json_encode(['action' => $operation, 'state' => $record->state, 'revision' => $record->revision, 'snapshot_hash' => $record->snapshot_hash], JSON_THROW_ON_ERROR)]);
    }

    public function pdf(Account $actor, Tenant $tenant, int $id, bool $administration = false)
    {
        if ($administration) {
            abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant) || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', 'download'), 403);
        }
        $receipt = $this->visible($actor, $tenant, $administration)->findOrFail($id);
        abort_unless($receipt->snapshot, 404);
        abort_unless(hash_equals($receipt->snapshot_hash, $this->snapshotHash($receipt->snapshot)), 409);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('clubunio.material.pdf', compact('receipt'))->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Quittung-'.$id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }
}
