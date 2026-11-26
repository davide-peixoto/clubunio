<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatTranslationLanguagesTest extends TestCase
{
    protected function setUp():void{
        parent::setUp();config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);DB::purge('clubunio');$db=DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,slug text,name text,created_at text,updated_at text);
CREATE TABLE clubora.accounts(id integer primary key,username text,display_name text,password text,active integer,api_only integer,created_at text,updated_at text);
CREATE TABLE clubora.tenant_accounts(tenant_id integer,account_id integer,role text,portal_enabled integer);
CREATE TABLE clubora.chat_preferences(tenant_id integer,account_id integer,revision integer,translation_enabled integer,target_language text,updated_at text,unique(tenant_id,account_id));
CREATE TABLE clubora.mobile_ai_work(id text primary key,tenant_id integer,account_id integer,request_key text,fingerprint text,provider text,operation text,message_id integer,target_language text,revision integer,state text,reserved_microusd integer,actual_microusd integer,input_tokens integer,output_tokens integer,model text,result_encrypted text,created_at text);
SQL);
    }
    protected function tearDown():void{DB::purge('clubunio');parent::tearDown();}
    public function test_independent_catalog_consent_and_fresh_revocation_before_cache_and_after_provider():void{
        if(!defined('CLUBUNIO_CHAT_TRANSLATION_TEST_LOADER'))define('CLUBUNIO_CHAT_TRANSLATION_TEST_LOADER',true);
        require_once base_path('deploy/check-chat-translation-languages.php');
        \clubunioChatTranslationLanguageChecks($this->app,DB::connection('clubunio'),fn(bool $ok,string $case)=>$this->assertTrue($ok,$case));
    }
}
