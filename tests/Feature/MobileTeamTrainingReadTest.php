<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileTeamsData;
use App\ClubUnio\Models\{Tenant, TrainingOption};
use App\ClubUnio\Services\{ActiveTrainingPlan, SignageService};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileTeamTrainingReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.training_options(id integer primary key,tenant_id integer,category text,name text,active integer);
CREATE TABLE clubora.training_slots(id integer primary key,tenant_id integer,team_season_id integer,period_id integer,weekday_id integer,starts_at text,ends_at text,field_id integer,area_id integer,hall text,all_fields integer default 0,without_main integer default 0,synthetic_only integer default 0,active integer default 1);
SQL);
        $db->table('clubora.training_options')->insert([
            ['id'=>100,'tenant_id'=>9,'category'=>'period','name'=>'Winter','active'=>true],
            ['id'=>101,'tenant_id'=>9,'category'=>'period','name'=>'Summer','active'=>true],
        ]);
        foreach ([
            1=>[], 2=>['hall'=>'Example hall'], 3=>['synthetic_only'=>true],
            4=>['all_fields'=>true], 5=>['period_id'=>101], 6=>['tenant_id'=>10],
            7=>['team_season_id'=>21], 8=>['active'=>false],
        ] as $id=>$overrides) {
            $db->table('clubora.training_slots')->insert($overrides + [
                'id'=>$id,'tenant_id'=>9,'team_season_id'=>20,'period_id'=>100,
                'weekday_id'=>1,'starts_at'=>'18:00','ends_at'=>'19:30',
            ]);
        }
        $period = new TrainingOption; $period->forceFill(['id'=>100]);
        $this->mock(ActiveTrainingPlan::class)->shouldReceive('period')->andReturn($period);
        $this->mock(SignageService::class)->shouldReceive('trainingVariant')->andReturn('synthetic_only');
    }

    protected function tearDown(): void { DB::purge('clubunio'); parent::tearDown(); }

    public function test_regular_and_indoor_training_survive_field_scenarios_without_leaking_other_scopes(): void
    {
        $tenant = new Tenant; $tenant->forceFill(['id'=>9, 'slug'=>'example']);
        // Only tests the internal projection; both public methods keep their fresh team authorization.
        $projection = new \ReflectionMethod(MobileTeamsData::class, 'trainingsForAuthorizedTeam');
        $rows = $projection->invoke(app(MobileTeamsData::class), $tenant, 20);
        $this->assertSame([1,2,3], array_column($rows, 'TrainingID'));
        $this->assertSame('Example hall', $rows[1]['Halle']);
        $this->assertSame(['Winter'], array_values(array_unique(array_column($rows, 'SommerWinter'))));
    }

    public function test_missing_active_plan_does_not_mix_summer_and_winter(): void
    {
        $this->forgetMock(ActiveTrainingPlan::class);
        $this->mock(ActiveTrainingPlan::class)->shouldReceive('period')->andReturnNull();
        $tenant = new Tenant; $tenant->forceFill(['id'=>9, 'slug'=>'example']);
        $projection = new \ReflectionMethod(MobileTeamsData::class, 'trainingsForAuthorizedTeam');
        $this->assertSame([], $projection->invoke(app(MobileTeamsData::class), $tenant, 20));
    }
}
