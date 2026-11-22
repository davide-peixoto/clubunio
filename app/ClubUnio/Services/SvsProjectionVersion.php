<?php
declare(strict_types=1);
namespace App\ClubUnio\Services;

final class SvsProjectionVersion
{
    public static function digest(string $root): string
    {
        $paths=array_merge(glob($root.'/database/clubunio/*.php'),glob($root.'/app/ClubUnio/Services/*Importer.php'),[
            $root.'/routes/clubunio-console.php',$root.'/deploy/pmp-relations.json',
            $root.'/app/ClubUnio/Services/MessageTemplateService.php',$root.'/app/ClubUnio/Services/StatusStyleService.php',
            $root.'/tools/build-svs-import-candidate.php',
            $root.'/tools/svs-lab-autoload.php',
        ]);
        sort($paths,SORT_STRING);$hash=hash_init('sha256');
        foreach($paths as $path){if(basename($path)==='SvsDeltaImporter.php')continue;hash_update($hash,substr($path,strlen($root))."\n".hash_file('sha256',$path)."\n");}
        return hash_final($hash);
    }
}
