<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParentAccessServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('clubunio'); $db=DB::connection('clubunio'); $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,slug text,name text,created_at text,updated_at text);
CREATE TABLE clubora.accounts(id integer primary key,username text,display_name text,password text,active integer,api_only integer,created_at text,updated_at text);
CREATE TABLE clubora.tenant_accounts(id integer primary key,tenant_id integer,account_id integer,role text,portal_enabled integer);
CREATE TABLE clubora.members(id integer primary key,tenant_id integer,first_name text,last_name text,birth_date text,mother_email text,father_email text,status_id integer,exit_date text,archived_at text,created_at text,updated_at text);
CREATE TABLE clubora.member_statuses(id integer primary key,tenant_id integer,ends_membership integer);
CREATE TABLE clubora.account_member_links(id integer primary key,tenant_id integer,account_id integer,member_id integer,kind text,state text,source text,revision integer default 1,reviewed_by integer,reviewed_at text,adult_consent_at text,adult_consent_by integer,adult_consent_source text,created_at text,updated_at text);
CREATE TABLE clubora.account_parent_email_proofs(account_id integer primary key,email text,verified_at text,issuer_hash text);
CREATE TABLE clubora.object_changes(id integer primary key,tenant_id integer,actor_id integer,object_type text,object_id integer,before_value text,after_value text);
SQL);
    }
    protected function tearDown(): void { DB::purge('clubunio'); parent::tearDown(); }

    public function test_verified_parent_activation_adult_consent_revocation_and_profile_isolation(): void
    {
        if (!defined('CLUBUNIO_PARENT_ACCESS_TEST_LOADER')) define('CLUBUNIO_PARENT_ACCESS_TEST_LOADER',true);
        require_once base_path('deploy/check-parent-access.php');
        \clubunioParentAccessChecks($this->app,DB::connection('clubunio'),fn(bool $ok,string $case)=>$this->assertTrue($ok,$case));
    }
}
