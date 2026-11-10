<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->statement('ALTER TABLE clubunio.training_slots ADD COLUMN area_id bigint,
            ADD CONSTRAINT training_slots_pitch_area_field_check CHECK (area_id IS NULL OR field_id IS NOT NULL),
            ADD CONSTRAINT training_slots_pitch_area_fk FOREIGN KEY (tenant_id,field_id,area_id)
                REFERENCES clubunio.pitch_areas(tenant_id,field_id,id)');
        $db->statement('CREATE INDEX training_slots_pitch_area_idx ON clubunio.training_slots(tenant_id,field_id,area_id)');
    }

    public function down(): void
    {
        throw new LogicException('Retain weekly pitch allocations; use a reviewed restore.');
    }
};
