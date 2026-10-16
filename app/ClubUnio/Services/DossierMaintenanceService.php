<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\ClubEvent;
use App\ClubUnio\Models\ClubFunction;
use App\ClubUnio\Models\EventGuest;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberFunction;
use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Models\Qualification;
use App\ClubUnio\Models\Sponsorship;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DossierMaintenanceService
{
    public const MEMBER_MODELS = ['functions' => MemberFunction::class, 'keys' => MemberKey::class, 'sponsorships' => Sponsorship::class, 'eventGuests' => EventGuest::class];

    public static function module(string $model): string
    {
        return match ($model) {
            MemberFunction::class, MemberKey::class, Sponsorship::class => 'members',
            EventGuest::class, ClubEvent::class => 'events',
            ClubFunction::class, Qualification::class => 'catalogs',
            default => throw new \InvalidArgumentException('Unsupported dossier object'),
        };
    }

    public function save(Account $actor, Tenant $tenant, string $model, ?int $memberId, ?int $id, array $input, ?int $copyFrom = null): CatalogModel
    {
        $module = self::module($model);
        abort_if($id !== null && $copyFrom !== null, 422);
        app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $copyFrom !== null ? 'copy' : ($id ? 'view' : 'create'));
        if (! in_array($model, [...array_values(self::MEMBER_MODELS), ClubEvent::class, ClubFunction::class, Qualification::class], true)) {
            throw new \InvalidArgumentException('Unsupported dossier object');
        }
        $memberObject = in_array($model, self::MEMBER_MODELS, true);
        abort_unless($memberObject === ($memberId !== null), 422);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $module, $memberObject, $memberId, $id, $input, $copyFrom) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $copyFrom !== null ? 'copy' : ($id ? 'view' : 'create'));
            if ($copyFrom !== null) {
                $model::where('tenant_id', $tenant->id)->when($memberObject, fn ($q) => $q->where('member_id', $memberId))->lockForUpdate()->findOrFail($copyFrom);
            }
            if ($memberObject) {
                Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($memberId);
            }
            $row = $id ? $model::where('tenant_id', $tenant->id)->when($memberObject, fn ($q) => $q->where('member_id', $memberId))->lockForUpdate()->findOrFail($id) : new $model;
            $reference = fn (string $table, string $field, bool $required = true) => [$required ? 'required' : 'nullable', 'integer',
                Rule::exists('clubunio.clubora.'.$table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $row->$field ?? -1)))];
            $rules = ['revision' => $id ? 'required|integer|min:1' : 'nullable|integer|min:0|max:0', 'active' => 'required|boolean'];
            if (in_array($model, [ClubFunction::class, Qualification::class], true)) {
                $rules['name'] = 'required|string|max:160';
            } elseif ($model === ClubEvent::class) {
                $rules += ['name' => 'required|string|max:255', 'location' => 'required|string|max:255',
                    'season_id' => $reference('seasons', 'season_id'), 'team_id' => $reference('teams', 'team_id', false)];
            } elseif ($model === MemberFunction::class) {
                $rules += ['club_function_id' => $reference('club_functions', 'club_function_id'), 'qualification_ids' => 'present|array|max:50', 'qualification_ids.*' => 'integer|distinct'];
            } elseif ($model === MemberKey::class) {
                $rules += ['number' => 'required|string|max:100', 'name' => 'required|string|max:255', 'color' => 'required|string|max:100'];
            } elseif ($model === Sponsorship::class) {
                $rules += ['name' => 'required|string|max:255', 'amount' => 'required|numeric|min:0|max:9999999999.9999|decimal:0,4',
                    'expires_on' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:4000'];
            } else {
                $rules['club_event_id'] = $reference('club_events', 'club_event_id');
            }
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['active' => 'Unbekannte oder geschützte Felder.']);
            }
            foreach (['name', 'number', 'color', 'location'] as $field) {
                if (isset($input[$field]) && is_string($input[$field])) {
                    $input[$field] = trim($input[$field]);
                }
            }
            $data = Validator::make($input, $rules)->validate();
            if ($id) {
                $statusChanged = (bool) $row->active !== (bool) $data['active'];
                if ($statusChanged) {
                    app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $data['active'] ? 'restore' : 'deactivate');
                }
                $candidate = clone $row;
                $candidate->forceFill(array_diff_key($data, array_flip(['revision', 'active', 'qualification_ids'])));
                $qualificationChanged = $model === MemberFunction::class
                    && collect($data['qualification_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all()
                        !== $row->qualifications()->pluck('clubora.qualifications.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if (! $statusChanged || $candidate->isDirty() || $qualificationChanged) {
                    app(ModulePermissionService::class)->authorize($actor, $tenant, $module, 'update');
                }
            }
            if ($id && (int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Datensatz wurde geändert. Bitte erneut öffnen.']);
            }
            $qualificationIds = $data['qualification_ids'] ?? [];
            $oldQualifications = $model === MemberFunction::class && $id ? $row->qualifications()->pluck('clubora.qualifications.id')->all() : [];
            if ($model === MemberFunction::class) {
                $valid = Qualification::where('tenant_id', $tenant->id)->whereIn('id', $qualificationIds)
                    ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $oldQualifications))->count();
                if ($valid !== count($qualificationIds)) {
                    throw ValidationException::withMessages(['qualification_ids' => 'Nur aktive Qualifikationen dieses Vereins auswählen.']);
                }
            }
            $duplicateFields = match ($model) {
                MemberFunction::class => ['club_function_id'], EventGuest::class => ['club_event_id'], MemberKey::class => ['number', 'color'],
                ClubFunction::class, Qualification::class => ['name'], default => [],
            };
            if ($data['active'] && $duplicateFields) {
                $q = $model::where('tenant_id', $tenant->id)->where('active', true)->when($id, fn ($q) => $q->whereKeyNot($id))->when($memberObject, fn ($q) => $q->where('member_id', $memberId));
                $changed = ! $id || ! $row->active;
                foreach ($duplicateFields as $field) {
                    $q->where($field, $data[$field]);
                    $changed = $changed || (string) $row->$field !== (string) $data[$field];
                }
                if ($changed && $q->exists()) {
                    throw ValidationException::withMessages([$duplicateFields[0] => 'Diese aktive Zuordnung besteht bereits.']);
                }
            }
            unset($data['revision'], $data['qualification_ids']);
            $before = $id ? $row->only([...array_keys($data), 'revision']) : null;
            if ($before !== null && $model === MemberFunction::class) {
                $before['qualification_ids'] = $oldQualifications;
            }
            $row->forceFill($data + ['tenant_id' => $tenant->id]);
            if ($memberObject) {
                $row->member_id = $memberId;
            }
            $row->revision = $id ? $row->revision + 1 : 1;
            $row->save();
            if ($model === MemberFunction::class) {
                $row->qualifications()->sync(array_fill_keys($qualificationIds, ['tenant_id' => $tenant->id]));
            }
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => str($row->getTable())->afterLast('.')->toString(), 'object_id' => $row->id,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($row->only([...array_keys($data), 'member_id', 'revision']) + ($model === MemberFunction::class ? ['qualification_ids' => $qualificationIds] : []), JSON_THROW_ON_ERROR)]);

            return $row;
        });
    }
}
