<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection='clubunio';
    public function up():void {
        $db=DB::connection('clubunio');
        $expected=['https://dev.clubunio.ch'=>'clubunio_dev','https://stage.clubunio.com'=>'clubunio_stage','https://app.clubunio.com'=>'clubunio_prod'][config('app.url')]??null;
        if(PHP_SAPI!=='cli'||!$expected||$db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$expected
            ||$db->selectOne('select current_database() as name')->name!==$expected)throw new LogicException('Reviewed support help target required');
        $db->unprepared($this->sql());
    }
    public function sql():string {return <<<'SQL'
ALTER TABLE clubora.support_triage DROP CONSTRAINT support_triage_topic_check;
ALTER TABLE clubora.support_triage ADD CONSTRAINT support_triage_topic_check CHECK(topic IN ('login','expense','profile','tenants','chat','translation','planning','teams','members','membership','wallet','events','reservations','documents','keyboxes','notifications','push','diagnostics','manual_review'));
SQL;}
    public function down():void {throw new LogicException('Preserve classified support history; reviewed rollback required');}
};
