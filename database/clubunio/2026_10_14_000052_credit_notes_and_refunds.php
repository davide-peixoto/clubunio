<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.financial_adjustment_statuses (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 code varchar(80) NOT NULL, name varchar(160) NOT NULL, active boolean NOT NULL DEFAULT true,
 sort_order integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,code)
);
INSERT INTO clubora.financial_adjustment_statuses(tenant_id,code,name,sort_order)
SELECT t.id,v.code,v.name,v.sort_order FROM clubora.tenants t CROSS JOIN
(VALUES ('draft','Entwurf',10),('released','Freigegeben',20),('confirmed','Bestätigt',30),
('cancelled','Storniert',40)) v(code,name,sort_order);
CREATE TABLE clubora.credit_notes (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 invoice_id bigint NOT NULL REFERENCES clubora.invoices(id), member_id bigint NOT NULL REFERENCES clubora.members(id),
 status_id bigint NOT NULL REFERENCES clubora.financial_adjustment_statuses(id), revision integer NOT NULL DEFAULT 1,
 request_id uuid NOT NULL, request_hash text NOT NULL, reason varchar(1000) NOT NULL,
 amount_cents bigint NOT NULL CHECK(amount_cents>0), included_tax numeric(18,6) NOT NULL CHECK(included_tax>=0),
 source_snapshot jsonb NOT NULL, created_by bigint NOT NULL REFERENCES clubora.accounts(id),
 released_by bigint REFERENCES clubora.accounts(id), released_at timestamptz,
 confirmed_by bigint REFERENCES clubora.accounts(id), confirmed_at timestamptz,
 external_reference varchar(120), connection_hash text, external_snapshot jsonb,
 cancellation_reason varchar(1000), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,request_id), UNIQUE(tenant_id,connection_hash,external_reference)
);
CREATE INDEX credit_notes_invoice ON clubora.credit_notes(tenant_id,invoice_id,status_id);
CREATE TABLE clubora.credit_note_lines (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 credit_note_id bigint NOT NULL REFERENCES clubora.credit_notes(id), invoice_line_id bigint NOT NULL REFERENCES clubora.invoice_lines(id),
 description text NOT NULL, amount_cents bigint NOT NULL CHECK(amount_cents>0),
 included_tax numeric(18,6) NOT NULL CHECK(included_tax>=0), tax_snapshot jsonb,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(credit_note_id,invoice_line_id)
);
CREATE TABLE clubora.refunds (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 invoice_id bigint NOT NULL REFERENCES clubora.invoices(id), credit_note_id bigint REFERENCES clubora.credit_notes(id),
 status_id bigint NOT NULL REFERENCES clubora.financial_adjustment_statuses(id), revision integer NOT NULL DEFAULT 1,
 request_id uuid NOT NULL, request_hash text NOT NULL, reason varchar(1000) NOT NULL,
 amount_cents bigint NOT NULL CHECK(amount_cents>0), entitlement_snapshot jsonb NOT NULL,
 created_by bigint NOT NULL REFERENCES clubora.accounts(id), confirmed_by bigint REFERENCES clubora.accounts(id),
 confirmed_at timestamptz, paid_on date, proof_reference varchar(120), cancellation_reason varchar(1000),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,request_id), UNIQUE(tenant_id,proof_reference)
);
CREATE INDEX refunds_invoice ON clubora.refunds(tenant_id,invoice_id,status_id);
ALTER TABLE clubora.bexio_balances ADD COLUMN overpaid_cents bigint NOT NULL DEFAULT 0 CHECK(overpaid_cents>=0);
ALTER TABLE clubora.bexio_balances DROP CONSTRAINT bexio_balances_check;
ALTER TABLE clubora.bexio_balances ADD CONSTRAINT bexio_balance_equation
 CHECK(total_cents+overpaid_cents=paid_cents+credit_cents+remaining_cents);
ALTER TABLE clubora.bexio_balances ADD CONSTRAINT bexio_balance_exclusive
 CHECK((remaining_cents=0 OR overpaid_cents=0) AND (object_type='invoice' OR overpaid_cents=0));
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
