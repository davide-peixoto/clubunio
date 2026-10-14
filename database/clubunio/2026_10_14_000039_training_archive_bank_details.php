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
        $db->statement('ALTER TABLE clubora.training_plans ADD COLUMN archived_at timestamptz');
        $db->statement('ALTER TABLE clubora.banks ADD COLUMN street text, ADD COLUMN postal_address text, ADD COLUMN postal_code text, ADD COLUMN postal_account text, ADD COLUMN country_code text, ADD COLUMN clearing_number text');
        $db->statement(<<<'SQL'
UPDATE clubora.banks b SET street=i."Domizil", postal_address=i."Postadresse", postal_code=i."PLZ",
 postal_account=i."Postkonto", country_code=i."Landcode", clearing_number=i."BCNr"
FROM legacy_prod."Institut" i, clubora.tenants t
WHERE t.slug='sv-sissach' AND b.tenant_id=t.id AND b.legacy_id=i."InstitutID"
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
