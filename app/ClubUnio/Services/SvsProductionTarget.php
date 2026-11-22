<?php
declare(strict_types=1);
namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;
use LogicException;

/** A per-run root-owned permit binds the tested snapshot, backup and target. */
final class SvsProductionTarget
{
    private static ?Connection $authorized=null;
    public static function authorize(Connection $db,string $path,string $snapshot): void
    {
        $real=realpath($path);
        if(PHP_SAPI!=='cli'||!$real||!preg_match('~\A/root/clubunio-svs-production/runs/[a-f0-9]{32}/permit\.json\z~',$real)
            ||fileowner($real)!==0||(fileperms($real)&0077)!==0||$db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='clubunio_prod'
            ||$db->selectOne('select current_database() n')->n!=='clubunio_prod')throw new LogicException('Explicit root-owned PROD run permit required');
        $p=json_decode(file_get_contents($real),true,512,JSON_THROW_ON_ERROR);$dir=dirname($real);
        if(($p['target']??'')!=='clubunio_prod'||($p['tenant']??'')!=='sv-sissach'||($p['direction']??'')!=='phpmaker_prod_to_clubunio_prod'
            ||($p['import_secrets']??true)!==false||($p['snapshot_sha256']??'')!==$snapshot
            ||($p['expires_at']??0)<time()||($p['expires_at']??0)>time()+3600
            ||!hash_equals($p['backup_sha256']??'',hash_file('sha256',$dir.'/prod-before.dump'))
            ||!hash_equals($p['rehearsal_sha256']??'',hash_file('sha256',$dir.'/rehearsal.json'))
            ||!hash_equals($p['repeat_sha256']??'',hash_file('sha256',$dir.'/repeat.json')))throw new LogicException('PROD permit evidence mismatch');
        $r=json_decode(file_get_contents($dir.'/rehearsal.json'),true,512,JSON_THROW_ON_ERROR);$repeat=json_decode(file_get_contents($dir.'/repeat.json'),true,512,JSON_THROW_ON_ERROR);
        if(!$r['committed']||!$repeat['committed']||$r['conflicts']!==0||$repeat['inserted']!==0||$repeat['updated']!==0
            ||$r['mapping_version']!==$repeat['mapping_version'])throw new LogicException('Successful rehearsal and no-op repeat required');
        $head=$db->getSchemaBuilder()->hasTable('migration_control.svs_runs')?(int)$db->table('migration_control.svs_runs')->max('id'):0;
        if($head!==$p['previous_run_id'])throw new LogicException('PROD baseline advanced since backup');
        self::$authorized=$db;
    }
    public static function allows(Connection $db): bool{return self::$authorized===$db;}
}
