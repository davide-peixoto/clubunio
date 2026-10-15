<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\SavedView;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavedViewService
{
    public const LISTS = ['customer-credits', 'credit-notes', 'refunds', 'members', 'member-reports', 'registrations', 'expenses', 'invoices', 'training-slots', 'open-invoices', 'player-reports', 'staff-reports', 'payment-reports', 'unassigned-members', 'passive-members', 'competitions', 'signage-notices', 'family-reports', 'bank-account-reports', 'football-clubs', 'key-reports', 'spots-recipients'];

    public function query(Account $actor, Tenant $tenant, string $list)
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant) && in_array($list, self::LISTS, true), 403);

        return SavedView::where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('list_key', $list);
    }

    public function save(Account $actor, Tenant $tenant, string $list, ?int $id, array $input, array $state): SavedView
    {
        $query = $this->query($actor, $tenant, $list);
        $input['name'] = trim($input['name'] ?? '');
        $data = Validator::make($input, ['name' => 'required|string|max:80', 'is_default' => 'required|boolean', 'revision' => 'nullable|integer|min:1'])->validate();
        Validator::make($state, ['version' => 'required|in:1', 'filters' => 'present|array', 'search' => 'present|nullable|string|max:200', 'sort' => 'nullable|string|max:160', 'tab' => 'nullable|string|max:160', 'columns' => 'present|array'])->validate();
        if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 16000 || array_diff(array_keys($state), ['version', 'filters', 'search', 'sort', 'tab', 'columns'])) {
            throw ValidationException::withMessages(['name' => 'Diese Ansicht ist zu umfangreich oder ungültig.']);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $list, $id, $query, $data, $state) {
            // Serializes default switches and uniqueness checks for one account.
            Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $record = $id ? (clone $query)->lockForUpdate()->findOrFail($id) : new SavedView;
            if ($id && $record->revision !== (int) ($data['revision'] ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Ansicht wurde geändert. Bitte neu öffnen.']);
            }
            if ((clone $query)->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Eine Ansicht mit diesem Namen existiert bereits.']);
            }
            if (! $id && (clone $query)->count() >= 20) {
                throw ValidationException::withMessages(['name' => 'Maximal 20 Ansichten je Liste.']);
            }
            if ($data['is_default']) {
                (clone $query)->where('is_default', true)->when($id, fn ($q) => $q->where('id', '<>', $id))
                    ->update(['is_default' => false, 'revision' => DB::connection('clubunio')->raw('revision + 1'), 'updated_at' => now()]);
            }
            $record->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'list_key' => $list,
                'name' => $data['name'], 'is_default' => $data['is_default'], 'state_encrypted' => $state,
                'revision' => $record->exists ? $record->revision + 1 : 1])->save();

            return $record;
        });
    }

    public function delete(Account $actor, Tenant $tenant, string $list, int $id, int $revision): void
    {
        $changed = $this->query($actor, $tenant, $list)->whereKey($id)->where('revision', $revision)->delete();
        if (! $changed) {
            throw ValidationException::withMessages(['view_id' => 'Ansicht wurde geändert oder ist nicht verfügbar.']);
        }
    }
}
