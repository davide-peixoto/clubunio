<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection='clubunio';
    public function up(): void {
        $db=DB::connection('clubunio');
        $expected=['https://dev.clubunio.ch'=>'clubunio_dev','https://stage.clubunio.com'=>'clubunio_stage','https://app.clubunio.com'=>'clubunio_prod'][config('app.url')]??null;
        if(PHP_SAPI!=='cli'||!$expected||$db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$expected
            ||$db->selectOne('select current_database() as name')->name!==$expected) throw new LogicException('Reviewed keybox emergency target required');
        $db->unprepared($this->sql());
    }
    public function sql(): string { return <<<'SQL'
ALTER TABLE clubora.mobile_keyboxes ADD COLUMN emergency_code_cipher text;
ALTER TABLE clubora.mobile_keybox_commands ADD COLUMN failure_kind varchar(24), ADD COLUMN failure_scope char(64), ADD COLUMN failed_at timestamptz, ADD COLUMN emergency_exposed_at timestamptz;
ALTER TABLE clubora.mobile_keybox_commands DROP CONSTRAINT mobile_keybox_commands_state_check;
ALTER TABLE clubora.mobile_keybox_commands ADD CONSTRAINT mobile_keybox_commands_state_check CHECK(state IN ('held','issuing','issued','attention','invalidated','failed'));
ALTER TABLE clubora.mobile_keybox_commands ADD CONSTRAINT mobile_keybox_failure_metadata CHECK((state='failed' AND failure_kind IS NOT NULL AND failure_kind IN ('oauth_unavailable','oauth_rejected') AND failure_scope IS NOT NULL AND failed_at IS NOT NULL) OR (state<>'failed'));
ALTER TABLE clubora.mobile_keybox_commands DROP CONSTRAINT mobile_keybox_commands_tenant_id_keybox_id_scope_key_key;
CREATE UNIQUE INDEX mobile_keybox_current_scope ON clubora.mobile_keybox_commands(tenant_id,keybox_id,scope_key) WHERE state<>'failed';
CREATE INDEX mobile_keybox_provider_failures ON clubora.mobile_keybox_commands(tenant_id,keybox_id,account_id,failure_scope) WHERE state='failed';
SQL; }
    public function down(): void { throw new LogicException('Reviewed keybox emergency evidence restore required'); }
};
