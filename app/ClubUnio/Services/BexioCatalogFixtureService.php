<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class BexioCatalogFixtureService
{
    public const CODE = 'CLUBUNIO-SANDBOX-ARTICLE-V1';

    public const MARKER = 'clubunio-sandbox-fixture:v1:article';

    public const INVOICE_MARKER = 'SANDBOX Artikeltest v1 - keine Forderung, keine Zahlung, kein Versand';

    public function prepareInvoice(Account $actor, Tenant $tenant): int
    {
        $remote = $this->create($actor, $tenant);
        $db = DB::connection('clubunio');
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);

        return $db->transaction(function () use ($actor, $tenant, $remote, $db, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
            $binding = $db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
            abort_unless($binding && hash_equals($hash, $binding->connection_hash)
                && hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            $existing = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')
                ->where('member_id', $binding->member_id)->where('notes', self::INVOICE_MARKER)->first();
            if ($existing) {
                return $existing->id;
            }
            $article = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('connection_hash', $hash)
                ->where('kind', 'article')->where('remote_id', $remote)->firstOrFail();
            $workflow = app(InvoiceWorkflowService::class);
            $invoice = new Invoice;
            $invoice->forceFill(['tenant_id' => $tenant->id, 'member_id' => $binding->member_id, 'notes' => self::INVOICE_MARKER,
                'workflow_status_id' => $workflow->status($tenant, 'draft')])->save();
            $invoice->refresh();
            $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line', [
                'description' => 'ClubUnio Sandbox Artikelposition', 'gross_amount' => '12.00', 'discount' => '2.00', 'bexio_catalog_article_id' => $article->id]);
            $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');

            return $invoice->id;
        });
    }

    public function create(Account $actor, Tenant $tenant): int
    {
        $catalog = app(BexioCatalogService::class);
        $catalog->sync($actor, $tenant);
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        $table = DB::connection('clubunio')->table('clubora.bexio_catalog_fixture_actions');
        $existing = (clone $table)->where('tenant_id', $tenant->id)->where('connection_hash', $hash)->first();
        if ($existing) {
            return $this->reconcile($tenant, $token, $existing->id);
        }
        $prior = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('connection_hash', $hash)
            ->where('kind', 'article')->where('code', self::CODE)->get();
        abort_if($prior->count() > 1, 409, 'Mehrere Testartikel gefunden. Keine Neuanlage.');
        $priorId = $prior->first()?->remote_id;
        $pick = function (string $path, string $field, mixed $value) use ($client, $token): int {
            $rows = array_values(array_filter($client->get($token, $path), fn ($row) => ($row[$field] ?? null) === $value
                && (! array_key_exists('is_active', $row) || $row['is_active'] === true)));
            abort_unless(count($rows) === 1 && (int) ($rows[0]['id'] ?? 0) > 0, 422, 'Testartikel-Grundlage nicht eindeutig.');

            return (int) $rows[0]['id'];
        };
        $user = $client->get($token, '/3.0/users/me');
        $configuration = ['user_id' => (int) ($user['id'] ?? 0), 'currency_id' => $pick('/3.0/currencies', 'name', 'CHF'),
            'unit_id' => $pick('/2.0/unit', 'name', 'Stk'), 'account_id' => $pick('/2.0/accounts', 'account_no', '3200'),
            'tax_income_id' => $pick('/3.0/taxes', 'code', 'UEX'), 'tax_expense_id' => $pick('/3.0/taxes', 'code', 'V00')];
        $id = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $hash, $table, $priorId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
            abort_unless(hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            abort_if((clone $table)->where('tenant_id', $tenant->id)->where('connection_hash', $hash)->exists(), 409, 'Testartikel-Auftrag bereits vorhanden.');

            return $table->insertGetId(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'connection_hash' => $hash,
                'state' => $priorId ? 'uncertain' : 'running', 'remote_id' => $priorId]);
        });
        if ($priorId) {
            return $this->reconcile($tenant, $token, $id);
        }
        // Commit the claim before HTTP. A timeout can only be reconciled, never reposted.
        $result = $client->createTestArticle($token, $configuration);
        (clone $table)->where('id', $id)->update(['state' => $result['state'] === 'accepted' ? 'uncertain' : $result['state'],
            'remote_id' => $result['id'], 'http_status' => $result['http_status']]);
        $remoteId = $this->reconcile($tenant, $token, $id);
        $catalog->sync($actor, $tenant);

        return $remoteId;
    }

    private function reconcile(Tenant $tenant, string $token, int $id): int
    {
        $table = DB::connection('clubunio')->table('clubora.bexio_catalog_fixture_actions')->where('tenant_id', $tenant->id)->where('id', $id);
        $action = (clone $table)->firstOrFail();
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        abort_unless(hash_equals($action->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
        if ($action->remote_id) {
            $rows = [$client->get($token, '/2.0/article/'.$action->remote_id)];
        } else {
            $rows = [];
            $finished = false;
            for ($offset = 0; $offset < 10000; $offset += 500) {
                $page = $client->get($token, '/2.0/article', ['limit' => 500, 'offset' => $offset]);
                $rows = array_merge($rows, array_filter($page, fn ($row) => ($row['intern_code'] ?? '') === self::CODE && ($row['remarks'] ?? '') === self::MARKER));
                if (count($page) < 500) {
                    $finished = true;
                    break;
                }
            }
            abort_unless($finished, 502, 'Testartikel-Suche überschreitet das Limit.');
        }
        abort_unless(count($rows) === 1 && ($rows[0]['intern_code'] ?? '') === self::CODE && ($rows[0]['remarks'] ?? '') === self::MARKER
            && ($rows[0]['intern_name'] ?? '') === 'ClubUnio Sandbox Testartikel' && (int) ($rows[0]['article_type_id'] ?? 0) === 2
            && ($rows[0]['is_stock'] ?? null) === false && (int) ($rows[0]['id'] ?? 0) > 0, 409, 'Testartikel noch nicht eindeutig bestätigt. Kein erneuter POST.');
        $table->update(['remote_id' => $rows[0]['id'], 'state' => 'accepted', 'checked_at' => now()]);

        return (int) $rows[0]['id'];
    }
}
