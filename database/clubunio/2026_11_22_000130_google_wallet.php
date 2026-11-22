<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        $expected = ['https://dev.clubunio.ch' => 'clubunio_dev', 'https://stage.clubunio.com' => 'clubunio_stage', 'https://app.clubunio.com' => 'clubunio_prod'][config('app.url')] ?? null;
        if (PHP_SAPI !== 'cli' || ! $expected || $db->getDriverName() !== 'pgsql'
            || $db->getDatabaseName() !== $expected || $db->selectOne('select current_database() as name')->name !== $expected) {
            throw new LogicException('Reviewed Google-wallet target required');
        }
        DB::connection('clubunio')->unprepared($this->sql());
    }

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubora.member_google_wallet_passes (
 id uuid PRIMARY KEY, card_id uuid NOT NULL UNIQUE,
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id), member_id bigint NOT NULL,
 environment varchar(8) NOT NULL CHECK(environment IN ('dev','stage','prod')),
 object_id varchar(180) NOT NULL UNIQUE,
 projection_encrypted text, logo_encrypted text, logo_hash char(64),
 fingerprint char(64), desired_fingerprint char(64), dirty boolean NOT NULL DEFAULT true,
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts>=0), next_attempt_at timestamptz,
 checked_at timestamptz, synced_at timestamptz, last_error varchar(40),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,member_id),
 FOREIGN KEY(tenant_id,card_id) REFERENCES clubora.member_cards(tenant_id,id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubora.members(tenant_id,id)
);
CREATE INDEX member_google_wallet_scan ON clubora.member_google_wallet_passes(checked_at);
CREATE INDEX member_google_wallet_pending ON clubora.member_google_wallet_passes(next_attempt_at) WHERE dirty;
REVOKE ALL ON clubora.member_google_wallet_passes FROM PUBLIC;
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Installed Google passes require a reviewed revocation/restore plan.');
    }
};
