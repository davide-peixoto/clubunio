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
            throw new LogicException('DEV database required.');
        }
        $db->statement('CREATE TABLE clubora.platform_keycloak_invitations (
            account_id bigint PRIMARY KEY REFERENCES clubora.accounts(id),
            issuer varchar(255) NOT NULL, subject varchar(255) NOT NULL,
            email varchar(254) NOT NULL, invited_at timestamptz, activated_at timestamptz,
            UNIQUE (issuer, subject)
        )');
    }
};
