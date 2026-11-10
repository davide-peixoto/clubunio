<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db=DB::connection('clubunio');
        if ($db->getDriverName()!=='pgsql'||!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) { throw new LogicException('Reviewed ClubUnio PostgreSQL required'); }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.mobile_keyboxes (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 name varchar(160) NOT NULL, provider_device_cipher text NOT NULL,
 bus_id bigint, active boolean NOT NULL DEFAULT false, revision integer NOT NULL DEFAULT 1 CHECK(revision>0),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,bus_id) REFERENCES clubora.buses(tenant_id,id)
);
CREATE TABLE clubora.mobile_keybox_grants (
 tenant_id bigint NOT NULL, keybox_id bigint NOT NULL, account_id bigint NOT NULL,
 active boolean NOT NULL DEFAULT false, revision integer NOT NULL DEFAULT 1 CHECK(revision>0),
 PRIMARY KEY(tenant_id,keybox_id,account_id),
 FOREIGN KEY(tenant_id,keybox_id) REFERENCES clubora.mobile_keyboxes(tenant_id,id),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id)
);
CREATE TABLE clubora.mobile_keybox_commands (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, keybox_id bigint NOT NULL, account_id bigint NOT NULL,
 session_id uuid NOT NULL REFERENCES clubora.mobile_sessions(id), request_key uuid NOT NULL,
 scope_key char(64) NOT NULL, snapshot_encrypted text NOT NULL, pin_encrypted text,
 reservation_id bigint, keybox_revision integer NOT NULL CHECK(keybox_revision>0), reservation_revision integer,
 valid_from timestamptz NOT NULL, valid_until timestamptz NOT NULL CHECK(valid_until>valid_from),
 state varchar(16) NOT NULL DEFAULT 'held' CHECK(state IN ('held','issuing','issued','attention','invalidated')),
 invalidated_at timestamptz, remote_revocation_state varchar(24) NOT NULL DEFAULT 'not_available'
 CHECK(remote_revocation_state IN ('not_available','required','confirmed')),
 created_at timestamptz NOT NULL DEFAULT now(), provider_started_at timestamptz,
 UNIQUE(tenant_id,account_id,request_key), UNIQUE(tenant_id,keybox_id,scope_key),
 FOREIGN KEY(tenant_id,keybox_id) REFERENCES clubora.mobile_keyboxes(tenant_id,id),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 FOREIGN KEY(tenant_id,reservation_id) REFERENCES clubora.bus_reservations(tenant_id,id),
 CHECK((state='issued')=(pin_encrypted IS NOT NULL))
);
REVOKE ALL ON clubora.mobile_keyboxes,clubora.mobile_keybox_grants,clubora.mobile_keybox_commands FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Reviewed keybox evidence restore required'); }
};
