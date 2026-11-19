<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubunio.legacy_training_plans (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), source_id bigint NOT NULL,
 source_hash text NOT NULL, source_status text NOT NULL, source_username text, source_team_name text,
 title text, state text NOT NULL CHECK(state IN ('review','excluded','imported')), error_code text,
 input_json jsonb, plan_json jsonb, suggested_owner_id bigint REFERENCES clubunio.accounts(id),
 suggested_team_season_id bigint REFERENCES clubunio.team_seasons(id),
 owner_id bigint REFERENCES clubunio.accounts(id), team_season_id bigint REFERENCES clubunio.team_seasons(id),
 plan_id bigint REFERENCES clubunio.training_plans(id), revision integer NOT NULL DEFAULT 1,
 imported_by bigint REFERENCES clubunio.accounts(id), imported_at timestamptz, decision_note varchar(1000),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,source_id)
);
ALTER TABLE clubunio.training_plans ADD COLUMN legacy_source_id bigint REFERENCES clubunio.legacy_training_plans(id);
CREATE UNIQUE INDEX training_plan_legacy_once ON clubunio.training_plans(tenant_id,legacy_source_id) WHERE legacy_source_id IS NOT NULL;
CREATE TABLE clubunio.bus_keyboxes (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), bus_id bigint NOT NULL REFERENCES clubunio.buses(id),
 name varchar(160) NOT NULL, mode text NOT NULL DEFAULT 'SIMULATION' CHECK(mode='SIMULATION'), active boolean NOT NULL DEFAULT false,
 revision integer NOT NULL DEFAULT 1, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,bus_id)
);
CREATE TABLE clubunio.bus_keybox_codes (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), reservation_id bigint NOT NULL REFERENCES clubunio.bus_reservations(id),
 keybox_id bigint NOT NULL REFERENCES clubunio.bus_keyboxes(id), reservation_revision integer NOT NULL, keybox_revision integer NOT NULL,
 scope_key text NOT NULL, state text NOT NULL CHECK(state IN ('issued','invalidated')), pin_cipher text NOT NULL,
 valid_from timestamptz NOT NULL, valid_until timestamptz NOT NULL, created_by bigint NOT NULL REFERENCES clubunio.accounts(id),
 invalidated_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), CHECK(valid_until>valid_from), UNIQUE(tenant_id,scope_key)
);
CREATE TABLE clubunio.integration_checks (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), actor_id bigint NOT NULL REFERENCES clubunio.accounts(id),
 kind text NOT NULL CHECK(kind='instagram'), state text NOT NULL CHECK(state IN ('ready','failed','missing')),
 item_count integer NOT NULL DEFAULT 0 CHECK(item_count BETWEEN 0 AND 8), http_status integer, reference uuid NOT NULL UNIQUE,
 created_at timestamptz NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
