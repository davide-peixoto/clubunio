<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Invoice,Tenant};
use App\ClubUnio\Services\{DashboardService,ModulePermissionService};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAggregateTest extends TestCase
{
    public function test_summary_matches_existing_list_with_balance_priority_rounding_and_tenant_scope():void
    {
        config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:']]);DB::purge('clubunio');
        $db=DB::connection('clubunio');$db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach(['invoices'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,active INTEGER,rounding_cents INTEGER',
            'invoice_lines'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,invoice_id INTEGER,status_id INTEGER,active INTEGER,net_amount NUMERIC,gross_amount NUMERIC',
            'invoice_line_statuses'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,code TEXT',
            'bexio_balances'=>'id INTEGER PRIMARY KEY,tenant_id INTEGER,object_id INTEGER,object_type TEXT,remaining_cents INTEGER'] as $table=>$columns)$db->statement('CREATE TABLE clubora.'.$table.' ('.$columns.')');
        foreach(['O','T','U','B'] as $i=>$code)$db->table('clubora.invoice_line_statuses')->insert(['id'=>$i+1,'tenant_id'=>4,'code'=>$code]);
        foreach(range(1,11) as $id)$db->table('clubora.invoices')->insert(['id'=>$id,'tenant_id'=>$id===8?5:4,'active'=>$id!==7,'rounding_cents'=>match($id){3=>2,4=>-1,default=>0}]);
        foreach([1=>99,2=>100,3=>100,4=>0,5=>10,6=>50,7=>50,8=>10000,9=>10,10=>null,11=>-2] as $id=>$net)$db->table('clubora.invoice_lines')->insert(['id'=>$id,'tenant_id'=>$id===8?5:4,'invoice_id'=>$id,'status_id'=>match($id){4=>2,6=>4,11=>3,default=>1},'active'=>$id!==5,'net_amount'=>$net,'gross_amount'=>$id===4?5.50:null]);
        foreach([1=>12345,2=>0,9=>null] as $id=>$cents)$db->table('clubora.bexio_balances')->insert(['id'=>$id,'tenant_id'=>4,'object_id'=>$id,'object_type'=>'invoice','remaining_cents'=>$cents]);
        $actor=(new Account)->forceFill(['id'=>1]);$tenant=(new Tenant)->forceFill(['id'=>4]);
        $permissions=new class{public int $calls=0;public function authorize($actor,$tenant,$module,$function):void{$this->calls++;}};
        $this->app->instance(ModulePermissionService::class,$permissions);
        $service=app(DashboardService::class);$query=$service->scopeOpenInvoices(Invoice::where('tenant_id',4)->where('active',true),$tenant);
        // SQLite accepts attached schemas in FROM, but not PostgreSQL's three-part column references.
        $sql=preg_replace('/"clubora"\."([a-z_]+)"\./','"$1".',$query->toSql());
        $listed=collect($db->select($sql,$query->getBindings()));
        $summary=$service->openSummary($actor,$tenant);
        $this->assertSame([1,3,4,10,11],$listed->pluck('id')->sort()->values()->all());
        $this->assertSame($listed->count(),$summary['count']);
        $this->assertEqualsWithDelta($listed->sum('open_amount'),(float)$summary['amount'],.000001);
        $this->assertEqualsWithDelta(226.96,(float)$summary['amount'],.000001);
        $this->assertSame(1,$permissions->calls);
    }
}
