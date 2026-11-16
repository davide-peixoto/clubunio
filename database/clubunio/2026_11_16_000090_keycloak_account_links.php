<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->selectOne('SELECT current_database() AS name')->name !== 'clubunio_dev') {
            throw new LogicException('DEV database required for the invitation pilot.');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.keycloak_account_links (
    issuer varchar(255) NOT NULL,
    subject varchar(255) NOT NULL,
    account_id bigint NOT NULL REFERENCES clubora.accounts(id),
    linked_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (issuer, subject),
    UNIQUE (issuer, account_id)
);
CREATE TABLE clubora.keycloak_account_invitations (
    token_hash char(64) PRIMARY KEY,
    tenant_id bigint NOT NULL,
    account_id bigint NOT NULL,
    issuer varchar(255) NOT NULL,
    email varchar(254) NOT NULL,
    first_name varchar(255),
    last_name varchar(255),
    expires_at timestamptz NOT NULL,
    accepted_at timestamptz,
    FOREIGN KEY (tenant_id, account_id)
        REFERENCES clubora.tenant_accounts(tenant_id, account_id) ON DELETE CASCADE
);
CREATE INDEX keycloak_invitation_expiry ON clubora.keycloak_account_invitations (expires_at);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
