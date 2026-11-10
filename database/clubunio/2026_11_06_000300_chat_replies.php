<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection='clubunio';
    public function up(): void
    {
        $db=DB::connection('clubunio');
        if($db->getDriverName()!=='pgsql'||!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) throw new LogicException('Reviewed PostgreSQL required');
        $db->unprepared(<<<'SQL'
ALTER TABLE clubora.chat_messages ADD COLUMN reply_to_message_id bigint;
ALTER TABLE clubora.chat_messages ADD CONSTRAINT chat_messages_reply_tenant_fk
 FOREIGN KEY(tenant_id,reply_to_message_id) REFERENCES clubora.chat_messages(tenant_id,id);
ALTER TABLE clubora.chat_messages ADD CONSTRAINT chat_messages_reply_order_check
 CHECK(reply_to_message_id IS NULL OR reply_to_message_id < id);
CREATE INDEX chat_messages_replies ON clubora.chat_messages(tenant_id,reply_to_message_id) WHERE reply_to_message_id IS NOT NULL;
SQL);
    }
    public function down(): void { throw new LogicException('Reviewed restore required; preserve replies'); }
};
