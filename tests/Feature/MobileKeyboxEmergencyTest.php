<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileKeyboxEmergencyTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp(); config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('clubunio'); $db=DB::connection('clubunio'); $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,slug text,name text,created_at text,updated_at text);
CREATE TABLE clubora.accounts(id integer primary key,username text,display_name text,password text,active integer,api_only integer,revision integer default 1,created_at text,updated_at text);
CREATE TABLE clubora.tenant_accounts(tenant_id integer,account_id integer,role text,portal_enabled integer);
CREATE TABLE clubora.mobile_sessions(id text primary key,account_id integer,token_hash text,device_name text,expires_at text,created_at text,revoked_at text,security_stamp text);
CREATE TABLE clubora.tenant_settings(tenant_id integer,area text,key text,label text,value_type text,encrypted_value text,enabled integer,is_secret integer);
CREATE TABLE clubora.mobile_keyboxes(id integer primary key,tenant_id integer,name text,provider_device_cipher text,emergency_code_cipher text,bus_id integer,active integer,revision integer default 1);
CREATE TABLE clubora.mobile_keybox_grants(tenant_id integer,keybox_id integer,account_id integer,active integer,revision integer default 1);
CREATE TABLE clubora.mobile_keybox_commands(id text primary key,tenant_id integer,keybox_id integer,account_id integer,session_id text,request_key text,scope_key text,snapshot_encrypted text,pin_encrypted text,reservation_id integer,keybox_revision integer,reservation_revision integer,valid_from text,valid_until text,state text default 'held',invalidated_at text,remote_revocation_state text default 'not_available',created_at text default CURRENT_TIMESTAMP,provider_started_at text,failure_kind text,failure_scope text,failed_at text,emergency_exposed_at text,unique(tenant_id,account_id,request_key));
CREATE UNIQUE INDEX clubora.mobile_keybox_current_scope ON mobile_keybox_commands(tenant_id,keybox_id,scope_key) WHERE state<>'failed';
CREATE TABLE clubora.object_changes(id integer primary key,tenant_id integer,actor_id integer,object_type text,object_id integer,after_value text);
SQL);
    }
    protected function tearDown(): void { DB::purge('clubunio'); parent::tearDown(); }
    public function test_confirmed_failures_idempotency_bounded_disclosure_revocation_and_uncertainty(): void {
        if(!defined('CLUBUNIO_KEYBOX_EMERGENCY_TEST_LOADER')) define('CLUBUNIO_KEYBOX_EMERGENCY_TEST_LOADER',true);
        require_once base_path('deploy/check-keybox-emergency.php');
        \clubunioKeyboxEmergencyChecks($this->app,DB::connection('clubunio'),fn(bool $ok,string $case)=>$this->assertTrue($ok,$case));
    }
}
