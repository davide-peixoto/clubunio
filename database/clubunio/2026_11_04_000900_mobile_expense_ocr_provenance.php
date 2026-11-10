<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Reserved by Root; explicit reviewed individual path only. No rule-suggestion backfill. */
return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.mobile_ai_work ADD CONSTRAINT mobile_ai_work_tenant_actor_id_unique UNIQUE(tenant_id,account_id,id);
CREATE TABLE clubunio.mobile_expense_ocr_provenance (
 tenant_id bigint NOT NULL, line_id bigint NOT NULL, line_revision integer NOT NULL CHECK(line_revision>0),
 created_by bigint NOT NULL REFERENCES clubunio.accounts(id), ocr_account_id bigint NOT NULL,
 work_id uuid NOT NULL, draft_id uuid NOT NULL, draft_revision integer NOT NULL CHECK(draft_revision>0),
 source_sha256 char(64) NOT NULL CHECK(source_sha256 ~ '^[0-9a-f]{64}$'), suggested_amount numeric(19,4) NOT NULL CHECK(suggested_amount>0),
 saved_amount numeric(19,4) NOT NULL CHECK(saved_amount>0), source_replaced boolean NOT NULL DEFAULT false, accepted boolean NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(tenant_id,line_id,line_revision),
 FOREIGN KEY(tenant_id,line_id) REFERENCES clubunio.expense_lines(tenant_id,id),
 FOREIGN KEY(tenant_id,draft_id) REFERENCES clubunio.mobile_expense_receipts(tenant_id,id),
 FOREIGN KEY(tenant_id,ocr_account_id,work_id) REFERENCES clubunio.mobile_ai_work(tenant_id,account_id,id),
 CHECK(accepted=(NOT source_replaced AND suggested_amount=saved_amount))
);
REVOKE ALL ON clubunio.mobile_expense_ocr_provenance FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Retain verified OCR amount history; reviewed restore required'); }
};
