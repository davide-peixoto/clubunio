<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Reviewed ClubUnio PostgreSQL required'); }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.mobile_expense_receipts (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 account_id bigint NOT NULL REFERENCES clubora.accounts(id), client_id uuid NOT NULL,
 filename_encrypted text NOT NULL, bytes_encrypted text,
 sha256 char(64) NOT NULL, byte_count integer NOT NULL CHECK(byte_count BETWEEN 1 AND 5242880),
 mime_type varchar(80) NOT NULL CHECK(mime_type IN ('application/pdf','image/jpeg','image/png')),
 state varchar(20) NOT NULL CHECK(state IN ('clean','bound','discarded','expired')),
 scanner_version varchar(160) NOT NULL, scanned_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz NOT NULL,
 bound_line_id bigint, bound_upload_id bigint, binding_fingerprint char(64), revision integer NOT NULL DEFAULT 1 CHECK(revision > 0),
 UNIQUE(tenant_id,account_id,client_id), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,bound_line_id) REFERENCES clubora.expense_lines(tenant_id,id),
 FOREIGN KEY(tenant_id,bound_upload_id) REFERENCES clubora.document_uploads(tenant_id,id),
 CHECK(expires_at > created_at),
 CHECK((state='clean' AND bytes_encrypted IS NOT NULL AND bound_line_id IS NULL AND bound_upload_id IS NULL AND binding_fingerprint IS NULL)
    OR (state='bound' AND bytes_encrypted IS NULL AND bound_line_id IS NOT NULL AND bound_upload_id IS NOT NULL AND binding_fingerprint IS NOT NULL)
    OR (state IN ('discarded','expired') AND bytes_encrypted IS NULL AND bound_line_id IS NULL AND bound_upload_id IS NULL AND binding_fingerprint IS NULL))
);
CREATE INDEX mobile_expense_receipts_expiry ON clubora.mobile_expense_receipts(expires_at) WHERE state='clean';
REVOKE ALL ON clubora.mobile_expense_receipts FROM PUBLIC;
CREATE TABLE clubora.mobile_expense_commands (
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id), account_id bigint NOT NULL REFERENCES clubora.accounts(id),
 command_key uuid NOT NULL, operation varchar(30) NOT NULL CHECK(operation IN ('create_receipt','create_line')),
 fingerprint char(64) NOT NULL, expense_id bigint NOT NULL, line_id bigint, upload_id bigint,
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(tenant_id,account_id,command_key),
 FOREIGN KEY(tenant_id,expense_id) REFERENCES clubora.expenses(tenant_id,id),
 FOREIGN KEY(tenant_id,line_id) REFERENCES clubora.expense_lines(tenant_id,id),
 FOREIGN KEY(tenant_id,upload_id) REFERENCES clubora.document_uploads(tenant_id,id),
 CHECK((operation='create_receipt' AND line_id IS NULL AND upload_id IS NULL) OR (operation='create_line' AND line_id IS NOT NULL))
);
REVOKE ALL ON clubora.mobile_expense_commands FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Retain expense upload binding history; reviewed restore required'); }
};
