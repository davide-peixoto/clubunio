<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public function visible(Account $actor, Tenant $tenant)
    {
        $q = MemberBankAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id);
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view')) {
            return $q;
        }
        $access = app(MemberAccessService::class);
        if (app(ModulePermissionService::class)->isProfile($actor, $tenant) || ! $access->canEnter($actor, $tenant)) {
            return $q->whereRaw('1=0');
        }

        // A technical lead's team scope never extends to bank details.
        return $q->whereIn('member_id', $access->represented($actor, $tenant, true)->select('clubora.members.id'));
    }

    public function save(Account $actor, Tenant $tenant, int $memberId, ?int $id, array $input): MemberBankAccount
    {
        if (app(ModulePermissionService::class)->isProfile($actor, $tenant)) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'members', $id ? 'view' : 'create');
        }
        if (array_diff(array_keys($input), ['revision', 'bank_id', 'iban', 'active'])) {
            throw ValidationException::withMessages(['iban' => 'Nicht erlaubte Eingabefelder.']);
        }
        $access = app(MemberAccessService::class);
        abort_unless(app(ModulePermissionService::class)->allows($actor, $tenant, 'members', $id ? 'view' : 'create') || ($access->canEnter($actor, $tenant) && $access->represented($actor, $tenant, true)->whereKey($memberId)->exists()), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $memberId, $id, $input) {
            Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($memberId);
            $bank = $id ? $this->visible($actor, $tenant)->where('member_id', $memberId)->lockForUpdate()->findOrFail($id) : new MemberBankAccount;
            if ($id && (int) ($input['revision'] ?? 0) !== (int) $bank->revision) {
                throw ValidationException::withMessages(['revision' => 'Das Konto wurde inzwischen geändert.']);
            }
            $data = Validator::make($input, [
                'bank_id' => ['required', 'integer', Rule::exists('clubunio.clubora.banks', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true))],
                'iban' => [$id ? 'nullable' : 'required', 'string', 'max:60'], 'active' => 'required|boolean',
            ])->validate();
            $access = app(MemberAccessService::class);
            $own = $access->canEnter($actor, $tenant) && $access->represented($actor, $tenant, true)->whereKey($memberId)->exists();
            $iban = isset($data['iban']) && trim($data['iban']) !== '' ? $this->normalize($data['iban']) : ($id ? Crypt::decryptString($bank->iban_encrypted) : '');
            if (! $own) {
                $statusChanged = $id && (bool) $bank->active !== (bool) $data['active'];
                if ($statusChanged) {
                    app(ModulePermissionService::class)->authorize($actor, $tenant, 'members', $data['active'] ? 'restore' : 'deactivate');
                }
                if (! $statusChanged || (int) $bank->bank_id !== (int) $data['bank_id'] || $iban !== Crypt::decryptString($bank->iban_encrypted)) {
                    app(ModulePermissionService::class)->authorize($actor, $tenant, 'members', $id ? 'update' : 'create');
                }
            }
            if (! $id || $iban !== Crypt::decryptString($bank->iban_encrypted)) {
                $this->validateIban($iban);
            }
            $used = $id && DB::connection('clubunio')->table('clubora.expenses')->where('tenant_id', $tenant->id)->where('bank_account_id', $id)->exists();
            if ($used && ($iban !== Crypt::decryptString($bank->iban_encrypted) || (int) $data['bank_id'] !== (int) $bank->bank_id)) {
                throw ValidationException::withMessages(['iban' => 'Dieses Konto ist bereits in Belegen verwendet. Bitte ein neues Konto erfassen.']);
            }
            foreach ($this->visible($actor, $tenant)->where('member_id', $memberId)->where('active', true)->when($id, fn ($q) => $q->where('id', '!=', $id))->get() as $existing) {
                if ($this->normalize(Crypt::decryptString($existing->iban_encrypted)) === $iban && $data['active']) {
                    throw ValidationException::withMessages(['iban' => 'Dieses Konto ist bereits erfasst.']);
                }
            }
            $before = $id ? ['bank_id' => $bank->bank_id, 'active' => $bank->active, 'revision' => $bank->revision, 'iban_last4' => substr(Crypt::decryptString($bank->iban_encrypted), -4)] : null;
            $bank->forceFill(['tenant_id' => $tenant->id, 'member_id' => $memberId, 'bank_id' => $data['bank_id'], 'iban_encrypted' => Crypt::encryptString($iban), 'active' => $data['active'], 'revision' => $id ? $bank->revision + 1 : 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'member_bank_accounts', 'object_id' => $bank->id,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_value' => json_encode(['bank_id' => $bank->bank_id, 'active' => $bank->active, 'revision' => $bank->revision, 'iban_last4' => substr($iban, -4)], JSON_THROW_ON_ERROR)]);

            return $bank;
        });
    }

    private function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/u', '', $iban));
    }

    private function validateIban(string $iban): void
    {
        $valid = (bool) preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban);
        if (in_array(substr($iban, 0, 2), ['CH', 'LI'], true) && strlen($iban) !== 21) {
            $valid = false;
        }
        $remainder = 0;
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        if (! $valid || $remainder !== 1) {
            throw ValidationException::withMessages(['iban' => 'Die IBAN oder ihre Prüfziffer ist ungültig.']);
        }
    }
}
