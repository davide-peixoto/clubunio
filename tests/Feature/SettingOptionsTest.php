<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Setting, Tenant};
use App\ClubUnio\Services\{SettingOptions, SettingsService};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettingOptionsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Rollback fixtures require opt-in'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['name'=>'Settings fixture','slug'=>'settings-'.Str::random(12)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username'=>Str::random(25),'display_name'=>'Fixture','password'=>bcrypt('fixture'),'active'=>true])->save();
        $this->admin->tenants()->attach($this->tenant, ['role'=>'admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { while ($this->db->transactionLevel()) { $this->db->rollBack(); } }
        parent::tearDown();
    }

    private function setting(string $key, string $value): Setting
    {
        $s = new Setting;
        $s->forceFill(['tenant_id'=>$this->tenant->id,'area'=>'general','key'=>$key,'label'=>$key,'value_type'=>'string',
            'encrypted_value'=>Crypt::encryptString($value),'enabled'=>false,'is_secret'=>false])->save();
        return $s;
    }

    public function test_season_options_preserve_legacy_ids_and_audit_without_activation(): void
    {
        $this->db->table('clubunio.seasons')->insert(['tenant_id'=>$this->tenant->id,'name'=>'2026/2027','legacy_id'=>38,'active'=>true]);
        $setting = $this->setting('SaisonID','38');
        $options = app(SettingOptions::class);
        $this->assertSame([38=>'2026/2027'],$options->options($setting));
        $this->assertSame('2026/2027 (38)',$options->display($setting));
        $this->assertSame('Verwendet',$options->usage($setting));
        app(SettingsService::class)->update($this->admin,$this->tenant,$setting->id,'38');
        $this->assertFalse($setting->fresh()->enabled);
        $this->assertSame('38',Crypt::decryptString($setting->fresh()->encrypted_value));
        $this->assertSame(1,$this->db->table('clubunio.setting_changes')->where('setting_id',$setting->id)->count());
    }

    public function test_foreign_inactive_and_unknown_choices_are_rejected(): void
    {
        $other = Tenant::create(['name'=>'Other','slug'=>'other-'.Str::random(12)]);
        $this->db->table('clubunio.seasons')->insert(['tenant_id'=>$other->id,'name'=>'Foreign','legacy_id'=>77,'active'=>true]);
        $this->db->table('clubunio.seasons')->insert(['tenant_id'=>$this->tenant->id,'name'=>'Inactive','legacy_id'=>78,'active'=>false]);
        $setting = $this->setting('SaisonID','77');
        $this->assertSame([],app(SettingOptions::class)->options($setting));
        foreach (['77','78','999'] as $value) {
            try { app(SettingsService::class)->update($this->admin,$this->tenant,$setting->id,$value); $this->fail('Invalid setting accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('value',$e->errors()); }
        }
        $this->assertSame(0,$this->db->table('clubunio.setting_changes')->where('setting_id',$setting->id)->count());
    }

    public function test_imported_status_and_period_are_labels_not_live_controls(): void
    {
        $this->db->table('clubunio.facility_statuses')->insert(['tenant_id'=>$this->tenant->id,'code'=>'O','name'=>'Offen','active'=>true]);
        $this->db->table('clubunio.training_options')->insert(['tenant_id'=>$this->tenant->id,'category'=>'period','code'=>'S','name'=>'Sommer','active'=>true]);
        $options = app(SettingOptions::class);
        $status = $this->setting('StatusPlatz1','O');
        $this->assertSame('Offen (O)',$options->display($status));
        $period = $this->setting('SommerWinter','S');
        $this->assertSame('Sommer (S)',$options->display($period));
        $this->assertSame('Altbestand',$options->usage($status));
        $this->expectException(ValidationException::class);
        app(SettingsService::class)->update($this->admin,$this->tenant,$status->id,'O');
    }

    public function test_secret_values_never_gain_plaintext_lookup_display(): void
    {
        $setting = $this->setting('SaisonID','private-fixture');
        $setting->is_secret = true;
        $this->assertNull(app(SettingOptions::class)->options($setting));
        $this->assertSame('••••••••',app(SettingOptions::class)->display($setting));
    }

    public function test_active_training_plan_changes_are_scoped_audited_and_shared_by_signage(): void
    {
        $summer = $this->db->table('clubunio.training_options')->insertGetId(['tenant_id'=>$this->tenant->id,'category'=>'period','code'=>'S','name'=>'Sommer','active'=>true]);
        $winter = $this->db->table('clubunio.training_options')->insertGetId(['tenant_id'=>$this->tenant->id,'category'=>'period','code'=>'W','name'=>'Winter','active'=>true]);
        $setting = $this->setting(\App\ClubUnio\Services\ActiveTrainingPlan::KEY, (string) $summer);
        $service = app(\App\ClubUnio\Services\ActiveTrainingPlan::class);
        $this->assertSame($summer, $service->period($this->tenant)->id);
        $this->assertSame('Sommer ('.$summer.')', app(SettingOptions::class)->display($setting));
        app(SettingsService::class)->update($this->admin, $this->tenant, $setting->id, (string) $winter);
        $this->assertSame($winter, app(\App\ClubUnio\Services\SignageBrandService::class)->read($this->tenant)['period_id']);
        $this->assertSame(1, $this->db->table('clubunio.setting_changes')->where('setting_id', $setting->id)->count());
        $this->db->table('clubunio.training_options')->where('id', $winter)->update(['active'=>false]);
        $this->assertNull($service->period($this->tenant));
        foreach ([(string) $winter, '99999999'] as $invalid) {
            try { app(SettingsService::class)->update($this->admin,$this->tenant,$setting->id,$invalid); $this->fail('Invalid plan accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('value',$e->errors()); }
        }
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
        $this->actingAs($this->admin, 'clubunio');
        \Filament\Facades\Filament::setTenant($this->tenant);
        \Livewire\Livewire::test(\App\ClubUnio\Resources\SettingResource\Pages\ListSettings::class)
            ->callTableAction('change', $setting, data: ['value'=>(string) $summer])->assertHasNoTableActionErrors();
        \Livewire\Livewire::test(\App\ClubUnio\Resources\TrainingSlotResource\Pages\ManageSlots::class)
            ->assertSee('Aktiver Trainingsplan: Sommer');
        \Livewire\Livewire::test(\App\ClubUnio\Pages\SignagePreview::class)->assertSee('Aktiver Trainingsplan: Sommer');
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id',$this->tenant->id)->where('account_id',$this->admin->id)->update(['role'=>'office']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SettingsService::class)->update($this->admin->fresh(),$this->tenant,$setting->id,'0');
    }

    public function test_training_plan_migration_preserves_value_row_id_and_is_idempotent(): void
    {
        $setting = $this->setting(\App\ClubUnio\Services\ActiveTrainingPlan::KEY, '123');
        $setting->forceFill(['area'=>'signage', 'enabled'=>true])->save();
        $cipher = $setting->encrypted_value;
        $migration = require base_path('database/clubunio/2026_11_03_000090_active_training_plan.php');
        $migration->up();
        $migration->up();
        $this->assertSame('general', $setting->fresh()->area);
        $this->assertSame($cipher, $setting->fresh()->encrypted_value);
        $this->assertSame('Aktiver Trainingsplan', $setting->fresh()->label);
        $migration->down();
        $this->assertSame('signage', $setting->fresh()->area);
        $this->assertSame($cipher, $setting->fresh()->encrypted_value);
    }

    public function test_web_editor_saves_lookup_and_hides_archived_edit_action(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
        $this->actingAs($this->admin, 'clubunio');
        \Filament\Facades\Filament::setTenant($this->tenant);
        $this->db->table('clubunio.seasons')->insert(['tenant_id'=>$this->tenant->id,'name'=>'Lookup season','legacy_id'=>38,'active'=>true]);
        $setting = $this->setting('SaisonID','38');
        $old = $this->setting('StatusPlatz1','O');
        \Livewire\Livewire::test(\App\ClubUnio\Resources\SettingResource\Pages\ListSettings::class)
            ->assertSee('Lookup season (38)')->assertSee('Verwendet')
            ->assertTableActionHidden('change', $old)
            ->callTableAction('change', $setting, data: ['value'=>'38'])->assertHasNoTableActionErrors();
        $this->assertSame('38',Crypt::decryptString($setting->fresh()->encrypted_value));
    }
}
