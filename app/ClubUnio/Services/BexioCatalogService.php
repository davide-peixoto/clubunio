<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BexioCatalogService
{
    public function articleOptions(Tenant $tenant): array
    {
        $hash = app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        if ($hash === null) {
            return [];
        }

        return BexioCatalogEntry::where('tenant_id', $tenant->id)->where('kind', 'article')->where('active', true)->where('provider_active', true)
            ->where('connection_hash', $hash)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function invoiceArticle(Tenant $tenant, int $id): BexioCatalogEntry
    {
        $record = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('kind', 'article')->where('active', true)->where('provider_active', true)
            ->where('connection_hash', app(BexioSandboxFixtureService::class)->connectionHash($tenant))->find($id);
        abort_unless($record, 422, 'Artikel ist nicht für diese Verbindung freigegeben.');

        return $record;
    }

    public function verifyRemoteArticles(Tenant $tenant, string $token, array $positions): void
    {
        foreach (array_unique(array_filter(array_column($positions, 'article_id'))) as $id) {
            $entry = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('kind', 'article')->where('remote_id', $id)
                ->where('connection_hash', app(BexioSandboxFixtureService::class)->connectionHash($tenant))->first();
            abort_unless($entry, 422, 'Artikel muss zuerst im Katalog abgeglichen werden.');
            $this->invoiceArticle($tenant, $entry->id);
            $remote = app(BexioSandboxClient::class)->get($token, '/2.0/article/'.$id);
            abort_unless((int) ($remote['id'] ?? 0) === (int) $entry->remote_id
                && (string) ($remote['intern_code'] ?? '') === $entry->code
                && (string) ($remote['intern_name'] ?? '') === $entry->provider_name, 409, 'Bexio-Artikel wurde geändert. Katalog neu abgleichen.');
            foreach (['unit_id', 'currency_id', 'tax_income_id', 'account_id'] as $field) {
                if (array_key_exists($field, $entry->provider_data)) {
                    abort_unless((string) ($remote[$field] ?? '') === (string) ($entry->provider_data[$field] ?? ''), 409, 'Bexio-Artikelzuordnung wurde geändert. Katalog neu abgleichen.');
                }
            }
        }
    }

    private function binding(Account $actor, Tenant $tenant): string
    {
        app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);
        $binding = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($binding && hash_equals($hash, $binding->connection_hash), 409, 'Sandbox-Verbindung zuerst bestätigen.');

        return $hash;
    }

    public function sync(Account $actor, Tenant $tenant): int
    {
        $hash = $this->binding($actor, $tenant);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        $taxes = $client->get($token, '/3.0/taxes');
        abort_unless(array_is_list($taxes) && count($taxes) <= 5000, 502, 'Ungültiger Steuerkatalog.');
        $rows = [];
        foreach ($taxes as $tax) {
            if (! in_array($tax['type'] ?? '', ['sales_tax', 'not_taxable_turnover'], true)) {
                continue;
            }
            $rows[] = $this->normalizeTax($tax);
        }
        $finished = false;
        for ($offset = 0; $offset < 10000; $offset += 500) {
            $articles = $client->get($token, '/2.0/article', ['limit' => 500, 'offset' => $offset]);
            abort_unless(array_is_list($articles) && count($articles) <= 500, 502, 'Ungültiger Artikelkatalog.');
            foreach ($articles as $article) {
                $rows[] = $this->article($article);
            }
            if (count($articles) < 500) {
                $finished = true;
                break;
            }
        }
        abort_unless($finished, 502, 'Artikelkatalog überschreitet das Importlimit.');
        $keys = array_map(fn ($row) => $row['kind'].':'.$row['remote_id'], $rows);
        abort_unless(count(array_unique($keys)) === count($keys), 502, 'Doppelte Katalog-IDs.');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $hash, $rows) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($hash, $this->binding($actor, $tenant)), 409, 'Verbindung während Import geändert.');
            $query = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('connection_hash', $hash);
            $existing = (clone $query)->lockForUpdate()->get()->keyBy(fn ($row) => $row->kind.':'.$row->remote_id);
            foreach ($rows as $row) {
                $record = $existing->get($row['kind'].':'.$row['remote_id']) ?? new BexioCatalogEntry;
                $record->forceFill($row + ['tenant_id' => $tenant->id, 'connection_hash' => $hash,
                    'name' => $record->exists ? $record->name : mb_substr($row['provider_name'], 0, 160),
                    'active' => $record->exists ? $record->active : $row['provider_active'],
                    'revision' => $record->exists ? $record->revision + 1 : 1, 'observed_at' => now()])->save();
                $existing->forget($row['kind'].':'.$row['remote_id']);
            }
            foreach ($existing as $record) {
                $record->forceFill(['provider_active' => false, 'revision' => $record->revision + 1, 'observed_at' => now()])->save();
            }
            $this->audit($actor, $tenant, 0, null, ['operation' => 'sync', 'count' => count($rows)]);

            return count($rows);
        });
    }

    private function id(array $row): int
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,9}$/D', (string) ($row['id'] ?? '')), 502, 'Ungültige Katalog-ID.');

        return (int) $row['id'];
    }

    public function normalizeTax(array $row): array
    {
        $rate = (string) ($row['value'] ?? '');
        abort_unless(preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/D', $rate, $parts), 502, 'Ungültiger Steuersatz.');
        $basis = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        abort_unless($basis <= 10000 && is_bool($row['is_active'] ?? null) && is_string($row['code'] ?? null) && trim($row['code']) !== '', 502, 'Ungültige Steuerdaten.');
        $from = $this->period($row, 'start', false);
        $until = $this->period($row, 'end', true);
        abort_unless(! $from || ! $until || $until >= $from, 502, 'Ungültige Steuergültigkeit.');

        return ['kind' => 'tax', 'remote_id' => $this->id($row), 'code' => $row['code'],
            'provider_name' => $row['code'].' / '.($basis / 100).' %', 'provider_active' => $row['is_active'],
            'rate_basis_points' => $basis, 'tax_type' => $row['type'], 'valid_from' => $from, 'valid_until' => $until,
            'provider_data' => ['type' => $row['type'], 'value' => $rate]];
    }

    private function period(array $row, string $prefix, bool $end): ?string
    {
        $year = $row[$prefix.'_year'] ?? null;
        $month = $row[$prefix.'_month'] ?? null;
        if ($year === null && $month === null) {
            return null;
        }
        abort_unless(is_int($year) && $year >= 1900 && $year <= 2200 && is_int($month) && $month >= 1 && $month <= 12, 502, 'Ungültiger Steuerzeitraum.');
        $date = CarbonImmutable::create($year, $month, 1);

        return ($end ? $date->endOfMonth() : $date)->toDateString();
    }

    private function article(array $row): array
    {
        abort_unless(is_string($row['intern_name'] ?? null) && trim($row['intern_name']) !== '', 502, 'Ungültiger Artikelname.');

        return ['kind' => 'article', 'remote_id' => $this->id($row), 'code' => (string) ($row['intern_code'] ?? $row['id']),
            'provider_name' => mb_substr($row['intern_name'], 0, 500), 'provider_active' => true,
            'rate_basis_points' => null, 'tax_type' => null, 'valid_from' => null, 'valid_until' => null,
            'provider_data' => array_intersect_key($row, array_flip(['unit_id', 'currency_id', 'tax_income_id', 'sale_price', 'account_id']))];
    }

    public function edit(Account $actor, Tenant $tenant, int $id, array $input): BexioCatalogEntry
    {
        $hash = $this->binding($actor, $tenant);
        abort_unless(array_diff(array_keys($input), ['name', 'active', 'revision']) === [], 422, 'Providerwerte sind nicht manuell änderbar.');
        $data = Validator::make($input, ['name' => 'required|string|max:160', 'active' => 'required|boolean', 'revision' => 'required|integer|min:1'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $hash, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($hash, $this->binding($actor, $tenant)), 409);
            $record = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('connection_hash', $hash)->lockForUpdate()->findOrFail($id);
            if ((int) $record->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Katalog wurde geändert. Bitte neu laden.']);
            }
            $before = $record->only(['name', 'active', 'revision']);
            $record->forceFill(['name' => $data['name'], 'active' => $data['active'], 'revision' => $record->revision + 1])->save();
            $this->audit($actor, $tenant, $id, $before, $record->only(['name', 'active', 'revision']));

            return $record;
        });
    }

    private function audit(Account $actor, Tenant $tenant, int $id, ?array $before, array $after): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'bexio_catalog_entries', 'object_id' => $id, 'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
