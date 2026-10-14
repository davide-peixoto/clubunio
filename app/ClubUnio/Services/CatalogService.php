<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Bank;
use App\ClubUnio\Models\BillingRecipient;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\ExpenseRound;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\GameType;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceStatus;
use App\ClubUnio\Models\InvoiceWorkflowStatus;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\StaffFunction;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input): CatalogModel
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        if (! in_array($model, [InvoiceCategory::class, InvoiceStatus::class, InvoiceLineStatus::class, InvoiceWorkflowStatus::class, Team::class, Season::class, MemberStatus::class, MemberType::class, RegistrationStatus::class, StaffFunction::class, Country::class, Gender::class, BillingRecipient::class, DocumentType::class, Bank::class, ExpenseType::class, ExpenseRound::class, ExpenseStatus::class, GameType::class], true)) {
            throw new \InvalidArgumentException('Unsupported catalog');
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $id, $input) {
            // Serialize mapping changes with invoice status reconciliation.
            if (in_array($model, [InvoiceStatus::class, InvoiceLineStatus::class], true)) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            }
            $record = $id ? $model::where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            $rules = ['name' => 'required|string|max:160', 'active' => 'required|boolean', 'tenant_id' => 'prohibited', 'legacy_id' => 'prohibited'];
            if ($model === Team::class) {
                $rules += ['description' => 'nullable|string|max:4000', 'sfv_team_id' => 'nullable|integer|min:1', 'laundry_allowance' => 'nullable|numeric|min:0|max:9999999999.99|decimal:0,2'];
            } elseif ($model !== Season::class) {
                $rules += ['sort_order' => 'required|integer|min:0|max:100000'];
                $rules['code'] = $id ? 'prohibited' : ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('clubunio.'.$record->getTable(), 'code')->where('tenant_id', $tenant->id)];
            } else {
                $rules['name'] = 'required|string|max:100';
                $rules += ['starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on'];
            }
            if ($model === Country::class) {
                $rules['iso3'] = $id ? 'prohibited' : ['required', 'regex:/^[A-Z]{3}$/', Rule::unique('clubunio.clubora.countries', 'iso3')->where('tenant_id', $tenant->id)];
            }
            if ($model === Country::class && ! $id) {
                $rules['code'] = ['required', 'regex:/^[A-Z]{2}$/', Rule::unique('clubunio.clubora.countries', 'code')->where('tenant_id', $tenant->id)];
            }
            if ($model === BillingRecipient::class) {
                $rules['email_field'] = $id ? 'prohibited' : ['required', Rule::in(['email', 'mother_email', 'father_email'])];
            }
            if ($model === MemberStatus::class) {
                $rules += ['ends_membership' => 'sometimes|boolean', 'restores_membership' => 'sometimes|boolean', 'spots_eligible' => 'sometimes|boolean'];
            }
            if ($model === MemberType::class) {
                $rules += ['team_required' => 'sometimes|boolean', 'passive_membership' => 'sometimes|boolean'];
            }
            if ($model === Bank::class) {
                $rules += ['city' => 'nullable|string|max:255', 'swift' => 'nullable|string|max:20'];
                $rules += ['street' => 'nullable|string|max:255', 'postal_address' => 'nullable|string|max:255', 'postal_code' => 'nullable|string|max:30',
                    'country_code' => 'nullable|string|max:3', 'clearing_number' => 'nullable|string|max:30', 'postal_account' => 'nullable|string|max:60'];
            }
            if ($model === ExpenseType::class) {
                $rules += ['receipt_required' => 'required|boolean', 'amount_source' => ['required', Rule::in(['manual', 'receipt_ai', 'team_laundry'])]];
            }
            if ($model === ExpenseStatus::class && ! $id) {
                $rules['code'][] = Rule::in(['O', 'F', 'Z', 'B', 'R']);
            }
            if ($model === InvoiceWorkflowStatus::class && ! $id) {
                $rules['code'][] = Rule::in(['draft', 'released', 'cancelled']);
            }
            if (in_array($model, [InvoiceStatus::class, InvoiceLineStatus::class], true)) {
                unset($rules['sort_order']);
            }
            if ($model === InvoiceStatus::class) {
                $rules['position_code'] = ['required', 'string', Rule::exists('clubunio.clubora.invoice_line_statuses', 'code')->where('tenant_id', $tenant->id)->where('active', true)];
                if (! $id) {
                    $rules['code'][] = 'regex:/^[0-9]{1,10}$/';
                }
            }
            $data = Validator::make($input, $rules)->validate();
            if ($model === InvoiceLineStatus::class && $id && ! $data['active'] && InvoiceStatus::where('tenant_id', $tenant->id)->where('active', true)->where('position_code', $record->code)->exists()) {
                throw ValidationException::withMessages(['active' => 'Dieser Positionsstatus wird noch von einer aktiven Bexio-Zuordnung verwendet.']);
            }
            $data = array_diff_key($data, array_flip(['tenant_id', 'legacy_id']));
            if ($id) {
                $data = array_diff_key($data, array_flip(['code', 'iso3', 'email_field']));
            }
            if ($model === MemberStatus::class && ($data['ends_membership'] ?? $record->ends_membership) && ($data['restores_membership'] ?? $record->restores_membership)) {
                throw ValidationException::withMessages(['restores_membership' => 'Austritt und Wiedereintritt dürfen nicht gleichzeitig aktiv sein.']);
            }
            $before = $record->exists ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id])->save();
            if (in_array($model, [Team::class, Season::class], true)) {
                DB::connection('clubunio')->update("UPDATE clubora.team_seasons ts SET label=t.name || ' / ' || s.name
                    FROM clubora.teams t,clubora.seasons s WHERE ts.tenant_id=? AND t.tenant_id=ts.tenant_id AND s.tenant_id=ts.tenant_id AND t.id=ts.team_id AND s.id=ts.season_id", [$tenant->id]);
            }
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id,
                'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);

            return $record;
        });
    }
}
