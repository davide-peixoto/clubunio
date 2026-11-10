<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Reviewed individual path only; no existing personal sessions are rewritten. */
return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        $db->unprepared(<<<'SQL'
ALTER TABLE clubora.mobile_sessions ADD CONSTRAINT mobile_sessions_id_account_unique UNIQUE(id,account_id);
CREATE TABLE clubora.mobile_push_devices (
 id uuid PRIMARY KEY, proof_hash char(64) NOT NULL,
 app_id varchar(180) NOT NULL, platform varchar(10) NOT NULL CHECK(platform IN ('android','ios')),
 origin varchar(255) NOT NULL, fixed_tenant_id bigint REFERENCES clubora.tenants(id),
 token_cipher text NOT NULL, token_hash char(64) NOT NULL UNIQUE,
 revision integer NOT NULL DEFAULT 1 CHECK(revision>0), revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE clubora.mobile_push_bindings (
 id uuid PRIMARY KEY, device_id uuid NOT NULL REFERENCES clubora.mobile_push_devices(id),
 account_id bigint NOT NULL REFERENCES clubora.accounts(id), session_id uuid NOT NULL,
 revision integer NOT NULL DEFAULT 1 CHECK(revision>0), revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(session_id,account_id) REFERENCES clubora.mobile_sessions(id,account_id),
 UNIQUE(device_id,account_id), UNIQUE(id,account_id,device_id)
);
CREATE TABLE clubora.mobile_push_outbox (
 id uuid PRIMARY KEY, binding_id uuid NOT NULL, account_id bigint NOT NULL, device_id uuid NOT NULL,
 tenant_id bigint NOT NULL, kind varchar(20) NOT NULL CHECK(kind IN ('chat','announcement','invoice','planning','expense','member','member_change','registration','bus','document_receipt','document_file','document_archive','penalty')),
 object_id bigint NOT NULL CHECK(object_id>0), event_key varchar(160) NOT NULL,
 device_revision integer NOT NULL CHECK(device_revision>0), binding_revision integer NOT NULL CHECK(binding_revision>0),
 state varchar(20) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','sending','accepted','discarded','attention')),
 lease_until timestamptz, provider_reference varchar(255), outcome_code varchar(40),
 created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
 FOREIGN KEY(binding_id,account_id,device_id) REFERENCES clubora.mobile_push_bindings(id,account_id,device_id),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 UNIQUE(binding_id,tenant_id,event_key)
);
CREATE INDEX mobile_push_pending ON clubora.mobile_push_outbox(state,created_at);
CREATE TABLE clubora.mobile_push_commands (
 tenant_id bigint NOT NULL, account_id bigint NOT NULL, command_key uuid NOT NULL,
 operation varchar(30) NOT NULL CHECK(operation='publish_squad'), fingerprint char(64) NOT NULL,
 event_id bigint NOT NULL, event_revision char(64) NOT NULL, recipient_fingerprint char(64) NOT NULL,
 queued_count integer NOT NULL CHECK(queued_count>=0), created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(tenant_id,account_id,command_key),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 FOREIGN KEY(tenant_id,event_id) REFERENCES clubora.team_events(tenant_id,id)
);
REVOKE ALL ON clubora.mobile_push_devices, clubora.mobile_push_bindings, clubora.mobile_push_outbox, clubora.mobile_push_commands FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Reviewed restore required; preserve sessions and delivery history'); }
};
