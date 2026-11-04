<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\LookupTranslation;
use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LookupTranslations
{
    private array $variants = [];

    private ?bool $available = null;

    public function tables(): array
    {
        return config('clubunio-lookups.tables', []);
    }

    public function tableName(mixed $table): ?string
    {
        if (! is_string($table)) {
            return null;
        }
        $name = str_starts_with($table, 'clubora.') ? substr($table, 8) : $table;

        return array_key_exists($name, $this->tables()) ? $name : null;
    }

    public function sourceKey(string $table): string
    {
        return config('clubunio-lookups.global.'.$table, 'id');
    }

    public function isGlobal(string $table): bool
    {
        return array_key_exists($table, config('clubunio-lookups.global', []));
    }

    public function available(): bool
    {
        return $this->available ??= DB::connection('clubunio')->getSchemaBuilder()->hasTable('clubora.lookup_translations');
    }

    public function allowed(?Account $actor, ?Tenant $tenant): bool
    {
        return $actor && $tenant && ! $actor->api_only
            && ! (request()->hasSession() && request()->session()->has(ImpersonationService::KEY))
            && app(AccountAdministrationService::class)->allowed($actor, $tenant);
    }

    private function variants(string $table, ?int $tenant, string $locale): Collection
    {
        if (! $tenant || ! $this->available()) {
            return collect();
        }
        $key = $tenant.':'.$table.':'.$locale;

        return $this->variants[$key] ??= LookupTranslation::where('tenant_id', $tenant)
            ->where('source_table', $table)->where('locale', $locale)->get()->keyBy('source_id');
    }

    public function label(string $table, object|array $row, ?string $locale = null, ?int $tenant = null): mixed
    {
        $raw = data_get($row, 'name');
        $table = $this->tableName($table);
        $locale ??= app()->getLocale();
        if (! $table || ! is_string($raw) || ! in_array($locale, ['de', 'en'], true)
            || ! array_key_exists($locale, config('clubunio-languages.enabled', []))) {
            return $raw;
        }
        $rowTenant = data_get($row, 'tenant_id');
        if (! $this->isGlobal($table)) {
            if (! $rowTenant || ($tenant !== null && $tenant !== (int) $rowTenant)) {
                return $raw;
            }
            $tenant = (int) $rowTenant;
        }
        $id = data_get($row, $this->sourceKey($table));
        $variant = $id !== null ? $this->variants($table, $tenant, $locale)->get((string) $id) : null;
        // An edited source label invalidates its old display override, not its raw value.
        if ($variant && $variant->source_name === $raw) {
            return $variant->label;
        }
        if ($table === 'countries' && class_exists(\Locale::class)) {
            foreach (['code', 'iso3'] as $field) {
                $code = data_get($row, $field);
                if (is_string($code) && preg_match('/^[A-Z]{2,3}$/D', $code)) {
                    $name = \Locale::getDisplayRegion('und_'.$code, $locale);
                    if ($name && $name !== $code) {
                        return $name;
                    }
                }
            }
        }

        return $locale === 'en' ? (config('clubunio-lookups.en', [])[$raw] ?? $raw) : $raw;
    }

    public function localizedPluck(Builder|EloquentBuilder $builder, mixed $column, mixed $key = null): Collection
    {
        $query = $builder instanceof EloquentBuilder ? (clone $builder)->toBase() : clone $builder;
        $table = $this->tableName($query->from);
        // Complex projections keep native pluck semantics rather than guessing row identity.
        if ($query->getConnection()->getName() !== 'clubunio' || ! $table
            || ! in_array($column, ['name', $table.'.name', 'clubora.'.$table.'.name'], true)
            || ($key !== null && (! is_string($key) || ! preg_match('/^(?:clubora\.)?(?:'.$table.'\.)?[a-z_][a-z0-9_]*$/D', $key)))
            || $query->joins || $query->groups || $query->havings || $query->unions || $query->distinct) {
            return $builder->pluck($column, $key);
        }
        $keyField = $key === null ? null : substr(strrchr('.'.$key, '.'), 1);
        $columns = ['name', $this->sourceKey($table)];
        if (! $this->isGlobal($table)) {
            $columns[] = 'tenant_id';
        }
        if ($table === 'countries') {
            $columns = [...$columns, 'code', 'iso3'];
        }
        if ($keyField !== null) {
            $columns[] = $keyField;
        }
        $rows = $query->select(array_unique($columns))->get();
        $tenant = $this->isGlobal($table) ? Filament::getTenant()?->getKey() : null;
        $result = collect();
        foreach ($rows as $row) {
            $label = $this->label($table, $row, tenant: $tenant);
            if ($keyField === null) {
                $result->push($label);
            } else {
                $result->put($row->{$keyField}, $label);
            }
        }

        return $result;
    }

    public function formatDisplay(string $path, mixed $state, ?Model $record, ?Model $relatedRecord = null): mixed
    {
        if ($path !== 'name' && ! str_ends_with($path, '.name')) {
            return $state;
        }
        $target = $relatedRecord;
        if (! $target) {
            $target = $record;
            foreach (array_slice(explode('.', $path), 0, -1) as $relation) {
                // Formatting must not trigger lazy-loading or invent a relation from text.
                if (! $target instanceof Model || ! $target->relationLoaded($relation)) {
                    return $state;
                }
                $target = $target->getRelation($relation);
            }
        }
        if (! $target instanceof CatalogModel || $target->getConnectionName() !== 'clubunio'
            || ! $this->tableName($target->getTable()) || $target->getRawOriginal('name') !== $state) {
            return $state;
        }
        $tenant = $record?->getAttribute('tenant_id') ?? Filament::getTenant()?->getKey();

        return $this->label($target->getTable(), $target->getAttributes(), tenant: $tenant === null ? null : (int) $tenant);
    }

    public function sourceOptions(string $table, Tenant $tenant, ?string $search = null): array
    {
        abort_unless($this->tableName($table) === $table, 422);
        $query = DB::connection('clubunio')->table('clubora.'.$table);
        if (! $this->isGlobal($table)) {
            $query->where('tenant_id', $tenant->id);
        }
        if ($search !== null && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query->orderBy('name')->limit(100)->pluck('name', $this->sourceKey($table))->all();
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): LookupTranslation
    {
        abort_unless($this->allowed($actor, $tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($actor, $tenant), 403);
            $rules = [
                'source_table' => ['required', Rule::in(array_keys($this->tables()))],
                'source_id' => 'required|string|max:80', 'source_name' => 'required|string|max:1000',
                'locale' => ['required', Rule::in(array_intersect(['de', 'en'], array_keys(config('clubunio-languages.enabled', []))))],
                'label' => 'required|string|max:255', 'revision' => 'required|integer|min:0',
                'reason' => 'required|string|min:10|max:500',
            ];
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['label' => __('Unerlaubte Übersetzungsfelder.')]);
            }
            if (is_int($input['source_id'] ?? null)) {
                $input['source_id'] = (string) $input['source_id'];
            }
            $data = Validator::make($input, $rules)->validate();
            if (trim($data['label']) === '' || strip_tags($data['label']) !== $data['label']) {
                throw ValidationException::withMessages(['label' => __('Die Übersetzung muss reiner Text sein.')]);
            }
            $source = DB::connection('clubunio')->table('clubora.'.$data['source_table'])
                ->where($this->sourceKey($data['source_table']), $data['source_id']);
            if (! $this->isGlobal($data['source_table'])) {
                $source->where('tenant_id', $tenant->id);
            }
            $source = $source->lockForUpdate()->first();
            abort_unless($source, 404);
            $record = $id ? LookupTranslation::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new LookupTranslation;
            $identity = ['source_table' => $data['source_table'], 'source_id' => $data['source_id'], 'locale' => $data['locale']];
            if ($source->name !== $data['source_name'] || (int) ($record->revision ?? 0) !== (int) $data['revision']
                || ($id && $record->only(array_keys($identity)) !== $identity)
                || (! $id && LookupTranslation::where('tenant_id', $tenant->id)->where($identity)->exists())) {
                throw ValidationException::withMessages(['revision' => __('Die Lookup-Übersetzung oder Quelle wurde geändert. Bitte neu öffnen.')]);
            }
            $before = $record->exists ? $record->getAttributes() : null;
            $record->forceFill($identity + ['tenant_id' => $tenant->id, 'source_name' => $source->name,
                'label' => $data['label'], 'revision' => $data['revision'] + 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'lookup_translations', 'object_id' => $record->id,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($record->getAttributes() + ['reason' => $data['reason']], JSON_THROW_ON_ERROR),
            ]);
            $this->variants = [];

            return $record;
        });
    }
}
