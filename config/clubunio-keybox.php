<?php

// Operational gates are separately activatable; no credential/device secret here.
$flag=fn (string $key)=>filter_var(env($key,false),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===true;
$slugs=array_values(array_filter(array_map('trim',explode(',',(string)env('CLUBUNIO_KEYBOX_TENANT_SLUGS','')))));
foreach($slugs as $slug) if(!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$slug)) $slugs=[];
try { $approved=json_decode((string)env('CLUBUNIO_KEYBOX_APPROVED_IDS','{}'),true,16,JSON_THROW_ON_ERROR); } catch(Throwable) { $approved=[]; }
if(!is_array($approved)) $approved=[];
foreach($approved as $slug=>$ids) {
    if(!is_string($slug)||!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$slug)||!is_array($ids)||!array_is_list($ids)||count($ids)>100) { $approved=[]; break; }
    foreach($ids as $id) if(!is_int($id)||$id<1) { $approved=[]; break 2; }
}
return [
    'administration_enabled'=>$flag('CLUBUNIO_KEYBOX_ADMINISTRATION_ENABLED'),
    'issuance_enabled'=>$flag('CLUBUNIO_KEYBOX_ISSUANCE_ENABLED'),
    'provider_enabled'=>$flag('CLUBUNIO_KEYBOX_PROVIDER_ENABLED'),
    'operations_reviewed'=>$flag('CLUBUNIO_KEYBOX_OPERATIONS_REVIEWED'),
    'offline_revocation_acknowledged'=>$flag('CLUBUNIO_KEYBOX_OFFLINE_REVOCATION_ACKNOWLEDGED'),
    'tenant_slugs'=>$slugs,'approved_keybox_ids'=>$approved,
    'protocol'=>(string)env('CLUBUNIO_KEYBOX_PROTOCOL','legacy'),'dispatch_after_response'=>true,
];
