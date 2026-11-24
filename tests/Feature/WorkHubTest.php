<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{WorkTaskService,WorkNotificationHub,WorkConflictSolver,WorkObjectSearch,WorkGuidedCapture,WorkspaceAccess,BackofficeReadContext};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkHubTest extends TestCase
{
    private Account $actor;
    private Tenant $tenant;
    private array $permissions=['tasks.view','tasks.create','tasks.update','tasks.complete','tasks.deactivate','tasks.restore'];
    protected function setUp():void
    {
        parent::setUp();config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:']]);DB::purge('clubunio');
        $db=DB::connection('clubunio');$db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.tenants(id INTEGER PRIMARY KEY)');$db->table('clubora.tenants')->insert(['id'=>4]);
        $db->statement('CREATE TABLE clubora.accounts(id INTEGER PRIMARY KEY,display_name TEXT,active INTEGER,api_only INTEGER)');
        $db->table('clubora.accounts')->insert([['id'=>1,'display_name'=>'One','active'=>1,'api_only'=>0],['id'=>2,'display_name'=>'Two','active'=>1,'api_only'=>0]]);
        $db->statement("CREATE TABLE clubora.workspace_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,created_by INTEGER,assigned_to INTEGER,title_encrypted TEXT,body_encrypted TEXT,due_on TEXT,state TEXT,revision INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT)");
        $db->statement('CREATE TABLE clubora.workspace_task_events(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,task_id INTEGER,actor_id INTEGER,operation TEXT,revision INTEGER)');
        $db->statement('CREATE TABLE clubora.notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,account_id INTEGER,object_type TEXT,object_id INTEGER,title TEXT,body TEXT,read_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->actor=(new Account)->forceFill(['id'=>1,'active'=>true,'api_only'=>false]);$this->tenant=(new Tenant)->forceFill(['id'=>4]);
        $this->mock(WorkspaceAccess::class)->shouldReceive('allowed')->andReturnUsing(fn($a,$t)=>(bool)$a?->active && (int)$t->id===4);
        $reads=$this->mock(BackofficeReadContext::class);$reads->shouldReceive('tenantsFor')->andReturn(null);
        $reads->shouldReceive('permissionCache')->andReturnUsing(fn()=> $this->permissions);
    }
    private function task():int{return app(WorkTaskService::class)->save($this->actor,$this->tenant,null,['title'=>'Private planning','body'=>'Details','assigned_to'=>1,'due_on'=>'2026-12-01']);}
    public function test_task_is_encrypted_and_revision_guards_changes():void
    {
        $id=$this->task();$s=app(WorkTaskService::class);$row=$s->get($this->actor,$this->tenant,$id);
        $this->assertSame('Private planning',$row->title);$this->assertStringNotContainsString('Private',$row->title_encrypted);
        $s->transition($this->actor,$this->tenant,$id,1,'doing');$this->assertSame(2,$s->get($this->actor,$this->tenant,$id)->revision);
        try{$s->transition($this->actor,$this->tenant,$id,1,'done');$this->fail('Stale transition accepted');}catch(ValidationException){}
        $this->assertSame('doing',$s->get($this->actor,$this->tenant,$id)->state);
        $s->transition($this->actor,$this->tenant,$id,2,'archived');$s->transition($this->actor,$this->tenant,$id,3,'open');
        $this->assertSame(4,DB::connection('clubunio')->table('clubora.workspace_task_events')->count());
    }
    public function test_tasks_are_scoped_and_reassignment_needs_its_own_permission():void
    {
        $id=$this->task();$s=app(WorkTaskService::class);
        $other=(new Account)->forceFill(['id'=>2,'active'=>true]);$this->assertSame(0,$s->visible($other,$this->tenant)->count());
        try{$s->save($this->actor,$this->tenant,$id,['title'=>'Changed','assigned_to'=>2,'revision'=>1]);$this->fail('Assignment leaked');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->permissions=[];$this->expectException(HttpException::class);$s->get($this->actor,$this->tenant,$id);
    }
    public function test_notification_read_requires_owner_and_current_source_access():void
    {
        $id=$this->task();$db=DB::connection('clubunio');$notice=$db->table('clubora.notifications')->insertGetId(['tenant_id'=>4,'account_id'=>2,'object_type'=>'workspace_task','object_id'=>$id,'title'=>'Test','body'=>'']);
        try{app(WorkNotificationHub::class)->read($this->actor,$this->tenant,$notice);$this->fail('Foreign notice read');}catch(HttpException $e){$this->assertSame(404,$e->getStatusCode());}
        $this->assertNull($db->table('clubora.notifications')->value('read_at'));
        $db->table('clubora.notifications')->update(['account_id'=>1]);app(WorkNotificationHub::class)->read($this->actor,$this->tenant,$notice);
        $this->assertNotNull($db->table('clubora.notifications')->value('read_at'));
    }
    public function test_solver_respects_other_team_events_and_unavailable_fields():void
    {
        $source=['key'=>'request:1:2026-11-24','date'=>'2026-11-24','source'=>'request','status'=>'approved','field_id'=>1,'area_id'=>null,'team_season_ids'=>[7],'duration_minutes'=>60,'starts_at_iso'=>'2026-11-24T10:00:00+01:00','ends_at_iso'=>'2026-11-24T11:00:00+01:00'];
        $other=array_replace($source,['key'=>'request:2:2026-11-24','field_id'=>2,'starts_at_iso'=>'2026-11-24T09:00:00+01:00','ends_at_iso'=>'2026-11-24T11:00:00+01:00']);
        $fields=[['id'=>1,'name'=>'A','available'=>true,'areas'=>[]],['id'=>2,'name'=>'B','available'=>true,'areas'=>[]],['id'=>3,'name'=>'C','available'=>false,'areas'=>[]]];
        $items=app(WorkConflictSolver::class)->alternatives($source,[$source,$other],$fields);$this->assertNotEmpty($items);$this->assertLessThanOrEqual(5,count($items));
        foreach($items as $item){$this->assertNotSame(3,$item['field_id']);$this->assertGreaterThanOrEqual('11:00',$item['starts_at']);}
    }
    public function test_search_wildcards_are_literal_and_catalogues_match():void
    {
        $this->assertSame('%a!%!_!!%',WorkObjectSearch::pattern(' A%_! '));
        $de=require lang_path('de/work_hub.php');$en=require lang_path('en/work_hub.php');$this->assertSame(array_keys($de),array_keys($en));json_encode([$de,$en],JSON_THROW_ON_ERROR);
        foreach($de as $key=>$value){preg_match_all('/:\w+/',$value,$a);preg_match_all('/:\w+/',$en[$key],$b);$this->assertSame($a[0],$b[0],$key);}
    }
    public function test_guided_event_rejects_invalid_time_order():void
    {
        $this->expectException(ValidationException::class);
        app(WorkGuidedCapture::class)->validateStep('event',['kind'=>'TRAINING','mode'=>'ANMELDEN','starts_at'=>'2026-11-24T18:00','ends_at'=>'2026-11-24T17:00','deadline_at'=>'2026-11-24T17:00'],2);
    }
    public function test_solver_rechecks_revision_and_candidate_before_delegating():void
    {
        $proposal=['label'=>'A','date'=>'2026-11-24','starts_at'=>'12:00','field_id'=>1,'area_id'=>null,'duration_minutes'=>60];
        $source=['key'=>'request:1:2026-11-24','date'=>'2026-11-24','revision'=>'fresh'];
        $solver=new class($source,$proposal) extends WorkConflictSolver {
            public function __construct(private array $source,private array $proposal){}
            public function suggestions(Account $actor,Tenant $tenant,string $key,string $date):array{return ['source'=>$this->source,'items'=>[$this->proposal]];}
        };
        $native=$this->mock(\App\ClubUnio\Services\PitchPlanningService::class);
        try{$solver->apply($this->actor,$this->tenant,array_replace($source,['revision'=>'stale']),$proposal);$this->fail('Stale source moved');}catch(ValidationException){}
        try{$solver->apply($this->actor,$this->tenant,$source,array_replace($proposal,['field_id'=>99]));$this->fail('Forged candidate moved');}catch(ValidationException $e){$this->assertArrayHasKey('proposal',$e->errors());}
        $expected=$proposal;unset($expected['label']);$expected['revision']='fresh';
        $native->shouldReceive('move')->once()->with($this->actor,$this->tenant,$source['key'],$expected);
        $solver->apply($this->actor,$this->tenant,$source,$proposal);$this->addToAssertionCount(1);
    }

}
