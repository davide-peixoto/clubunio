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
CREATE TABLE clubora.invoice_categories (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 code varchar(80) NOT NULL, name varchar(160) NOT NULL, active boolean NOT NULL DEFAULT true,
 sort_order integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,code)
);
INSERT INTO clubora.invoice_categories(tenant_id,code,name,sort_order)
SELECT t.id,v.code,v.name,v.sort_order FROM clubora.tenants t CROSS JOIN
(VALUES ('contribution','Mitgliederbeitrag',10),('fine','Busse',20),('kit','Dressbezug',30),
('merchandise','Merchandising',40),('other','Sonstige Leistungen',50)) v(code,name,sort_order);
ALTER TABLE clubora.invoices ADD COLUMN invoice_category_id bigint REFERENCES clubora.invoice_categories(id),
 ADD COLUMN tax_mode text NOT NULL DEFAULT 'exempt' CHECK(tax_mode IN ('exempt','inclusive')),
 ADD COLUMN rounding_cents integer NOT NULL DEFAULT 0 CHECK(rounding_cents BETWEEN -2 AND 2);
ALTER TABLE clubora.invoice_lines ADD COLUMN bexio_catalog_tax_id bigint REFERENCES clubora.bexio_catalog_entries(id),
 ADD COLUMN tax_snapshot jsonb;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
