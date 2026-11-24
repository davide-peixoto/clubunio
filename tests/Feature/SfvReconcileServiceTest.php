<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{SfvReconcileService,SfvPlanningService,SfvCompetitionService,TeamPlanningService,PitchPlanningProjection,ModulePermissionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SfvReconcileServiceTest extends TestCase
{
    private Account $actor;
    private Tenant $tenant;
    private $db;
    protected function setUp():void
    {
        parent::setUp();config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:']]);DB::purge('clubunio');
        $this->db=DB::connection('clubunio');$this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach([
            'tenants'=>'id INTEGER PRIMARY KEY',
            'teams'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,sfv_team_id INTEGER,active INTEGER',
            'seasons'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,active INTEGER,starts_on TEXT,ends_on TEXT',
            'team_seasons'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,team_id INTEGER,season_id INTEGER,active INTEGER',
            'team_events'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,team_season_id INTEGER,sfv_match_id INTEGER,starts_at TEXT,ends_at TEXT,location TEXT,status TEXT,deleted_at TEXT',
            'competitions'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,association_match_id INTEGER,active INTEGER',
            'sfv_schedule'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,match_id INTEGER,revision INTEGER,home INTEGER,title TEXT,state TEXT,starts_at TEXT,location TEXT,team_a_id INTEGER,team_b_id INTEGER,match_type TEXT',
        ] as $table=>$columns)$this->db->statement('CREATE TABLE clubora.'.$table.' ('.$columns.')');
        $this->db->table('clubora.tenants')->insert(['id'=>4]);
        $this->db->table('clubora.teams')->insert(['id'=>7,'tenant_id'=>4,'sfv_team_id'=>700,'active'=>1]);
        $this->db->table('clubora.seasons')->insert(['id'=>8,'tenant_id'=>4,'active'=>1,'starts_on'=>'2029-07-01','ends_on'=>'2030-06-30']);
        $this->db->table('clubora.team_seasons')->insert(['id'=>9,'tenant_id'=>4,'team_id'=>7,'season_id'=>8,'active'=>1]);
        $this->actor=(new Account)->forceFill(['id'=>1,'active'=>true]);$this->tenant=(new Tenant)->forceFill(['id'=>4]);
        $this->app->instance(ModulePermissionService::class,new class {public function authorize(...$args):void{}});
        $this->mock(TeamPlanningService::class)->shouldReceive('table')->andReturnUsing(fn($tenant,$name,$removed=false)=>$this->db->table('clubora.'.$name)->where('tenant_id',$tenant->id));
    }
    private function match(bool $home=false):void
    {
        $this->db->table('clubora.sfv_schedule')->insert(['id'=>1,'tenant_id'=>4,'match_id'=>900,'revision'=>1,'home'=>$home,'title'=>'Own v Other','state'=>'Angesetzt','starts_at'=>'2030-01-10T12:00:00+01:00','location'=>'Open field','team_a_id'=>700,'team_b_id'=>701,'match_type'=>'Meisterschaft']);
    }
    private function runReconcile():array{return app(SfvReconcileService::class)->reconcile($this->actor,$this->tenant,'2030-01-10','2030-01-10');}
    private function import():void
    {
        $this->mock(SfvPlanningService::class)->shouldReceive('import')->once()->andReturnUsing(function($actor,$tenant,$id,$data){
            $this->assertSame(9,$data['team_season_id']);$this->assertSame(1,$data['sfv_revision']);
            return $this->db->table('clubora.team_events')->insertGetId(['tenant_id'=>4,'team_season_id'=>9,'sfv_match_id'=>900,'starts_at'=>'2030-01-10T12:00:00+01:00','ends_at'=>'2030-01-10T14:00:00+01:00','location'=>$data['location'],'status'=>'AKTIV']);
        });
    }
    public function test_unambiguous_away_game_enters_calendar_once_without_a_local_pitch_booking():void
    {
        $this->match();$this->import();$this->mock(SfvCompetitionService::class)->shouldNotReceive('apply');
        $this->assertSame(1,$this->runReconcile()['new']);$this->assertSame(1,$this->runReconcile()['unchanged']);
        $this->assertSame(1,$this->db->table('clubora.team_events')->count());$this->assertSame(0,$this->db->table('clubora.competitions')->count());
    }
    public function test_ambiguous_team_mapping_requires_review_without_any_writes():void
    {
        $this->match();$this->db->table('clubora.team_seasons')->insert(['id'=>10,'tenant_id'=>4,'team_id'=>7,'season_id'=>8,'active'=>1]);
        $this->mock(SfvPlanningService::class)->shouldNotReceive('import');$result=$this->runReconcile();
        $this->assertCount(1,$result['issues']);$this->assertSame(0,$result['new']);$this->assertSame(0,$this->db->table('clubora.team_events')->count());
    }
    public function test_undated_match_is_reported_for_review_without_creating_an_event():void
    {
        $this->match();$this->db->table('clubora.sfv_schedule')->update(['starts_at'=>null]);
        $this->mock(SfvPlanningService::class)->shouldNotReceive('import');$result=$this->runReconcile();
        $this->assertSame(trans('daily.sfv_time'),$result['issues'][0]['message']);
        $this->assertSame(0,$this->db->table('clubora.team_events')->count());
    }
    public function test_existing_local_time_change_is_not_overwritten():void
    {
        $this->match();$this->db->table('clubora.team_events')->insert(['tenant_id'=>4,'team_season_id'=>9,'sfv_match_id'=>900,'starts_at'=>'2030-01-10T13:00:00+01:00','ends_at'=>'2030-01-10T15:00:00+01:00','location'=>'Open field','status'=>'AKTIV']);
        $this->mock(SfvPlanningService::class)->shouldNotReceive('import');$this->assertCount(1,$this->runReconcile()['issues']);
        $this->assertSame('2030-01-10T13:00:00+01:00',$this->db->table('clubora.team_events')->value('starts_at'));
    }
    public function test_home_game_and_calendar_are_atomic_when_calendar_creation_fails():void
    {
        $this->match(true);$preview=['target_id'=>null,'before'=>null,'after'=>['plays_on'=>'2030-01-10','starts_at'=>'12:00','field_id'=>12,'association_match_id'=>900],'field'=>'Open field'];
        $competitions=$this->mock(SfvCompetitionService::class);$competitions->shouldReceive('preview')->andReturn($preview);
        $competitions->shouldReceive('apply')->once()->andReturnUsing(fn()=>$this->db->table('clubora.competitions')->insertGetId(['tenant_id'=>4,'association_match_id'=>900,'active'=>1]));
        $pitch=$this->mock(PitchPlanningProjection::class);$pitch->shouldReceive('ready')->andReturn(true);$pitch->shouldReceive('fields')->andReturn([['id'=>12,'name'=>'Open field','available'=>true,'areas'=>[]]]);$pitch->shouldReceive('entries')->andReturn([]);
        $pitch->shouldReceive('competition')->andReturn(['key'=>'competition:0','date'=>'2030-01-10','field_id'=>12,'area_id'=>null,'starts_at_iso'=>'2030-01-10T12:00:00+01:00','ends_at_iso'=>'2030-01-10T14:00:00+01:00']);
        $this->mock(SfvPlanningService::class)->shouldReceive('import')->once()->andThrow(ValidationException::withMessages(['team'=>'Denied calendar creation']));
        $this->assertCount(1,$this->runReconcile()['issues']);$this->assertSame(0,$this->db->table('clubora.competitions')->count());
    }
    public function test_existing_reschedule_stays_a_review_item():void
    {
        $this->match(true);$this->mock(SfvCompetitionService::class)->shouldReceive('preview')->andReturn(['target_id'=>77,'before'=>['plays_on'=>'2030-01-10','starts_at'=>'11:00','field_id'=>12],'after'=>['plays_on'=>'2030-01-10','starts_at'=>'12:00','field_id'=>12]]);
        $this->mock(SfvPlanningService::class)->shouldNotReceive('import');$this->assertCount(1,$this->runReconcile()['issues']);
    }
}
