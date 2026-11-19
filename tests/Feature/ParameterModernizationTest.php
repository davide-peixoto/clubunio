<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{AccessProfileService, MobileModuleSettings, RetiredIntegrationParameters};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ParameterModernizationTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Rollback fixtures require opt-in'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction();
        if (! $this->db->getSchemaBuilder()->hasTable('clubunio.mobile_module_options')) {
            (require base_path('database/clubunio/2026_11_03_000093_profile_mobile_modules.php'))->up();
        }
        $this->tenant = Tenant::create(['slug'=>'param-'.Str::random(12),'name'=>'Parameter fixture']);
        $this->admin = new Account;
        $this->admin->forceFill(['username'=>Str::random(25),'display_name'=>'Fixture','password'=>bcrypt('fixture'),'active'=>true])->save();
        $this->admin->tenants()->attach($this->tenant,['role'=>'admin']);
        foreach (MobileModuleSettings::MODULES as $key=>$name) {
            $this->db->table('clubunio.mobile_module_options')->insert(['tenant_id'=>$this->tenant->id,'key'=>$key,'name'=>$name]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { while ($this->db->transactionLevel()) { $this->db->rollBack(); } }
        parent::tearDown();
    }

    private function input(array $extra=[]): array
    {
        return $extra + ['name'=>'App test profile','active'=>true,'permissions'=>['members.view'], 'reason'=>'Isolated module selection test'];
    }

    public function test_profile_module_selection_is_scoped_audited_and_cannot_grant_rights(): void
    {
        $service = app(AccessProfileService::class);
        $profile = $service->save($this->admin,$this->tenant,null,$this->input(['mobile_modules'=>['Mitglieder','Spesen']]));
        $this->assertSame(['Mitglieder','Spesen'],$profile->mobile_modules);
        $user = new Account;
        $user->forceFill(['username'=>Str::random(25),'display_name'=>'Fixture member','password'=>bcrypt('fixture'),'active'=>true])->save();
        $user->tenants()->attach($this->tenant,['role'=>'office','mobile_profile_id'=>$profile->id]);
        $request = \Illuminate\Http\Request::create('/test');
        $request->attributes->set('mobile.actor',$user);
        $request->attributes->set('mobile.tenant',$this->tenant);
        $response = app(\App\ClubUnio\Mobile\MobileAccountController::class)->modules($request)->getData(true);
        $this->assertSame(['Spesen','Mitglieder','Profil'],array_column($response['data']['modules'],'key'));
        // A module choice does not change any backend permissions.
        $this->assertSame(['members.view'],$profile->permissions);
        $profile = $service->save($this->admin,$this->tenant,$profile->id,$this->input(['revision'=>$profile->revision,'mobile_modules'=>[]]));
        $this->assertSame([],app(MobileModuleSettings::class)->visibleKeys($user,$this->tenant));
        $profile = $service->save($this->admin,$this->tenant,$profile->id,$this->input(['revision'=>$profile->revision]));
        $this->assertSame([],$profile->mobile_modules); // Old callers must preserve an explicit selection.
        $this->assertSame(3,$this->db->table('clubunio.object_changes')->where('tenant_id',$this->tenant->id)->where('object_type','access_profiles')->count());
        $other = Tenant::create(['slug'=>'other-'.Str::random(12),'name'=>'Other']);
        $this->assertSame([],app(MobileModuleSettings::class)->visibleKeys($user,$other));
        $this->expectException(ValidationException::class);
        $service->save($this->admin,$this->tenant,$profile->id,$this->input(['revision'=>$profile->revision,'mobile_modules'=>['Unknown']]));
    }

    public function test_profile_editor_contains_database_lookup_and_preserves_automatic_mode(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
        $this->actingAs($this->admin,'clubunio');
        \Filament\Facades\Filament::setTenant($this->tenant);
        $profile = app(AccessProfileService::class)->save($this->admin,$this->tenant,null,$this->input());
        $this->assertNull($profile->mobile_modules);
        $this->assertCount(10,app(MobileModuleSettings::class)->visibleKeys($this->admin,$this->tenant));
        $fields = \App\ClubUnio\Resources\AccessProfileResource::fields();
        $this->assertContains('mobile_modules',array_map(fn ($field)=>method_exists($field,'getName') ? $field->getName() : '',$fields));
        $input = \App\ClubUnio\Resources\AccessProfileResource::input(['modules'=>[], 'mobile_modules_limited'=>true,'mobile_modules'=>['Teams']]);
        $this->assertSame(['Teams'],$input['mobile_modules']);
    }

    public function test_cleanup_only_removes_reviewed_keys_and_preserves_live_integrations(): void
    {
        foreach (RetiredIntegrationParameters::KEYS as $area=>$keys) {
            foreach ($keys as $key) {
                $this->db->table('clubunio.tenant_settings')->insert(['tenant_id'=>$this->tenant->id,'area'=>$area,'key'=>$key,'label'=>$key,'value_type'=>'string','encrypted_value'=>Crypt::encryptString('fixture')]);
            }
        }
        $keep = ['FCM_SERVICE_ACCOUNT_JSON','SFV_APPLICATION_KEY','SFV_SEASON_ID','OPENAI_API_KEY','OPENAI_EXPENSE_MODEL','INSTAGRAM_ACCESS_TOKEN','N8N_FUTURE_UNREVIEWED'];
        foreach ($keep as $key) {
            $this->db->table('clubunio.tenant_settings')->insert(['tenant_id'=>$this->tenant->id,'area'=>'integrations','key'=>$key,'label'=>$key,'value_type'=>'string','encrypted_value'=>Crypt::encryptString('keep')]);
        }
        $migration = require base_path('database/clubunio/2026_11_03_000092_retire_integration_parameters.php');
        $migration->up();
        $migration->up();
        $this->assertEqualsCanonicalizing($keep,$this->db->table('clubunio.tenant_settings')->where('tenant_id',$this->tenant->id)->pluck('key')->all());
        $this->assertFalse(RetiredIntegrationParameters::contains('app','META_ACCESS_TOKEN'));
        $this->assertTrue(RetiredIntegrationParameters::contains('integrations','META_ACCESS_TOKEN'));
    }

    public function test_assignment_is_admin_only_revision_checked_and_tenant_scoped(): void
    {
        $profile = app(AccessProfileService::class)->save($this->admin,$this->tenant,null,$this->input(['mobile_modules'=>['Teams']]));
        $link = $this->db->table('clubunio.tenant_accounts')->where('tenant_id',$this->tenant->id)->where('account_id',$this->admin->id)->first();
        $service = app(MobileModuleSettings::class);
        $input = ['mobile_profile_id'=>$profile->id,'revision'=>$link->revision,'reason'=>'Assign display-only profile'];
        $service->assign($this->admin,$this->tenant,$link->id,$input);
        $this->assertSame(['Teams'],$service->visibleKeys($this->admin,$this->tenant));
        $this->assertSame('admin',$this->db->table('clubunio.tenant_accounts')->where('id',$link->id)->value('role'));
        try { $service->assign($this->admin,$this->tenant,$link->id,$input); $this->fail('Stale assignment accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('mobile_profile_id',$e->errors()); }
        $other = Tenant::create(['slug'=>'foreign-'.Str::random(12),'name'=>'Foreign']);
        $this->admin->tenants()->attach($other,['role'=>'admin']);
        $foreign = app(AccessProfileService::class)->save($this->admin,$other,null,$this->input());
        try {
            $service->assign($this->admin,$this->tenant,$link->id,['mobile_profile_id'=>$foreign->id,'revision'=>$link->revision+1,'reason'=>'Foreign profile must be rejected']);
            $this->fail('Foreign profile accepted');
        } catch (ValidationException $e) { $this->assertArrayHasKey('mobile_profile_id',$e->errors()); }
        $this->db->table('clubunio.access_profiles')->where('id',$profile->id)->update(['active'=>false]);
        $this->assertSame([],$service->visibleKeys($this->admin,$this->tenant));
        try { $service->assign($this->admin,$this->tenant,$link->id,['revision'=>$link->revision+1]+$input); $this->fail('Inactive profile accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('mobile_profile_id',$e->errors()); }
        $this->db->table('clubunio.tenant_accounts')->where('id',$link->id)->update(['role'=>'office']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->assign($this->admin,$this->tenant,$link->id,$input);
    }
}
