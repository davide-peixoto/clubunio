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
CREATE TABLE clubora.bexio_catalog_entries (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 kind text NOT NULL CHECK(kind IN ('article','tax')), remote_id bigint NOT NULL CHECK(remote_id > 0),
 connection_hash text NOT NULL, code text NOT NULL, provider_name text NOT NULL,
 name varchar(160) NOT NULL, active boolean NOT NULL DEFAULT true,
 provider_active boolean NOT NULL, rate_basis_points integer CHECK(rate_basis_points BETWEEN 0 AND 10000),
 tax_type text, valid_from date, valid_until date, provider_data jsonb NOT NULL,
 revision integer NOT NULL DEFAULT 1, observed_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,kind,connection_hash,remote_id),
 CHECK(valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)
);
ALTER TABLE clubora.invoice_lines ADD COLUMN bexio_catalog_article_id bigint REFERENCES clubora.bexio_catalog_entries(id);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
