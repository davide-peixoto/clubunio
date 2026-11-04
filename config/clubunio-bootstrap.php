<?php

$flag=fn (string $key)=>filter_var(env($key,false),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===true;
$origins=array_values(array_filter(array_map('trim',explode(',',(string)env('CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS','')))));
foreach($origins as $origin) {
    $parts=parse_url($origin);
    if(!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
        ||isset($parts['path'])||isset($parts['query'])||isset($parts['fragment'])) { $origins=[];break; }
}
return ['enabled'=>$flag('CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED'),'origins'=>$origins,
    'maintenance_mode'=>$flag('CLUBUNIO_MOBILE_MAINTENANCE_MODE'),
    'maintenance_message'=>(string)env('CLUBUNIO_MOBILE_MAINTENANCE_MESSAGE','Die App wird gewartet. Bitte versuche es später erneut.')];
