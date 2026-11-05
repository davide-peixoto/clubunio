<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{ChatAttachments,ChatService,MalwareScanner};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChatImageCompressionUploadTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private array $actors=[], $tokens=[];
    private int $chat;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,
            'clubunio-mobile.uploads_enabled'=>true,'cache.default'=>'array']);
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        $this->db=DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name TO 'clubunio-chat-image-compression-rollback'");
        $this->db->statement("SET LOCAL lock_timeout TO '2s'"); $this->db->statement("SET LOCAL statement_timeout TO '15s'");
        $this->tenant=Tenant::create(['slug'=>'chat-image-'.bin2hex(random_bytes(10)),'name'=>'Synthetic chat images']);
        for($i=0;$i<3;$i++) {
            $a=new Account; $a->forceFill(['username'=>'chat-image-'.bin2hex(random_bytes(12)), 'display_name'=>'Synthetic image '.$i,
                'password'=>'not-an-authentication-fixture','active'=>true,'api_only'=>false])->save();
            $this->actors[]=$a;
            $this->db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$a->id,'role'=>'admin','portal_enabled'=>true]);
            $session=(string)Str::uuid(); $secret=bin2hex(random_bytes(32));
            $this->db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$a->id,'token_hash'=>hash('sha256',$secret),
                'device_name'=>'Synthetic image','expires_at'=>now()->addHour()->toIso8601String(),'created_at'=>now()->toIso8601String()]);
            $this->tokens[]=$session.'.'.$secret;
        }
        $this->chat=(int)app(ChatService::class)->direct($this->actors[0],$this->tenant,$this->actors[1]->id)->id;
        $this->base='/api/mobile/v1/clubs/'.$this->tenant->id.'/';
        $this->withToken($this->tokens[0]);
    }

    protected function tearDown(): void
    {
        if(isset($this->db)) {
            $id=$this->tenant->id ?? null;
            $this->db->disableQueryLog(); $this->db->flushQueryLog();
            while($this->db->transactionLevel()) $this->db->rollBack();
            if($id) $this->assertFalse($this->db->table('clubora.tenants')->where('id',$id)->exists(),'Synthetic tenant rolled back');
        }
        parent::tearDown();
    }

    private function source(bool $alpha=false): string
    {
        $image=imagecreatetruecolor($alpha?500:2000,$alpha?300:1400);
        if($alpha) {
            imagealphablending($image,false); imagesavealpha($image,true);
            imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
            imagefilledrectangle($image,100,50,300,250,imagecolorallocatealpha($image,20,100,230,0));
        } else {
            mt_srand(8912);
            for($y=0;$y<1400;$y++) for($x=0;$x<2000;$x++) imagesetpixel($image,$x,$y,mt_rand(0,0xffffff));
        }
        ob_start();
        try { $alpha?imagepng($image):imagejpeg($image,null,95); return ob_get_contents(); }
        finally { ob_end_clean(); imagedestroy($image); }
    }

    private function upload(string $client,string $bytes,string $name='Synthetic.jpg',?int $chat=null)
    {
        return $this->post($this->base.'chats/'.($chat ?? $this->chat).'/attachments',
            ['client_id'=>$client,'file'=>UploadedFile::fake()->createWithContent($name,$bytes)],['Accept'=>'application/json']);
    }

    public function test_real_upload_compresses_before_blob_then_scan_send_download_and_metadata_only_projection(): void
    {
        $source=$this->source(); $this->assertGreaterThan(524288,strlen($source));
        $client=(string)Str::uuid(); $id=$this->upload($client,$source)->assertCreated()->assertJsonPath('data.state','quarantine')->json('data.id');
        $row=$this->db->table('clubora.chat_attachments')->where('id',$id)->first();
        $canonical=Crypt::decryptString($row->bytes_encrypted);
        $this->assertLessThanOrEqual(358400,strlen($canonical));
        $this->assertSame(strlen($canonical),(int)$row->byte_count);
        $this->assertSame(hash('sha256',$canonical),$row->sha256);
        $this->assertNotSame($source,$canonical);
        $meta=json_decode(Crypt::decryptString($row->filename_encrypted),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(['v'=>1,'name'=>'Synthetic.jpg','source_sha256'=>hash('sha256',$source)],$meta);
        $this->upload($client,$source)->assertCreated()->assertJsonPath('data.id',$id);
        // Different raw sources can canonicalize identically: still a strict409.
        $this->upload($client,$source.'different raw source')->assertConflict();
        $otherChat=app(ChatService::class)->direct($this->actors[0],$this->tenant,$this->actors[2]->id);
        $this->upload($client,$source,'Synthetic.jpg',$otherChat->id)->assertConflict();
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->with($canonical)->andReturn(['clean'=>true,'version'=>'synthetic']);
        $this->get($this->base.'attachments/'.$id,['Accept'=>'application/json'])->assertNotFound();
        $this->postJson($this->base.'attachments/'.$id.'/scan')->assertOk()->assertJsonPath('data.state','clean');
        $message=$this->postJson($this->base.'chats/'.$this->chat.'/messages',
            ['client_id'=>(string)Str::uuid(),'text'=>'Synthetic compressed picture','attachment_id'=>$id])->assertCreated()->json('data');
        $this->assertSame('Synthetic.jpg',$message['attachment']['name']);
        $this->get($this->base.'attachments/'.$id)->assertOk()->assertContent($canonical)->assertHeader('Content-Type','image/jpeg');
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        try { $projection=app(ChatAttachments::class)->forMessages($this->tenant,[$message['id']]); $queries=$this->db->getQueryLog(); }
        finally { $this->db->disableQueryLog(); }
        $this->assertSame('Synthetic.jpg',$projection[$message['id']]['name']);
        foreach($queries as $query) $this->assertStringNotContainsString('bytes_encrypted',$query['query']);
        $this->assertStringNotContainsString('source_sha256',json_encode($projection));
        $this->withToken($this->tokens[2])->get($this->base.'attachments/'.$id,['Accept'=>'application/json'])->assertNotFound();
    }

    public function test_alpha_and_pdf_contracts_are_preserved_without_plaintext_metadata_leak(): void
    {
        $id=$this->upload((string)Str::uuid(),$this->source(true),'Transparent.png')->assertCreated()->json('data.id');
        $row=$this->db->table('clubora.chat_attachments')->where('id',$id)->first();
        $this->assertSame('image/webp',$row->mime_type);
        $meta=json_decode(Crypt::decryptString($row->filename_encrypted),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('Transparent.webp',$meta['name']);
        $decoded=imagecreatefromstring(Crypt::decryptString($row->bytes_encrypted));
        try { $this->assertSame(127,(imagecolorat($decoded,1,1)>>24)&127); } finally { imagedestroy($decoded); }
        $pdf="%PDF-1.4\nSynthetic unchanged PDF\n%%EOF";
        $pdfId=$this->upload((string)Str::uuid(),$pdf,'Original.pdf')->assertCreated()->json('data.id');
        $pdfRow=$this->db->table('clubora.chat_attachments')->where('id',$pdfId)->first();
        $this->assertSame($pdf,Crypt::decryptString($pdfRow->bytes_encrypted));
        $this->assertSame('Original.pdf',Crypt::decryptString($pdfRow->filename_encrypted));
        $this->assertSame(hash('sha256',$pdf),$pdfRow->sha256);
        $this->assertSame('application/pdf',$pdfRow->mime_type);
    }

    public function test_old_large_raw_image_and_filename_replay_without_reencoding_and_rejected_bytes_stay_purged(): void
    {
        $source=$this->source(); $client=(string)Str::uuid(); $id=(string)Str::uuid();
        $this->db->table('clubora.chat_attachments')->insert(['id'=>$id,'tenant_id'=>$this->tenant->id,'conversation_id'=>$this->chat,
            'account_id'=>$this->actors[0]->id,'client_id'=>$client,'filename_encrypted'=>Crypt::encryptString('Legacy.jpg'),
            'bytes_encrypted'=>Crypt::encryptString($source),'sha256'=>hash('sha256',$source),'byte_count'=>strlen($source),
            'mime_type'=>'image/jpeg','state'=>'quarantine']);
        $this->upload($client,$source)->assertCreated()->assertJsonPath('data.id',$id);
        $row=$this->db->table('clubora.chat_attachments')->where('id',$id)->first();
        $this->assertSame($source,Crypt::decryptString($row->bytes_encrypted));
        $this->assertSame('Legacy.jpg',Crypt::decryptString($row->filename_encrypted));
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->with($source)->andReturn(['clean'=>false,'version'=>'synthetic']);
        $this->postJson($this->base.'attachments/'.$id.'/scan')->assertOk()->assertJsonPath('data.state','rejected');
        $this->assertNull($this->db->table('clubora.chat_attachments')->where('id',$id)->value('bytes_encrypted'));
        $this->upload($client,$source)->assertCreated()->assertJsonPath('data.state','rejected');
        $this->assertNull($this->db->table('clubora.chat_attachments')->where('id',$id)->value('bytes_encrypted'));
    }
}
