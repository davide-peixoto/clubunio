<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function visible(Account $actor, Tenant $tenant): Builder
    {
        $q = Expense::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true);
        if ($actor->canAccessTenant($tenant)) {
            return $q;
        }
        $access = app(MemberAccessService::class);
        if (! $access->canEnter($actor, $tenant)) {
            return $q->whereRaw('1=0');
        }

        return $q->where(function ($q) use ($access, $actor, $tenant) {
            $q->whereIn('member_id', $access->represented($actor, $tenant, true)->select('clubora.members.id'));
            if ($access->membership($actor, $tenant)?->role === 'technical_lead') {
                $q->orWhereIn('team_season_id', $access->responsibleTeams($actor, $tenant));
            }
        });
    }

    public function targets(Account $actor, Tenant $tenant, Expense $expense): array
    {
        if (! $this->visible($actor, $tenant)->whereKey($expense->id)->exists()) {
            return [];
        }
        $old = $expense->status?->code;
        $office = $actor->canAccessTenant($tenant);
        if ($office) {
            return match ($old) {
                'O' => ['F', 'R'], 'F' => ['O', 'Z', 'R'], 'Z' => ['B'], 'R' => ['O'], default => [],
            };
        }
        $access = app(MemberAccessService::class);
        if ($old === 'O' && $access->represented($actor, $tenant, true)->whereKey($expense->member_id)->exists()) {
            return ['F'];
        }
        if ($old === 'F' && $access->membership($actor, $tenant)?->role === 'technical_lead'
            && in_array($expense->team_season_id, $access->responsibleTeams($actor, $tenant))) {
            return ['Z', 'R'];
        }

        return [];
    }

    public function transition(Account $actor, Tenant $tenant, int $id, array $input): Expense
    {
        $this->fields($input, ['revision', 'status_id', 'reason']);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'status_id' => 'required|integer', 'reason' => 'required|string|min:10|max:500'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            $expense = $this->visible($actor, $tenant)->lockForUpdate()->findOrFail($id);
            $this->revision($expense, $data['revision']);
            $status = ExpenseStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->findOrFail($data['status_id']);
            if ((int) $expense->status_id === (int) $status->id) {
                return $expense;
            }
            abort_unless(in_array($status->code, $this->targets($actor, $tenant, $expense), true), 403);
            if (in_array($status->code, ['F', 'Z'], true)) {
                if (! $expense->activeLines()->exists() || $expense->activeLines()->where(fn ($q) => $q->whereNull('amount')->orWhere('amount', '<=', 0))->exists()) {
                    throw ValidationException::withMessages(['status_id' => 'Der Beleg benötigt gültige Positionen mit positivem Betrag.']);
                }
                if (! MemberBankAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $expense->member_id)->where('active', true)->whereKey($expense->bank_account_id)->exists()) {
                    throw ValidationException::withMessages(['status_id' => 'Bitte ein aktives eigenes Bankkonto hinterlegen.']);
                }
                if ($expense->activeLines()->whereHas('expenseType', fn ($q) => $q->where('receipt_required', true))->where(fn ($q) => $q->whereNull('storage_reference')->orWhere('storage_reference', ''))->exists()) {
                    throw ValidationException::withMessages(['status_id' => 'Für eine Position fehlt der erforderliche Beleg.']);
                }
            }
            $before = $expense->only(['status_id', 'revision']);
            $today = now('Europe/Zurich')->toDateString();
            $values = ['status_id' => $status->id, 'revision' => $expense->revision + 1];
            if (in_array($status->code, ['O', 'R'], true)) {
                foreach (['released_on', 'released_by', 'payment_released_on', 'payment_released_by', 'paid_on', 'paid_by'] as $field) {
                    $values[$field] = null;
                }
            } elseif ($status->code === 'F') {
                $values += ['released_on' => $today, 'released_by' => $actor->username];
            } elseif ($status->code === 'Z') {
                $values += ['payment_released_on' => $today, 'payment_released_by' => $actor->username];
            } elseif ($status->code === 'B') {
                $values += ['paid_on' => $today, 'paid_by' => $actor->username];
            }
            $expense->forceFill($values)->save();
            $this->audit($actor, $tenant, 'expenses', $expense->id, $before, $values + ['reason' => $data['reason']]);
            app(ExpenseMessageService::class)->record($tenant, $expense->fresh(['status', 'member', 'season', 'round', 'teamSeason.team']));

            return $expense->refresh();
        });
    }

    public function editable(Account $actor, Tenant $tenant, Expense $expense): bool
    {
        if ($expense->status?->code !== 'O' || ! $this->visible($actor, $tenant)->whereKey($expense->id)->exists()) {
            return false;
        }

        return $actor->canAccessTenant($tenant) || app(MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($expense->member_id)->exists();
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): Expense
    {
        $this->fields($input, ['revision', 'member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            $e = $id ? $this->visible($actor, $tenant)->lockForUpdate()->findOrFail($id) : new Expense;
            if ($id) {
                abort_unless($this->editable($actor, $tenant, $e), 403);
                $this->revision($e, $input['revision'] ?? 0);
            } else {
                abort_unless($actor->canAccessTenant($tenant) || app(MemberAccessService::class)->canEnter($actor, $tenant), 403);
            }
            $values = array_replace($id ? $e->only(['member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']) : [], $input);
            $data = Validator::make($values, [
                'member_id' => 'required|integer', 'team_season_id' => 'required|integer', 'bank_account_id' => 'required|integer',
                'round_id' => ['required', 'integer', Rule::exists('clubunio.clubora.expense_rounds', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true))],
                'notes' => 'nullable|string|max:4000',
            ])->validate();
            $access = app(MemberAccessService::class);
            if (! $actor->canAccessTenant($tenant)) {
                abort_unless($access->represented($actor, $tenant, true)->whereKey($data['member_id'])->exists(), 403);
                // Personal claims only for a team on which the member is active staff.
                abort_unless(DB::connection('clubunio')->table('clubora.team_staff')->where('tenant_id', $tenant->id)->where('active', true)->where('member_id', $data['member_id'])->where('team_season_id', $data['team_season_id'])->exists(), 403);
            }
            $team = TeamSeason::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereHas('team', fn ($q) => $q->where('active', true))->findOrFail($data['team_season_id']);
            if (! $access->activeMembers($tenant)->whereKey($data['member_id'])->exists()) {
                throw ValidationException::withMessages(['member_id' => 'Bitte ein aktives Mitglied wählen.']);
            }
            if (! MemberBankAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $data['member_id'])->where('active', true)->whereKey($data['bank_account_id'])->exists()) {
                throw ValidationException::withMessages(['bank_account_id' => 'Das Konto gehört nicht zu diesem Mitglied.']);
            }
            if ($id && $e->lines()->exists() && ((int) $e->member_id !== (int) $data['member_id'] || (int) $e->team_season_id !== (int) $data['team_season_id'])) {
                throw ValidationException::withMessages(['team_season_id' => 'Beleginhaber und Mannschaft können bei vorhandenen Positionen nicht geändert werden.']);
            }
            $before = $id ? $e->only(array_keys($data)) : null;
            if (! $id) {
                $status = ExpenseStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', 'O')->where('active', true)->firstOrFail();
                $data += ['status_id' => $status->id];
            }
            $e->forceFill($data + ['tenant_id' => $tenant->id, 'season_id' => $team->season_id, 'revision' => $id ? $e->revision + 1 : 1, 'active' => true])->save();
            $this->audit($actor, $tenant, 'expenses', $e->id, $before, $data);

            return $e;
        });
    }

    public function suggestion(Tenant $tenant, Expense $expense, int $type): ?string
    {
        $t = ExpenseType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->findOrFail($type);
        if ($t->amount_source !== 'team_laundry') {
            return null;
        }

        return $expense->teamSeason?->team?->laundry_allowance;
    }

    public function saveLine(Account $actor, Tenant $tenant, int $expenseId, ?int $id, array $input): ExpenseLine
    {
        $this->fields($input, ['revision', 'expense_revision', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes', 'active']);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $expenseId, $id, $input) {
            $e = $this->visible($actor, $tenant)->lockForUpdate()->findOrFail($expenseId);
            abort_unless($this->editable($actor, $tenant, $e), 403);
            $this->revision($e, $input['expense_revision'] ?? 0);
            $line = $id ? ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('expense_id', $e->id)->lockForUpdate()->findOrFail($id) : new ExpenseLine;
            if ($id) {
                $this->revision($line, $input['revision'] ?? 0);
            }
            $fields = ['expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes', 'active'];
            $values = array_replace($id ? $line->only($fields) : ['active' => true], array_intersect_key($input, array_flip($fields)));
            $rules = ['expense_type_id' => ['required', 'integer', Rule::exists('clubunio.clubora.expense_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true))],
                'game_type_id' => ['nullable', 'integer', Rule::exists('clubunio.clubora.game_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true))],
                'amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,4})?$/'], 'opponent' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:4000', 'active' => 'required|boolean'];
            $data = Validator::make($values, $rules)->validate();
            $changed = ! $id || (int) $line->expense_type_id !== (int) $data['expense_type_id'];
            $suggested = $changed ? $this->suggestion($tenant, $e, $data['expense_type_id']) : $line->suggested_amount;
            if (($data['amount'] ?? null) === null && $suggested !== null) {
                $data['amount'] = $suggested;
            }
            if ($this->units($data['amount'] ?? '0') <= 0) {
                throw ValidationException::withMessages(['amount' => 'Bitte einen positiven Betrag erfassen.']);
            }
            $before = $id ? $line->only($fields) : null;
            $data += ['suggested_amount' => $suggested, 'suggestion_accepted' => $suggested === null || $this->units($data['amount']) === $this->units($suggested)];
            $line->forceFill($data + ['tenant_id' => $tenant->id, 'expense_id' => $e->id, 'revision' => $id ? $line->revision + 1 : 1])->save();
            $e->forceFill(['revision' => $e->revision + 1])->save();
            $this->audit($actor, $tenant, 'expense_lines', $line->id, $before, $data);

            return $line;
        });
    }

    private function units(string $amount): int
    {
        [$whole,$fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 10000 + (int) str_pad(substr($fraction, 0, 4), 4, '0');
    }

    private function revision($record, $revision): void
    {
        if ((int) $record->revision !== (int) $revision) {
            throw ValidationException::withMessages(['revision' => 'Der Datensatz wurde inzwischen geändert. Bitte erneut öffnen.']);
        }
    }

    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['revision' => 'Nicht erlaubte Eingabefelder.']);
        }
    }

    private function audit(Account $actor, Tenant $tenant, string $type, int $id, ?array $before, array $after): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $type, 'object_id' => $id,
            'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
