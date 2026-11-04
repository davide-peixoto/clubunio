<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LookupTranslation;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\LookupTranslationResource;
use App\ClubUnio\Services\ImpersonationService;
use App\ClubUnio\Services\LookupTranslations;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class LookupTranslationsPostgresTest extends TestCase
{
    private function forbidden(callable $operation, int $status = 403): void
    {
        try {
            $operation();
            $this->fail('Forbidden lookup operation succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Invalid lookup operation succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_real_postgres_migration_save_authorization_and_complete_rollback(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Explicit PostgreSQL rollback-test opt-in required.');
        }
        $db = DB::connection('clubunio');
        $this->assertSame('pgsql', $db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $db->selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(0, $db->transactionLevel());
        $schema = $db->getSchemaBuilder();
        $tableExisted = $schema->hasTable('clubora.lookup_translations');
        $ledger = [];
        foreach (['clubora.migrations', 'public.migrations'] as $table) {
            if ($schema->hasTable($table)) {
                $ledger[$table] = $db->table($table)->where('migration', 'like', '%001300_lookup_languages%')->get()->toJson();
            }
        }
        $prefix = 'lookup-pg-'.bin2hex(random_bytes(8));
        $tenants = [];
        $accounts = [];
        $sources = [];
        $translationIds = [];
        $previousLocale = app()->getLocale();
        $createdMigration = false;
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL lock_timeout = '5s'");
            $db->statement("SET LOCAL statement_timeout = '30s'");
            if (! $tableExisted) {
                (require database_path('clubunio/2026_11_04_001300_lookup_languages.php'))->up();
                $createdMigration = true;
            }
            $this->assertTrue($schema->hasTable('clubora.lookup_translations'));
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
            app()->forgetInstance(LookupTranslations::class);
            $service = app(LookupTranslations::class);

            // Synthetic tenants only; no existing accounts, sources or grants are changed.
            foreach (['a', 'b'] as $suffix) {
                $tenant = Tenant::create(['slug' => $prefix.'-'.$suffix, 'name' => 'Lookup PostgreSQL fixture '.$suffix]);
                $tenants[] = (int) $tenant->id;
                $source = new MemberStatus;
                $source->forceFill(['tenant_id' => $tenant->id, 'code' => 'fixture', 'name' => 'Offen', 'active' => true])->save();
                $sources[] = (int) $source->id;
            }
            $tenant = Tenant::findOrFail($tenants[0]);
            $other = Tenant::findOrFail($tenants[1]);
            foreach (['admin', 'api', 'office'] as $kind) {
                $account = new Account;
                $account->forceFill(['username' => $prefix.'-'.$kind, 'display_name' => 'Lookup PostgreSQL '.$kind,
                    'password' => bcrypt(bin2hex(random_bytes(24))), 'active' => true, 'api_only' => $kind === 'api'])->save();
                $accounts[$kind] = (int) $account->id;
                $account->tenants()->attach($tenant, ['role' => $kind === 'office' ? 'office' : 'admin']);
            }
            $admin = Account::findOrFail($accounts['admin']);
            $api = Account::findOrFail($accounts['api']);
            $office = Account::findOrFail($accounts['office']);
            Filament::setCurrentPanel(Filament::getPanel('clubunio'));
            $this->actingAs($admin, 'clubunio');
            Filament::setTenant($tenant);
            app()->setLocale('en');
            $input = ['source_table' => 'member_statuses', 'source_id' => (string) $sources[0],
                'source_name' => 'Offen', 'locale' => 'en', 'label' => 'Available', 'revision' => 0,
                'reason' => 'Synthetic PostgreSQL rollback verification'];
            $before = MemberStatus::whereIn('id', $sources)->orderBy('id')->get()->toJson();

            $this->assertTrue($service->allowed($admin, $tenant));
            $created = $service->save($admin, $tenant, null, $input);
            $translationIds[] = $created->id;
            $this->assertSame(1, $created->revision);
            $this->assertSame($tenant->id, $created->tenant_id);
            $updated = $service->save($admin, $tenant, $created->id, array_replace($input, ['revision' => 1, 'label' => 'Ready']));
            $this->assertSame(2, $updated->revision);
            $this->assertSame('Ready', $updated->label);
            $german = $service->save($admin, $tenant, null, array_replace($input, ['locale' => 'de', 'label' => 'Verfügbar']));
            $translationIds[] = $german->id;
            $this->assertSame('Ready', MemberStatus::whereKey($sources[0])->localizedPluck('name')->first());
            $this->assertSame('Open', MemberStatus::whereKey($sources[1])->localizedPluck('name')->first());
            app()->setLocale('de');
            $this->assertSame('Verfügbar', $db->table('clubora.member_statuses')->where('id', $sources[0])->localizedPluck('name')->first());
            $this->assertSame('Offen', MemberStatus::findOrFail($sources[0])->name);
            app()->setLocale('en');

            $this->invalid(fn () => $service->save($admin, $tenant, $created->id, array_replace($input, ['revision' => 1])), 'revision');
            $this->invalid(fn () => $service->save($admin, $tenant, null, $input), 'revision');
            $this->invalid(fn () => $service->save($admin, $tenant, $created->id,
                array_replace($input, ['revision' => 2, 'source_name' => 'Stale source'])), 'revision');
            $this->invalid(fn () => $service->save($admin, $tenant, $created->id,
                array_replace($input, ['revision' => 2, 'locale' => 'de'])), 'revision');
            $this->invalid(fn () => $service->save($admin, $tenant, null, array_replace($input, ['tenant_id' => $other->id])), 'label');
            $this->assertSame(2, $created->fresh()->revision);

            $this->forbidden(fn () => $service->save($admin, $other, null, $input));
            $this->forbidden(fn () => $service->save($admin, $tenant, null,
                array_replace($input, ['source_id' => (string) $sources[1]])), 404);
            // Create a second tenant's variant using a real, temporary admin grant.
            $admin->tenants()->attach($other, ['role' => 'admin']);
            $foreign = $service->save($admin, $other, null, array_replace($input,
                ['source_id' => (string) $sources[1], 'label' => 'Other tenant label']));
            $translationIds[] = $foreign->id;
            $admin->tenants()->updateExistingPivot($other, ['role' => 'office']);
            try {
                $service->save($admin, $tenant, $foreign->id, array_replace($input, ['revision' => 1]));
                $this->fail('Foreign translation accepted.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(LookupTranslation::class, $exception->getModel());
            }
            $this->assertSame('Other tenant label', $foreign->fresh()->label);
            $this->assertFalse(LookupTranslationResource::canEdit($foreign));
            $this->assertEqualsCanonicalizing([$created->id, $german->id], LookupTranslationResource::getEloquentQuery()->pluck('id')->all());

            $this->assertFalse($service->allowed($api, $tenant));
            $this->forbidden(fn () => $service->save($api, $tenant, null, $input));
            $this->actingAs($api, 'clubunio');
            $this->assertFalse(LookupTranslationResource::canViewAny());
            $this->assertFalse(LookupTranslationResource::canCreate());
            $this->forbidden(fn () => $service->save($office, $tenant, null, $input));
            $this->actingAs($admin, 'clubunio');
            $this->withSession([ImpersonationService::KEY => $prefix]);
            request()->setLaravelSession(app('session.store'));
            $this->assertFalse($service->allowed($admin, $tenant));
            $this->assertFalse(LookupTranslationResource::canViewAny());
            $this->assertFalse(LookupTranslationResource::canEdit($created));
            $this->forbidden(fn () => $service->save($admin, $tenant, $created->id, array_replace($input, ['revision' => 2])));
            request()->session()->forget(ImpersonationService::KEY);
            $this->assertTrue($service->allowed($admin, $tenant));
            $admin->tenants()->updateExistingPivot($tenant, ['role' => 'office']);
            $this->forbidden(fn () => $service->save($admin, $tenant, null, $input));
            $admin->tenants()->updateExistingPivot($tenant, ['role' => 'admin']);
            $db->table('clubora.accounts')->where('id', $admin->id)->update(['active' => false]);
            $this->forbidden(fn () => $service->save($admin, $tenant, null, $input));
            $db->table('clubora.accounts')->where('id', $admin->id)->update(['active' => true]);

            $audits = $db->table('clubora.object_changes')->whereIn('tenant_id', $tenants)->orderBy('id')->get();
            $this->assertCount(4, $audits);
            $this->assertNull($audits[0]->before_value);
            $this->assertSame('Available', json_decode($audits[1]->before_value, true)['label']);
            $this->assertSame('Ready', json_decode($audits[1]->after_value, true)['label']);
            $this->assertSame(2, json_decode($audits[1]->after_value, true)['revision']);
            foreach ($audits as $audit) {
                $this->assertSame('lookup_translations', $audit->object_type);
                $this->assertSame($admin->id, $audit->actor_id);
                $this->assertSame($input['reason'], json_decode($audit->after_value, true)['reason']);
            }

            // Each constraint failure is confined to its own PostgreSQL savepoint.
            $base = $created->fresh()->getAttributes();
            unset($base['id']);
            foreach ([['locale' => 'fr'], ['revision' => 0], ['label' => ' '], []] as $change) {
                $db->beginTransaction();
                try {
                    $db->table('clubora.lookup_translations')->insert(array_replace($base, $change));
                    $this->fail('Migration constraint did not reject invalid data.');
                } catch (QueryException $exception) {
                    $this->assertContains($exception->errorInfo[0], ['23514', '23505']);
                } finally {
                    $db->rollBack();
                }
            }
            $this->assertSame($before, MemberStatus::whereIn('id', $sources)->orderBy('id')->get()->toJson());
            $this->assertSame(3, LookupTranslation::whereIn('tenant_id', $tenants)->count());
            $this->assertSame(4, $db->table('clubora.object_changes')->whereIn('tenant_id', $tenants)->count());
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
        } finally {
            // Never commit or run migration bookkeeping. DDL and all fixture rows roll back together.
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            app()->forgetInstance(LookupTranslations::class);
            app()->setLocale($previousLocale);
            if (request()->hasSession()) {
                request()->session()->forget(ImpersonationService::KEY);
            }
            $this->assertFalse($db->getPdo()->inTransaction());
            $this->assertSame($tableExisted, $schema->hasTable('clubora.lookup_translations'));
            $this->assertSame(0, $db->table('clubora.tenants')->whereIn('id', $tenants)->count());
            $this->assertSame(0, $db->table('clubora.accounts')->whereIn('id', array_values($accounts))->count());
            $this->assertSame(0, $db->table('clubora.member_statuses')->whereIn('id', $sources)->count());
            $this->assertSame(0, $db->table('clubora.tenant_accounts')->whereIn('tenant_id', $tenants)->count());
            $this->assertSame(0, $db->table('clubora.object_changes')->whereIn('tenant_id', $tenants)->count());
            if ($tableExisted) {
                $this->assertSame(0, $db->table('clubora.lookup_translations')->whereIn('id', $translationIds)->count());
            }
            foreach ($ledger as $table => $beforeLedger) {
                $this->assertSame($beforeLedger, $db->table($table)->where('migration', 'like', '%001300_lookup_languages%')->get()->toJson());
            }
            $migrationResult = $tableExisted ? 'existing table preserved'
                : ($createdMigration ? 'created and rolled back' : 'DDL did not complete; table remains absent');
            fwrite(STDOUT, PHP_EOL.'Lookup PostgreSQL rollback verified; migration1300: '
                .$migrationResult.'; fixture rows retained: 0.'.PHP_EOL);
        }
    }
}
