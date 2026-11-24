<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant, SavedView};
use App\ClubUnio\Services\{ApprovalQueue, BackofficeReadContext, MemberChangeService, WorkspaceAccess, WorkspaceConflicts, WorkspaceLinks, WorkspacePreferences};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceServicesTest extends TestCase
{
    private Account $actor;
    private Tenant $tenant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:']]); DB::purge('clubunio');
        $db=DB::connection('clubunio'); $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.accounts (id INTEGER PRIMARY KEY)'); $db->table('clubora.accounts')->insert(['id'=>1]);
        $db->statement('CREATE TABLE clubora.saved_views (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,account_id INTEGER,list_key TEXT,name TEXT,state_encrypted TEXT,is_default INTEGER,revision INTEGER,created_at TEXT,updated_at TEXT)');
        $this->actor=new class extends Account {
            public function fresh($with=[]) { return $this; }
            public function hasUnrestrictedBackofficeAccess(\Illuminate\Database\Eloquent\Model $tenant): bool { return false; }
        };
        $this->actor->forceFill(['id'=>1,'active'=>true]); $this->tenant=(new Tenant)->forceFill(['id'=>4]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio')); Filament::auth()->setUser($this->actor); Filament::setTenant($this->tenant,isQuiet:true);
        $this->mock(WorkspaceAccess::class)->shouldReceive('allowed')->andReturn(true);
    }

    public function test_preferences_are_encrypted_and_isolated_by_account_and_tenant(): void
    {
        $this->mock(WorkspaceLinks::class)->shouldReceive('available')->andReturn(['members'=>['label'=>'Members']]);
        $prefs=app(WorkspacePreferences::class); $prefs->toggle($this->actor,$this->tenant,'members');
        $this->assertSame(['members'],$prefs->read($this->actor,$this->tenant)['favorites']);
        $raw=DB::connection('clubunio')->table('clubora.saved_views')->value('state_encrypted');
        $this->assertStringNotContainsString('members',$raw);
        $this->assertSame([], $prefs->read((new Account)->forceFill(['id'=>2]),$this->tenant)['favorites']);
        $this->assertSame([], $prefs->read($this->actor,(new Tenant)->forceFill(['id'=>5]))['favorites']);
        $prefs->toggle($this->actor,$this->tenant,'members'); $this->assertSame([],$prefs->read($this->actor,$this->tenant)['favorites']);
        $this->assertSame(1,SavedView::count());
    }

    public function test_forged_favorite_cannot_store_an_external_link(): void
    {
        $this->mock(WorkspaceLinks::class)->shouldReceive('available')->andReturn([]);
        try { app(WorkspacePreferences::class)->toggle($this->actor,$this->tenant,'https://evil.example'); $this->fail('Must deny'); }
        catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertSame(0,SavedView::count());
    }

    public function test_clearing_recents_preserves_favorites_and_other_users(): void
    {
        foreach ([1,2] as $id) (new SavedView)->forceFill(['tenant_id'=>4,'account_id'=>$id,'list_key'=>'workspace','name'=>'personal','revision'=>1,
            'state_encrypted'=>['favorites'=>['members'],'recent'=>[['type'=>'members','id'=>42]]]])->save();
        app(WorkspacePreferences::class)->clear($this->actor,$this->tenant);
        $this->assertSame(['favorites'=>['members'],'recent'=>[]],app(WorkspacePreferences::class)->read($this->actor,$this->tenant));
        $this->assertCount(1,app(WorkspacePreferences::class)->read((new Account)->forceFill(['id'=>2]),$this->tenant)['recent']);
    }

    public function test_queue_visibility_requires_both_read_and_approval_permissions(): void
    {
        $reads=$this->mock(BackofficeReadContext::class); $reads->shouldReceive('tenantsFor')->andReturn(null);
        $reads->shouldReceive('permissionCache')->andReturn(['member_changes.view','member_changes.approve','expenses.view','bus.approve']);
        $this->assertSame(['change'=>trans('workspace.kind_change')],app(ApprovalQueue::class)->kinds($this->actor,$this->tenant));
    }

    public function test_stale_decision_never_reaches_the_domain_service(): void
    {
        $queue=new class extends ApprovalQueue { public function preview(Account $a,Tenant $t,string $kind,int $id): array { return ['revision'=>'5','decisions'=>['approve','reject']]; } };
        $this->mock(MemberChangeService::class)->shouldNotReceive('review');
        $this->expectException(ValidationException::class);
        $queue->decide($this->actor,$this->tenant,'change',42,'4','approve','Checked all submitted changes');
    }

    public function test_valid_decision_delegates_original_revision_and_note(): void
    {
        $queue=new class extends ApprovalQueue { public function preview(Account $a,Tenant $t,string $kind,int $id): array { return ['revision'=>'5','decisions'=>['approve','reject']]; } };
        $this->mock(MemberChangeService::class)->shouldReceive('review')->once()->with($this->actor,$this->tenant,42,5,false,'Please correct the address');
        $queue->decide($this->actor,$this->tenant,'change',42,'5','reject','Please correct the address');
        $this->addToAssertionCount(1);
    }

    public function test_payment_or_arbitrary_decisions_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ApprovalQueue::class)->decide($this->actor,$this->tenant,'expense',42,'1','pay','Checked all submitted changes');
    }

    public function test_bus_conflicts_respect_boundaries_offsets_and_resource_identity(): void
    {
        $row=fn($id,$bus,$from,$to)=>(object)['id'=>$id,'bus_id'=>$bus,'starts_at'=>$from,'ends_at'=>$to];
        $rows=[$row(1,7,'2026-11-24T10:00:00+01:00','2026-11-24T11:00:00+01:00'),
            $row(2,7,'2026-11-24T10:30:00+01:00','2026-11-24T12:00:00+01:00'),
            $row(3,7,'2026-11-24T12:00:00+01:00','2026-11-24T13:00:00+01:00'),
            $row(4,8,'2026-11-24T09:00:00Z','2026-11-24T10:00:00Z')];
        $pairs=app(WorkspaceConflicts::class)->busPairs($rows);
        $this->assertCount(1,$pairs); $this->assertSame([1,2],array_map(fn($r)=>$r->id,$pairs[0]));
    }

    public function test_language_catalogs_are_utf8_and_placeholders_match(): void
    {
        $de=require lang_path('de/workspace.php');$en=require lang_path('en/workspace.php');
        $this->assertSame(array_keys($de),array_keys($en)); $this->assertIsString(json_encode([$de,$en],JSON_THROW_ON_ERROR));
        foreach ($de as $key=>$value) { preg_match_all('/:\w+/',$value,$left);preg_match_all('/:\w+/',$en[$key],$right);$this->assertSame($left[0],$right[0],$key); }
    }

    public function test_every_existing_saved_list_has_a_workspace_destination(): void
    {
        $expected=\App\ClubUnio\Services\SavedViewService::LISTS;
        $actual=array_keys(WorkspaceLinks::LISTS); sort($expected);sort($actual);
        $this->assertSame($expected,$actual);
    }

    public function test_workspace_pages_stay_in_the_primary_navigation_in_both_languages(): void
    {
        $reads=$this->mock(BackofficeReadContext::class);$reads->shouldReceive('tenantsFor')->andReturn(null);$reads->shouldReceive('permissionCache')->andReturn([]);
        $pages=[\App\ClubUnio\Pages\MyClub::class,\App\ClubUnio\Pages\ApprovalCenter::class,\App\ClubUnio\Pages\PlanningConflicts::class];
        $panel=\Mockery::mock(\Filament\Panel::class)->makePartial();
        $panel->shouldReceive('getResources')->andReturn([]);$panel->shouldReceive('getPages')->andReturn($pages);
        foreach(['de','en'] as $locale) {
            app()->setLocale($locale);
            $items=array_map(fn($page)=>\Filament\Navigation\NavigationItem::make($page::getNavigationLabel())->url($page::getNavigationUrl()),$pages);
            $result=\App\ClubUnio\NavigationStructure::partition($panel,[\Filament\Navigation\NavigationGroup::make()->items($items)]);
            $this->assertCount(1,$result['navigation']);
            $this->assertSame(trans('workspace.group'),$result['navigation'][0]->getLabel());
            $this->assertCount(3,$result['navigation'][0]->getItems());
            $this->assertSame([],$result['settings']);
        }
    }

    public function test_inactive_api_and_impersonated_accounts_cannot_enter(): void
    {
        $access=new WorkspaceAccess;
        $this->assertFalse($access->allowed(null,$this->tenant));
        $this->assertFalse($access->allowed((new Account)->forceFill(['active'=>false]),$this->tenant));
        $this->assertFalse($access->allowed((new Account)->forceFill(['active'=>true,'api_only'=>true]),$this->tenant));
        request()->attributes->set('clubunio_impersonation',(object)['target_id'=>1]);
        $this->assertFalse($access->allowed($this->actor,$this->tenant));
    }
}
