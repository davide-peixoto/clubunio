<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant,Expense,ExpenseLine};
use App\ClubUnio\Mobile\MalwareScanner;
use App\ClubUnio\Services\{WorkGuidedCapture,WorkspaceAccess,BackofficeReadContext,ExpenseService,DocumentUploadService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuidedReceiptCaptureTest extends TestCase
{
    private $filePath;
    private Account $actor;
    private Tenant $tenant;
    private array $data=['member_id'=>1,'team_season_id'=>2,'bank_account_id'=>3,'round_id'=>4,'expense_type_id'=>5,'amount'=>'12.50'];
    protected function setUp():void
    {
        parent::setUp();config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:']]);DB::purge('clubunio');$db=DB::connection('clubunio');$db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.tenants(id INTEGER PRIMARY KEY)');$db->table('clubora.tenants')->insert(['id'=>4]);
        $db->statement('CREATE TABLE clubora.workspace_capture_commands(tenant_id INTEGER,account_id INTEGER,command_key TEXT,input_hash TEXT,result_url TEXT)');
        $this->actor=(new Account)->forceFill(['id'=>1,'active'=>true]);$this->tenant=(new Tenant)->forceFill(['id'=>4]);
        $this->mock(WorkspaceAccess::class)->shouldReceive('allowed')->andReturn(true);
        $reads=$this->mock(BackofficeReadContext::class);$reads->shouldReceive('tenantsFor')->andReturn(null);$reads->shouldReceive('permissionCache')->andReturn(['expenses.view','expenses.create','expenses.upload']);
        $this->filePath=tempnam(sys_get_temp_dir(),'daily-receipt');file_put_contents($this->filePath,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlTAAAAAASUVORK5CYII='));
    }
    protected function tearDown():void{if($this->filePath&&is_file($this->filePath))unlink($this->filePath);parent::tearDown();}
    private function service():WorkGuidedCapture{return new class extends WorkGuidedCapture{public function kinds():array{return ['expense'=>'Expense'];}};}
    private function receipt():UploadedFile{return new UploadedFile($this->filePath,'receipt.png','image/png',null,true);}
    public function test_scan_rejection_never_creates_a_draft_or_command():void
    {
        $this->app->instance(MalwareScanner::class,new class implements MalwareScanner{public function scan(string $bytes):array{return ['clean'=>false,'version'=>'fixture'];}});
        $this->mock(ExpenseService::class)->shouldNotReceive('save');
        try{$this->service()->save($this->actor,$this->tenant,'expense',$this->data,(string)Str::uuid(),$this->receipt());$this->fail('Rejected receipt accepted');}catch(ValidationException $e){$this->assertArrayHasKey('receipt',$e->errors());}
        $this->assertSame(0,DB::connection('clubunio')->table('clubora.workspace_capture_commands')->count());
    }
    public function test_pending_transfer_keeps_one_draft_and_retries_the_same_upload():void
    {
        $this->app->instance(MalwareScanner::class,new class implements MalwareScanner{public function scan(string $bytes):array{return ['clean'=>true,'version'=>'fixture'];}});
        $expense=\Mockery::mock(Expense::class)->makePartial();$expense->forceFill(['id'=>51,'revision'=>2]);$expense->shouldReceive('refresh')->andReturnSelf();
        $line=(new ExpenseLine)->forceFill(['id'=>61,'revision'=>1]);
        $native=$this->mock(ExpenseService::class);$native->shouldReceive('save')->once()->andReturn($expense);$native->shouldReceive('saveLine')->once()->andReturn($line);
        $uploads=$this->mock(DocumentUploadService::class);$uploads->shouldReceive('stage')->once()->andReturn(71);$uploads->shouldReceive('attempt')->twice()->with($this->actor,$this->tenant,71)->andThrow(ValidationException::withMessages(['file'=>'Pending transfer']));
        $command=(string)Str::uuid();$service=$this->service();$first=$service->save($this->actor,$this->tenant,'expense',$this->data,$command,$this->receipt());$second=$service->save($this->actor,$this->tenant,'expense',$this->data,$command,$this->receipt());
        $this->assertSame($first,$second);$this->assertStringContainsString('captureUpload=71',$first);$this->assertStringContainsString('receipt_pending=1',$first);
        $this->assertSame(1,DB::connection('clubunio')->table('clubora.workspace_capture_commands')->count());
        file_put_contents($this->filePath,'changed',FILE_APPEND);
        try{$service->save($this->actor,$this->tenant,'expense',$this->data,$command,$this->receipt());$this->fail('Different receipt reused command');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
    }
}
