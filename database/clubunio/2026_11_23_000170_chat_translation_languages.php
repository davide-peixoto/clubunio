<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection='clubunio';
    public function up():void {
        $db=DB::connection('clubunio');
        $expected=['https://dev.clubunio.ch'=>'clubunio_dev','https://stage.clubunio.com'=>'clubunio_stage','https://app.clubunio.com'=>'clubunio_prod'][config('app.url')]??null;
        if(PHP_SAPI!=='cli'||!$expected||$db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$expected
            ||$db->selectOne('select current_database() as name')->name!==$expected)throw new LogicException('Reviewed chat translation target required');
        $db->unprepared($this->sql());
    }
    public function sql():string { return <<<'SQL'
ALTER TABLE clubora.chat_preferences DROP CONSTRAINT chat_preferences_target_language_check;
ALTER TABLE clubora.chat_preferences ALTER COLUMN target_language TYPE varchar(20);
ALTER TABLE clubora.chat_preferences ADD CONSTRAINT chat_preferences_target_language_check CHECK(target_language ~ '^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$');
SQL; }
    public function down():void {throw new LogicException('Preserve saved chat translation targets; reviewed rollback required');}
};
