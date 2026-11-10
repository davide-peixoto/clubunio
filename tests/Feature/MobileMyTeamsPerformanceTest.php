<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail};
use Tests\TestCase;

/** Actual scoped endpoints, bounded query-count assertions; rolled-back synthetic rows only. */
class MobileMyTeamsPerformanceTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private array $actors=[], $tokens=[], $members=[];
    private int $team, $otherTeam, $child, $country;
    private string $base;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit rolled-back PostgreSQL required');
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,
            'clubunio-mobile.native_documents_enabled'=>false,'clubunio-mobile.native_member_id_card_enabled'=>false,
            'cache.default'=>'array','hashing.bcrypt.rounds'=>4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->db=DB::connection('clubunio');$this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction();$this->db->statement("SET LOCAL application_name TO 'clubunio-my-teams-performance-rollback'");
        $this->tenant=Tenant::create(['slug'=>'perf-teams-'.bin2hex(random_bytes(6)),'name'=>'Synthetic teams']);
        foreach (['admin'=>'admin','trainer'=>'trainer','parent'=>'member','outside'=>'member'] as $key=>$role) {
            $actor=new Account;$actor->forceFill(['username'=>'perf-teams-'.bin2hex(random_bytes(8)),
                'display_name'=>'Synthetic '.$key,'password'=>bcrypt('fixture'),'active'=>true,'api_only'=>false])->save();
            $this->actors[$key]=$actor;
            $this->insert('tenant_accounts',['account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);
            $member=$this->insert('members',['first_name'=>'Synthetic','last_name'=>$key,'birth_date'=>'1980-01-01']);
            $this->members[$key]=$member;
            $this->insert('account_member_links',['account_id'=>$actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic-test']);
            $this->tokens[$key]=$this->postJson('/api/mobile/v1/session',['username'=>$actor->username,'password'=>'fixture','device_name'=>'Synthetic performance'])->assertOk()->json('data.token');
        }
        $this->country=$this->insert('countries',['code'=>'CH','iso3'=>'CHE','name'=>'Synthetic country']);
        $this->child=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Child','birth_date'=>now()->subYears(12)->toDateString(),
            'email'=>'child@example.invalid','mother_name'=>'PRIVATE-MOTHER','country_id'=>$this->country,'nationality_id'=>$this->country,
            'ahv_encrypted'=>encrypt('PRIVATE-AHV')]);
        $this->insert('account_member_links',['account_id'=>$this->actors['parent']->id,'member_id'=>$this->child,'kind'=>'parent','state'=>'approved','source'=>'synthetic-test']);
        $season=$this->insert('seasons',['name'=>'Synthetic current','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic season','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$season)]);
        $this->team=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Synthetic team']),'season_id'=>$season,'label'=>'Synthetic team']);
        $this->otherTeam=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Other team']),'season_id'=>$season,'label'=>'Other team']);
        $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$this->child]);
        $this->insert('team_staff',['team_season_id'=>$this->team,'member_id'=>$this->members['trainer'],'staff_function_id'=>$this->insert('staff_functions',['code'=>'TRAINER','name'=>'Trainer'])]);
        $this->insert('training_slots',['team_season_id'=>$this->team,'starts_at'=>'18:00','ends_at'=>'19:30','synthetic_only'=>true]);
        $this->base='/api/mobile/v1/clubs/'.$this->tenant->id;
        $this->withToken($this->tokens['trainer']);
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) {$this->db->disableQueryLog();$this->db->flushQueryLog();$this->db->rollBack();}
        parent::tearDown();
    }
    private function insert(string $table,array $values): int
    {
        return $this->db->table('clubunio.'.$table)->insertGetId($values+['tenant_id'=>$this->tenant->id]);
    }
    private function measured(string $url): array
    {
        $this->db->flushQueryLog();$this->db->enableQueryLog();
        try {$response=$this->getJson($url)->assertOk();$queries=$this->db->getQueryLog();}
        finally {$this->db->disableQueryLog();}
        return [$response,$queries];
    }
    private function teamResolutions(array $queries): int
    {
        return count(array_filter($queries,fn($query)=>str_contains($query['query'],'from "clubunio"."team_seasons" as "ts"')
            &&str_contains($query['query'],'"ts"."id" = ?')));
    }
    public function test_roster_query_count_does_not_grow_per_member_and_training_is_authorized_once(): void
    {
        [$small,$before]=$this->measured($this->base.'/my-teams/'.$this->team);
        $small->assertJsonCount(2,'data.Members')->assertJsonCount(1,'data.Trainings');
        $this->assertSame(1,$this->teamResolutions($before));
        for($i=0;$i<38;$i++) {
            $member=$this->insert('members',['first_name'=>'Roster','last_name'=>(string)$i,'email'=>'roster@example.invalid']);
            $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$member]);
        }
        [$large,$after]=$this->measured($this->base.'/my-teams/'.$this->team);
        $large->assertJsonCount(40,'data.Members')->assertJsonCount(39,'data.Players');
        $this->assertSame(1,$this->teamResolutions($after));
        $this->assertSame(count($before),count($after),'Roster size must not add per-member SQL');
        $this->assertStringContainsString('PRIVATE-MOTHER',$large->getContent()); // Explicit user-authorized responsible-trainer member details.
        $this->assertStringNotContainsString('PRIVATE-AHV',$large->getContent());
        fwrite(STDOUT,"MyTeams roster2/40 SQL ".count($before).'/'.count($after)."; fresh team resolution1/1\n");
    }
    public function test_summary_window_total_and_empty_page_keep_actual_scope(): void
    {
        [$response,$queries]=$this->measured($this->base.'/my-teams?recperpage=1');
        $response->assertJsonPath('data.totalRecordCount',1)->assertJsonCount(1,'data.Teams');
        $projection=array_values(array_filter($queries,fn($query)=>str_contains($query['query'],'from "clubunio"."team_seasons" as "ts"')));
        $this->assertCount(1,$projection);$this->assertStringContainsString('COUNT(*) OVER ()',$projection[0]['query']);
        $this->getJson($this->base.'/my-teams?start=99')->assertOk()->assertJsonPath('data.totalRecordCount',1)->assertJsonCount(0,'data.Teams');
        $this->withToken($this->tokens['admin'])->getJson($this->base.'/my-teams?recperpage=1')->assertOk()->assertJsonPath('data.totalRecordCount',2);
        $this->withToken($this->tokens['outside'])->getJson($this->base.'/my-teams')->assertOk()->assertJsonPath('data.totalRecordCount',0);
        $this->getJson($this->base.'/my-teams/'.$this->team.'/trainings')->assertNotFound();
    }
    public function test_dossier_country_lookup_is_batched_and_parent_trainer_revocation_rights_remain_fresh(): void
    {
        $url=$this->base.'/members/'.$this->child;
        $this->withToken($this->tokens['parent']);
        [$response,$queries]=$this->measured($url);
        $response->assertJsonPath('data.Member.Land','CH')->assertJsonPath('data.Member.Nationalitaet','CHE')
            ->assertJsonPath('data.Member.Mutter','PRIVATE-MOTHER');
        $countryQueries=array_filter($queries,fn($query)=>str_contains($query['query'],'from "clubunio"."countries"'));
        $this->assertCount(1,$countryQueries);
        $this->withToken($this->tokens['trainer'])->getJson($url)->assertOk()->assertJsonPath('data.Member.CanEdit',false)->assertJsonMissingPath('data.Member.Mutter');
        $this->getJson($this->base.'/my-teams/'.$this->otherTeam)->assertNotFound();
        $this->withToken($this->tokens['parent'])->getJson($this->base.'/my-teams/'.$this->team.'/trainings')->assertOk()->assertJsonCount(1,'data');
        $this->db->table('clubunio.account_member_links')->where('tenant_id',$this->tenant->id)->where('account_id',$this->actors['parent']->id)->where('kind','parent')->update(['state'=>'revoked']);
        $this->getJson($url)->assertNotFound();
        $this->getJson($this->base.'/my-teams/'.$this->team.'/trainings')->assertNotFound();
        Http::assertNothingSent();Mail::assertNothingSent();
    }
}
