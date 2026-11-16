<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, MaterialReceipt, Tenant};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class MaterialReturnService
{
    public function allows(?Account $actor, ?Tenant $tenant, string $operation): bool
    {
        return $actor && $tenant && ! request()->attributes->has('clubunio_impersonation')
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', $operation);
    }

    private function table(Tenant $tenant, string $name)
    {
        return DB::connection('clubunio')->table('clubora.'.$name)->where('tenant_id', $tenant->id);
    }

    public function positions(Account $actor, Tenant $tenant, ?int $member, bool $overdue, bool $completed)
    {
        abort_unless($this->allows($actor, $tenant, 'view'), 403);
        $db = DB::connection('clubunio');
        return $db->table('clubora.material_receipts as receipt')
            ->crossJoin($db->raw("LATERAL jsonb_array_elements(receipt.snapshot->'items') WITH ORDINALITY AS item(data, position)"))
            ->leftJoin('clubora.material_return_deadlines as due', fn ($join) => $join
                ->on('due.tenant_id', '=', 'receipt.tenant_id')->on('due.receipt_id', '=', 'receipt.id')->on('due.position', '=', 'item.position'))
            ->leftJoinSub($this->table($tenant, 'material_returns')->selectRaw('receipt_id, position, sum(quantity) AS returned')
                ->groupBy('receipt_id', 'position'), 'returns', fn ($join) => $join
                ->on('returns.receipt_id', '=', 'receipt.id')->on('returns.position', '=', 'item.position'))
            ->where('receipt.tenant_id', $tenant->id)
            ->where(fn ($query) => $query->whereIn('receipt.state', ['issued', 'confirmed'])
                ->when($completed && ! $overdue, fn ($query) => $query->orWhere(fn ($query) => $query
                    ->where('receipt.state', 'cancelled')->whereNotNull('returns.returned'))))
            ->when($member, fn ($query) => $query->where('receipt.member_id', $member))
            ->when(! $completed || $overdue, fn ($query) => $query->whereRaw("COALESCE(returns.returned, 0) < (item.data->>'quantity')::integer"))
            ->when($overdue, fn ($query) => $query->where('due.due_on', '<', now('Europe/Zurich')->toDateString()))
            ->selectRaw("receipt.id, receipt.snapshot->>'member' AS member, receipt.title, item.position, item.data->>'description' AS description,
                (item.data->>'quantity')::integer AS issued, COALESCE(returns.returned, 0) AS returned, due.due_on")
            ->orderByDesc('receipt.id')->orderBy('item.position')->paginate(25);
    }

    public function history(Account $actor, Tenant $tenant, int $receipt, int $position)
    {
        abort_unless($this->allows($actor, $tenant, 'view'), 403);
        return $this->table($tenant, 'material_returns')->where('receipt_id', $receipt)
            ->where('position', $position)->orderByDesc('id')->get();
    }

    public function book(Account $actor, Tenant $tenant, int $id, int $position, array $input, string $bookingKey): void
    {
        abort_unless(\Illuminate\Support\Str::isUuid($bookingKey), 422);
        $data = Validator::make($input, [
            'quantity' => 'required|integer|min:1|max:10000',
            'returned_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Europe/Zurich')->toDateString(),
            'condition' => 'required|string|max:500',
        ], attributes: ['quantity' => trans('material_returns.quantity'), 'returned_on' => trans('material_returns.date'),
            'condition' => trans('material_returns.condition')])->validate();

        $this->write($actor, $tenant, $id, $position, function (MaterialReceipt $receipt, int $issued) use ($actor, $tenant, $position, $data, $bookingKey) {
            $query = $this->table($tenant, 'material_returns')->where('receipt_id', $receipt->id)->where('position', $position);
            $existing = (clone $query)->where('booking_key', $bookingKey)->first();
            if ($existing) {
                abort_unless((int) $existing->recorded_by === (int) $actor->id
                    && (int) $existing->quantity === (int) $data['quantity']
                    && $existing->returned_on === $data['returned_on'] && $existing->condition === $data['condition'], 409);
                return;
            }
            if ($data['quantity'] > $issued - $query->sum('quantity')) {
                throw ValidationException::withMessages(['quantity' => trans('material_returns.excess')]);
            }
            if ($data['returned_on'] < $receipt->handover_on->toDateString()) {
                throw ValidationException::withMessages(['returned_on' => trans('material_returns.before_handover')]);
            }
            $query->insert($data + ['tenant_id' => $tenant->id, 'receipt_id' => $receipt->id, 'position' => $position, 'booking_key' => $bookingKey,
                'recorded_by' => $actor->id, 'recorded_name' => $actor->display_name]);
        });
    }

    public function deadline(Account $actor, Tenant $tenant, int $id, int $position, ?string $date): void
    {
        Validator::make(['due_on' => $date], ['due_on' => 'nullable|date_format:Y-m-d'],
            attributes: ['due_on' => trans('material_returns.due')])->validate();
        $this->write($actor, $tenant, $id, $position, function (MaterialReceipt $receipt) use ($tenant, $position, $date) {
            $query = $this->table($tenant, 'material_return_deadlines')->where('receipt_id', $receipt->id)->where('position', $position);
            if ($date === null) {
                $query->delete();
                return;
            }
            $query->upsert(['tenant_id' => $tenant->id, 'receipt_id' => $receipt->id, 'position' => $position, 'due_on' => $date],
                ['tenant_id', 'receipt_id', 'position'], ['due_on']);
        });
    }

    private function write(Account $actor, Tenant $tenant, int $id, int $position, callable $operation): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $position, $operation) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($this->allows($actor, $tenant, 'view') && $this->allows($actor, $tenant, 'update'), 403);
            $receipt = MaterialReceipt::where('tenant_id', $tenant->id)->whereIn('state', ['issued', 'confirmed'])->lockForUpdate()->findOrFail($id);
            $quantity = $receipt->snapshot['items'][$position - 1]['quantity'] ?? null;
            abort_unless($position > 0 && (int) $quantity > 0, 404);
            $operation($receipt, (int) $quantity);
        });
    }
}
