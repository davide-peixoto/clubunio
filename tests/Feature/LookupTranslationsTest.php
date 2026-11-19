<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\LookupTranslation;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\LookupTranslationResource;
use App\ClubUnio\Resources\LookupTranslationResource\Pages\ManageLookupTranslations;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ImpersonationService;
use App\ClubUnio\Services\LookupTranslations;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LookupTranslationsTest extends TestCase
{
    private Account $actor;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // No opt-in or live connection: all writes below target attached RAM-only SQLite.
        config(['database.default' => 'clubunio', 'database.connections.clubunio' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $schema = SchemaFacade::connection('clubunio');
        foreach (['member_statuses', 'teams', 'countries', 'seasons', 'families'] as $name) {
            $schema->create('clubunio.'.$name, function (Blueprint $table) {
                $table->id();
                $table->integer('tenant_id');
                $table->string('name');
                $table->string('code');
                $table->string('iso3')->nullable();
                $table->boolean('active')->default(true);
            });
        }
        $schema->create('clubunio.account_roles', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        $schema->create('clubunio.lookup_translations', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id');
            $table->string('source_table');
            $table->string('source_id');
            $table->string('source_name');
            $table->string('locale');
            $table->string('label');
            $table->integer('revision');
            $table->unique(['tenant_id', 'source_table', 'source_id', 'locale']);
        });
        $schema->create('clubunio.tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        $schema->create('clubunio.accounts', function (Blueprint $table) {
            $table->id();
            $table->boolean('active');
            $table->boolean('api_only')->default(false);
        });
        $schema->create('clubunio.tenant_accounts', function (Blueprint $table) {
            $table->integer('tenant_id');
            $table->integer('account_id');
            $table->string('role');
        });
        $schema->create('clubunio.object_changes', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id');
            $table->integer('actor_id');
            $table->string('object_type');
            $table->integer('object_id');
            $table->text('before_value')->nullable();
            $table->text('after_value');
        });
        $db->table('clubunio.tenants')->insert([
            ['id' => 1, 'name' => 'Lookup test', 'slug' => 'lookup-test'],
            ['id' => 2, 'name' => 'Other test', 'slug' => 'other-test'],
        ]);
        $db->table('clubunio.accounts')->insert(['id' => 1, 'active' => true, 'api_only' => false]);
        $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => 1, 'account_id' => 1, 'role' => 'admin']);
        $db->table('clubunio.member_statuses')->insert([
            ['id' => 1, 'tenant_id' => 1, 'name' => 'Offen', 'code' => 'open'],
            ['id' => 2, 'tenant_id' => 1, 'name' => 'Gesperrt', 'code' => 'blocked'],
            ['id' => 3, 'tenant_id' => 2, 'name' => 'Offen', 'code' => 'open'],
            ['id' => 4, 'tenant_id' => 1, 'name' => 'My custom label', 'code' => 'custom'],
        ]);
        foreach (['teams', 'seasons', 'families'] as $table) {
            $db->table('clubunio.'.$table)->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Offen', 'code' => 'raw']);
        }
        $db->table('clubunio.countries')->insert([
            ['id' => 1, 'tenant_id' => 1, 'name' => 'Schweiz', 'code' => 'CH', 'iso3' => 'CHE'],
            ['id' => 2, 'tenant_id' => 1, 'name' => 'Unmapped country', 'code' => 'XX', 'iso3' => 'XXX'],
            ['id' => 3, 'tenant_id' => 1, 'name' => 'Schweiz alt', 'code' => 'XX', 'iso3' => 'CHE'],
        ]);
        $db->table('clubunio.account_roles')->insert(['code' => 'admin', 'name' => 'Administrator']);
        $this->actor = Account::findOrFail(1);
        $this->tenant = Tenant::findOrFail(1);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        app()->setLocale('en');
    }

    private function input(array $changes = []): array
    {
        return array_replace(['source_table' => 'member_statuses', 'source_id' => '1', 'source_name' => 'Offen',
            'locale' => 'en', 'label' => 'Available', 'revision' => 0, 'reason' => 'Test display-only override'], $changes);
    }

    public function test_both_macros_preserve_keys_order_filters_limit_and_builder(): void
    {
        $builder = MemberStatus::where('tenant_id', 1)->orderByDesc('id')->limit(2);
        $before = $builder->toSql();
        $this->assertSame([4 => 'My custom label', 2 => 'Blocked'], $builder->localizedPluck('name', 'id')->all());
        $this->assertSame($before, $builder->toSql());
        $this->assertSame([4 => 'My custom label', 2 => 'Gesperrt'], $builder->pluck('name', 'id')->all());
        $this->assertSame(['open' => 'Open', 'blocked' => 'Blocked', 'custom' => 'My custom label'],
            DB::table('clubunio.member_statuses')->where('tenant_id', 1)->orderBy('id')->localizedPluck('name', 'code')->all());
        $this->assertSame(['Open', 'Blocked', 'My custom label'], MemberStatus::where('tenant_id', 1)->orderBy('id')->localizedPluck('name')->all());
        $this->assertSame([1 => 'Open'], MemberStatus::whereKey(1)->localizedPluck('clubunio.member_statuses.name', 'member_statuses.id')->all());
        $scoped = MemberStatus::query()->withGlobalScope('test', fn ($q) => $q->where('tenant_id', 1));
        $this->assertCount(3, $scoped->localizedPluck('name'));
    }

    public function test_non_lookup_names_other_columns_and_complex_queries_use_native_pluck(): void
    {
        foreach (['teams', 'seasons', 'families'] as $table) {
            $query = DB::table('clubunio.'.$table)->where('tenant_id', 1);
            $this->assertSame($query->pluck('name', 'id')->all(), $query->localizedPluck('name', 'id')->all());
        }
        $this->assertSame(['Offen'], Team::query()->localizedPluck('name')->all());
        $query = MemberStatus::where('tenant_id', 1);
        $this->assertSame($query->pluck('code')->all(), $query->localizedPluck('code')->all());
        foreach ([DB::table('clubunio.member_statuses as s'), DB::table('clubunio.member_statuses')->distinct(),
            DB::table('clubunio.member_statuses')->groupBy('name')] as $query) {
            $this->assertSame($query->pluck('name')->all(), $query->localizedPluck('name')->all());
        }
    }

    public function test_iso_countries_and_unknown_text_have_explicit_fallbacks(): void
    {
        $this->assertSame([1 => 'Switzerland', 2 => 'Unmapped country', 3 => 'Switzerland'], Country::query()->orderBy('id')->localizedPluck('name', 'id')->all());
        app()->setLocale('de');
        $this->assertSame('Schweiz', Country::whereKey(1)->localizedPluck('name')->first());
        $this->assertSame('Offen', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        app()->setLocale('fr');
        $this->assertSame('Offen', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        app()->setLocale('en');
        config(['clubunio-languages.enabled' => ['de' => 'Deutsch']]);
        $this->assertSame('Offen', MemberStatus::whereKey(1)->localizedPluck('name')->first());
    }

    public function test_tenant_variants_and_languages_are_isolated_and_source_changes_invalidate_them(): void
    {
        $service = app(LookupTranslations::class);
        $service->save($this->actor, $this->tenant, null, $this->input());
        $service->save($this->actor, $this->tenant, null, $this->input(['locale' => 'de', 'label' => 'Verfügbar']));
        $this->assertSame('Available', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        $this->assertSame('Open', MemberStatus::whereKey(3)->localizedPluck('name')->first());
        app()->setLocale('de');
        $this->assertSame('Verfügbar', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        $this->assertSame('Offen', MemberStatus::find(1)->name);
        DB::table('clubunio.member_statuses')->where('id', 1)->update(['name' => 'Changed source']);
        $this->assertSame('Changed source', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        $this->assertSame('Offen', $service->label('member_statuses', ['id' => 1, 'tenant_id' => 1, 'name' => 'Offen'], tenant: 2));
    }

    public function test_global_role_overrides_are_tenant_specific_and_codes_stay_raw(): void
    {
        app(LookupTranslations::class)->save($this->actor, $this->tenant, null, $this->input([
            'source_table' => 'account_roles', 'source_id' => 'admin', 'source_name' => 'Administrator', 'label' => 'Club administrator',
        ]));
        $this->assertSame(['admin' => 'Club administrator'], AccountRole::query()->localizedPluck('name', 'code')->all());
        Filament::setTenant(Tenant::find(2));
        $this->assertSame(['admin' => 'Administrator'], AccountRole::query()->localizedPluck('name', 'code')->all());
        $this->assertSame('Administrator', AccountRole::find('admin')->name);
    }

    public function test_audit_and_revision_checks_are_atomic_and_never_write_source(): void
    {
        $service = app(LookupTranslations::class);
        $source = MemberStatus::find(1)->getAttributes();
        $created = $service->save($this->actor, $this->tenant, null, $this->input());
        $this->assertSame(1, $created->revision);
        $updated = $service->save($this->actor, $this->tenant, $created->id, $this->input(['revision' => 1, 'label' => 'Ready']));
        $this->assertSame(2, $updated->revision);
        $this->assertSame('Ready', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        $audit = DB::table('clubunio.object_changes')->orderByDesc('id')->first();
        $this->assertSame('Available', json_decode($audit->before_value, true)['label']);
        $this->assertSame('Ready', json_decode($audit->after_value, true)['label']);
        $this->assertSame('Test display-only override', json_decode($audit->after_value, true)['reason']);
        foreach ([$this->input(), $this->input(['revision' => 2, 'source_name' => 'old']),
            $this->input(['revision' => 2, 'locale' => 'de'])] as $input) {
            try {
                $service->save($this->actor, $this->tenant, $created->id, $input);
                $this->fail('Stale or changed identity accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('revision', $e->errors());
            }
        }
        try {
            $service->save($this->actor, $this->tenant, null, $this->input());
            $this->fail('Duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame($source, MemberStatus::find(1)->getAttributes());
        $this->assertSame(2, DB::table('clubunio.object_changes')->count());
        $this->assertSame(2, $created->fresh()->revision);
    }

    public function test_live_admin_role_active_state_and_support_mode_are_rechecked(): void
    {
        $service = app(LookupTranslations::class);
        $this->assertTrue($service->allowed($this->actor, $this->tenant));
        foreach (['office', 'profile', 'trainer'] as $role) {
            DB::table('clubunio.tenant_accounts')->update(['role' => $role]);
            try {
                $service->save($this->actor, $this->tenant, null, $this->input());
                $this->fail('Non-admin accepted');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        DB::table('clubunio.tenant_accounts')->update(['role' => 'admin']);
        DB::table('clubunio.accounts')->update(['active' => false]);
        $this->assertFalse($service->allowed($this->actor, $this->tenant));
        DB::table('clubunio.accounts')->update(['active' => true]);
        $this->withSession([ImpersonationService::KEY => 'support-fixture']);
        request()->setLaravelSession(app('session.store'));
        $this->assertFalse($service->allowed($this->actor, $this->tenant));
        $this->assertSame(0, LookupTranslation::count());
    }

    public function test_tampering_foreign_sources_and_foreign_overrides_are_rejected(): void
    {
        $service = app(LookupTranslations::class);
        foreach ([['source_table' => 'teams'], ['locale' => 'fr'], ['label' => '<b>Open</b>'], ['tenant_id' => 2], ['label' => ' ']] as $change) {
            try {
                $service->save($this->actor, $this->tenant, null, $this->input($change));
                $this->fail('Invalid input accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        try {
            $service->save($this->actor, $this->tenant, null, $this->input(['source_id' => '3']));
            $this->fail('Foreign source accepted');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $record = new LookupTranslation;
        $record->forceFill(['tenant_id' => 2, 'source_table' => 'member_statuses', 'source_id' => '3',
            'source_name' => 'Offen', 'locale' => 'en', 'label' => 'Private label', 'revision' => 1])->save();
        try {
            $service->save($this->actor, $this->tenant, $record->id, $this->input(['revision' => 1]));
            $this->fail('Foreign override accepted');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(LookupTranslation::class, $e->getModel());
        }
        $this->assertSame(0, DB::table('clubunio.object_changes')->count());
    }

    public function test_column_and_entry_formatting_leave_raw_state_and_colour_unchanged(): void
    {
        $lookup = MemberStatus::find(1);
        $record = (new Member)->forceFill(['id' => 1, 'tenant_id' => 1])->setRelation('status', $lookup);
        $livewire = \Mockery::mock(HasTable::class);
        $livewire->shouldReceive('getTableRecordKey')->andReturn('1');
        $table = Table::make($livewire)->query(Member::query());
        $column = TextColumn::make('status.name')->record($record)->badge()->color(fn ($state) => StatusAppearance::defaultHex($state));
        $column->table($table);
        $this->assertSame('Offen', $column->getState());
        $this->assertSame('Open', $column->formatState($column->getState(), $lookup));
        $this->assertSame('#0369a1', $column->getColor($column->getState(), $lookup));
        $this->assertSame('Offen', $lookup->name);
        $this->assertSame('Offen', $lookup->toArray()['name']);
        $entry = TextEntry::make('status.name');
        $entry->container(Schema::make()->record($record));
        $this->assertSame('Open', $entry->formatState('Offen', $lookup));
        $plain = TextColumn::make('name')->record(Team::find(1));
        $this->assertSame('Offen', $plain->formatState('Offen'));
        $custom = TextColumn::make('name')->record($lookup)->formatStateUsing(fn ($state) => 'Custom '.$state);
        $this->assertSame('Custom Offen', $custom->formatState('Offen'));
        $this->assertSame('Open', app(LookupTranslations::class)->formatDisplay('status.name', 'Offen', $record));
        $record->unsetRelation('status');
        $this->assertSame('Offen', app(LookupTranslations::class)->formatDisplay('status.name', 'Offen', $record));
    }

    public function test_missing_migration_falls_back_and_translation_reads_are_bounded(): void
    {
        $db = DB::connection('clubunio');
        $db->enableQueryLog();
        MemberStatus::where('tenant_id', 1)->localizedPluck('name');
        $db->flushQueryLog();
        foreach (range(1, 20) as $unused) {
            app(LookupTranslations::class)->label('member_statuses', ['id' => 1, 'tenant_id' => 1, 'name' => 'Offen']);
        }
        $this->assertCount(0, $db->getQueryLog());
        $db->disableQueryLog();
        SchemaFacade::connection('clubunio')->drop('clubunio.lookup_translations');
        app()->forgetInstance(LookupTranslations::class);
        $this->assertSame('Open', MemberStatus::whereKey(1)->localizedPluck('name')->first());
        $this->assertFalse(LookupTranslationResource::canViewAny());
    }

    public function test_resource_is_admin_only_manually_scoped_and_has_no_delete_path(): void
    {
        $record = app(LookupTranslations::class)->save($this->actor, $this->tenant, null, $this->input());
        $this->assertTrue(LookupTranslationResource::canViewAny());
        $this->assertTrue(LookupTranslationResource::canEdit($record));
        $this->assertFalse(LookupTranslationResource::canDelete($record));
        $this->assertFalse(LookupTranslationResource::canDeleteAny());
        $this->assertNull(ModulePermissionService::modelModule(LookupTranslation::class));
        $this->assertSame([$record->id], LookupTranslationResource::getEloquentQuery()->pluck('id')->all());
        $record->tenant_id = 2;
        $this->assertFalse(LookupTranslationResource::canEdit($record));
        DB::table('clubunio.tenant_accounts')->update(['role' => 'office']);
        $this->assertFalse(LookupTranslationResource::canViewAny());
        $this->assertFalse(LookupTranslationResource::canCreate());
    }

    public function test_audit_failure_rolls_back_the_override(): void
    {
        SchemaFacade::connection('clubunio')->drop('clubunio.object_changes');
        try {
            app(LookupTranslations::class)->save($this->actor, $this->tenant, null, $this->input());
            $this->fail('Audit failure ignored');
        } catch (QueryException $e) {
            $this->assertSame(0, LookupTranslation::count());
            $this->assertSame('Offen', MemberStatus::find(1)->name);
        }
    }

    public function test_native_editor_creates_and_updates_only_variants(): void
    {
        // SQLite cannot select schema.table.* in the unrelated render snapshot.
        // The editor/service's fresh AccountAdministrationService checks stay real.
        $this->mock(BackofficeReadContext::class, function ($mock) {
            $mock->makePartial()->shouldReceive('duringRender')->andReturnUsing(fn ($next) => $next());
        });
        $page = Livewire::test(ManageLookupTranslations::class)
            ->assertSuccessful()->assertCountTableRecords(0)
            ->callTableAction('create', data: $this->input())->assertHasNoTableActionErrors();
        $record = LookupTranslation::firstOrFail();
        $page->assertCanSeeTableRecords([$record])
            ->callTableAction('edit', $record, data: ['label' => 'Ready', 'reason' => 'Reviewed display wording'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Ready', $record->fresh()->label);
        $this->assertSame(2, $record->fresh()->revision);
        $this->assertSame('Offen', MemberStatus::find(1)->name);
    }
}
