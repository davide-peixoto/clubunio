<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!\App\ClubUnio\Services\StageMigrationTarget::allows($db) || $db->getDriverName() !== 'pgsql') {
            throw new LogicException('Reviewed ClubUnio PostgreSQL database required');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubunio.chat_preferences (
 tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
 account_id bigint NOT NULL REFERENCES clubunio.accounts(id),
 translation_enabled boolean NOT NULL DEFAULT false,
 target_language varchar(10) NOT NULL DEFAULT 'de',
 revision integer NOT NULL DEFAULT 1 CHECK(revision > 0),
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(tenant_id,account_id),
 CHECK(target_language IN ('de','en','fr','it','pt-PT'))
);
CREATE TABLE clubunio.chat_reactions (
 tenant_id bigint NOT NULL,
 message_id bigint NOT NULL,
 account_id bigint NOT NULL REFERENCES clubunio.accounts(id),
 emoji varchar(16) NOT NULL,
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(message_id,account_id),
 FOREIGN KEY(tenant_id,message_id) REFERENCES clubunio.chat_messages(tenant_id,id),
 CHECK(emoji IN ('👍','❤️','😂','😮','😢','🙏'))
);
CREATE INDEX chat_reactions_scope ON clubunio.chat_reactions(tenant_id,message_id);
REVOKE ALL ON clubunio.chat_preferences, clubunio.chat_reactions FROM PUBLIC;
SQL);
    }

    public function down(): void { throw new LogicException('Preserve user preferences and reactions'); }
};
