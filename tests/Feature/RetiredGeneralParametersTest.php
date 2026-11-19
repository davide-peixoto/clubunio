<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class RetiredGeneralParametersTest extends TestCase
{
    public function test_cleanup_is_allowlisted_backed_up_and_idempotent(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Rollback fixtures require opt-in'); }
        $db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $db->getDatabaseName());
        $db->beginTransaction();
        try {
            $tenant = Tenant::create(['slug'=>'retired-'.Str::random(12),'name'=>'Cleanup fixture']);
            $actor = new Account;
            $actor->forceFill(['username'=>Str::random(25),'display_name'=>'Fixture','password'=>bcrypt('fixture'),'active'=>true])->save();
            $actor->tenants()->attach($tenant,['role'=>'admin']);
            $ids = [];
            foreach (['SommerWinter','StatusPlatz1','StatusPlatz2','StatusPlatz3','SaisonID','SIGNAGE_PERIOD_ID','OtherParameter'] as $key) {
                $ids[$key] = $db->table('clubunio.tenant_settings')->insertGetId(['tenant_id'=>$tenant->id,'area'=>'general','key'=>$key,'label'=>$key,
                    'value_type'=>'string','encrypted_value'=>Crypt::encryptString('fixture-value'),'enabled'=>false,'is_secret'=>false]);
            }
            $db->table('clubunio.tenant_settings')->insert(['tenant_id'=>$tenant->id,'area'=>'app','key'=>'SommerWinter','label'=>'Unrelated area',
                'value_type'=>'string','encrypted_value'=>Crypt::encryptString('preserve')]);
            $audit = $db->table('clubunio.setting_changes')->insertGetId(['tenant_id'=>$tenant->id,'setting_id'=>$ids['SommerWinter'],'actor_id'=>$actor->id]);
            $before = glob(storage_path('app/private/migration-backups/retired-general-*.json')) ?: [];
            $migration = require base_path('database/clubunio/2026_11_03_000091_retire_general_parameters.php');
            $migration->up();
            $this->assertEqualsCanonicalizing(['SaisonID','SIGNAGE_PERIOD_ID','OtherParameter'], $db->table('clubunio.tenant_settings')->where('tenant_id',$tenant->id)->where('area','general')->pluck('key')->all());
            $this->assertSame(1,$db->table('clubunio.tenant_settings')->where('tenant_id',$tenant->id)->where('area','app')->count());
            $files = array_values(array_diff(glob(storage_path('app/private/migration-backups/retired-general-*.json')), $before));
            $this->assertCount(1,$files);
            $backup = json_decode(file_get_contents($files[0]),true,512,JSON_THROW_ON_ERROR);
            $saved = collect($backup['settings'])->firstWhere('id',$ids['SommerWinter']);
            $this->assertSame('fixture-value',Crypt::decryptString($saved['encrypted_value']));
            $this->assertNotNull(collect($backup['changes'])->firstWhere('id',$audit));
            $this->assertFalse($db->table('clubunio.setting_changes')->where('id',$audit)->exists());
            $migration->up();
            $this->assertCount(count($before)+1,glob(storage_path('app/private/migration-backups/retired-general-*.json')));
        } finally {
            $db->rollBack();
        }
    }
}
