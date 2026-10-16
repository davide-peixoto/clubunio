<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContributionTrainingService
{
    public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input)
    {
        $module = match ($model) {
            ContributionPrice::class, ContributionRule::class => 'contributions',
            TrainingSlot::class => 'training_slots', TrainingOption::class => 'catalogs', default => abort(403),
        };
        app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $id ? 'update' : 'create');
        if (! in_array($model, [ContributionPrice::class, ContributionRule::class, TrainingSlot::class, TrainingOption::class], true)) {
            throw new \InvalidArgumentException('Unsupported model');
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $id, $input, $module) {
            // Serialize catalog edits so overlapping rule inserts cannot race.
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id ? $model::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new $model;
            if ($id && (int) ($input['revision'] ?? 0) !== (int) $record->revision) {
                throw ValidationException::withMessages(['revision' => 'Der Datensatz wurde geändert. Bitte neu laden.']);
            }
            $exists = fn ($table) => Rule::exists('clubunio.clubora.'.$table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true));
            $rules = ['active' => 'required|boolean', 'revision' => $id ? 'required|integer|min:1' : 'nullable|integer|min:1'];
            if ($model === ContributionPrice::class) {
                $rules += ['name' => 'required|string|max:160', 'amount' => 'required|numeric|min:0|max:999999999.99|decimal:0,4', 'bexio_product_id' => 'nullable|string|max:100', 'bexio_article_id' => 'nullable|integer|min:1'];
            } elseif ($model === ContributionRule::class) {
                $rules += ['season_id' => ['required', 'integer', $exists('seasons')], 'price_id' => ['required', 'integer', $exists('contribution_prices')], 'gender_id' => ['required', 'integer', $exists('genders')],
                    'year_from' => 'required|integer|min:1800|max:2200', 'year_to' => 'required|integer|min:1800|max:2200|gte:year_from'];
            } elseif ($model === TrainingOption::class) {
                $rules += ['name' => 'required|string|max:160', 'sort_order' => 'required|integer|min:0|max:10000'];
                if (! $id) {
                    $rules += ['category' => ['required', Rule::in(['period', 'weekday', 'field', 'room', 'side'])], 'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('clubunio.clubora.training_options')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('category', $input['category'] ?? ''))]];
                }
            } else {
                $rules += ['team_season_id' => ['required', 'integer', $exists('team_seasons')], 'starts_at' => 'required|date_format:H:i:s', 'ends_at' => 'required|date_format:H:i:s|after:starts_at', 'hall' => 'nullable|string|max:255'];
                foreach (['period', 'weekday', 'field', 'room', 'side'] as $category) {
                    $rules[$category.'_id'] = [in_array($category, ['period', 'weekday']) ? 'required' : 'nullable', 'integer', Rule::exists('clubunio.clubora.training_options', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('category', $category)->where('active', true))];
                }
                foreach (['all_fields', 'without_main', 'synthetic_only', 'website_visible'] as $field) {
                    $rules[$field] = 'required|boolean';
                }
                foreach (['starts_at', 'ends_at'] as $field) {
                    if (preg_match('/^\d{2}:\d{2}$/', (string) ($input[$field] ?? ''))) {
                        $input[$field] .= ':00';
                    }
                }
            }
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['form' => 'Unbekannte oder geschützte Felder.']);
            }
            $data = Validator::make($input, $rules)->validate();
            app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $id ? 'update' : 'create');
            if ($id && (bool) $record->active !== (bool) $data['active']) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $data['active'] ? 'restore' : 'deactivate');
            }
            if ($model === TrainingSlot::class && empty($data['field_id']) && empty($data['hall'])) {
                throw ValidationException::withMessages(['field_id' => 'Bitte Spielfeld oder Halle angeben.']);
            }
            if ($model === ContributionRule::class && $data['active'] && ContributionRule::where('tenant_id', $tenant->id)->where('season_id', $data['season_id'])->where('gender_id', $data['gender_id'])->where('active', true)->where('id', '!=', $id ?? 0)->where('year_from', '<=', $data['year_to'])->where('year_to', '>=', $data['year_from'])->exists()) {
                throw ValidationException::withMessages(['year_from' => 'Dieser Jahrgangsbereich überschneidet sich mit einem aktiven Beitragssatz.']);
            }
            unset($data['revision']);
            $before = $id ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id, 'revision' => $id ? $record->revision + 1 : 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id,
                'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);

            return $record;
        });
    }
}
