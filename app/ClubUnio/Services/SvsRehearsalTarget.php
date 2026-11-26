<?php
declare(strict_types=1);
namespace App\ClubUnio\Services;
use Illuminate\Database\Connection;
use LogicException;
final class SvsRehearsalTarget
{
    private static ?Connection $authorized=null;
    public static function authorize(Connection $db,string $name): void
    {
        if(PHP_SAPI!=='cli'||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$name)||$db->getDatabaseName()!==$name||$db->selectOne('select current_database() as n')->n!==$name)throw new LogicException('Isolated rehearsal DB required');
        self::$authorized=$db;
    }
    public static function allows(Connection $db): bool{return $db===self::$authorized;}
}
