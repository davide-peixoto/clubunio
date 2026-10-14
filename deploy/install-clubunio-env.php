<?php
require dirname(__DIR__).'/vendor/autoload.php';
$shared='/var/www/svs-laravel/shared';
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$env=\Dotenv\Dotenv::parse(file_get_contents($shared.'/.env'));
$env['APP_PREVIOUS_KEYS']=implode(',',array_filter([$env['APP_KEY'] ?? '',$env['APP_PREVIOUS_KEYS'] ?? '']));
$env['APP_KEY']=$data['key'];
$env['APP_NAME']='ClubUnio';
$env['APP_DEBUG']='false';
$env['APP_ENV']='staging';
$env['SESSION_COOKIE']='clubora_staging_session';
foreach (['.env.clubora'=>$env,'.env.postgresql.local'=>$data['pg']] as $name=>$values) {
    $target=$shared.'/'.$name;
    if (file_exists($target)) throw new RuntimeException('Refusing to overwrite existing private configuration');
    $lines=[];
    foreach ($values as $key=>$value) $lines[]=$key.'="'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],(string)$value).'"';
    file_put_contents($target,implode("\n",$lines)."\n");
    chmod($target,0640);
}
echo "Separate ClubUnio configuration installed.\n";
