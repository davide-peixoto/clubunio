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
ALTER TABLE clubora.members
 ADD COLUMN billing_bexio_contact_id integer CHECK (billing_bexio_contact_id > 0),
 ADD COLUMN billing_bexio_identity_hash text,
 ADD COLUMN billing_bexio_revision integer NOT NULL DEFAULT 0;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
