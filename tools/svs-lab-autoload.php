<?php
// Share vendor packages, never the live application's optimized App class map.
$svsRoot=dirname(__DIR__);
$svsLoader=require $svsRoot.'/vendor/autoload.php';
$svsLoader->setPsr4('App\\',$svsRoot.'/app');
foreach($svsLoader->getClassMap() as $svsClass=>$svsPath){
    if(str_starts_with($svsClass,'App\\')){
        $svsLocal=$svsRoot.'/app/'.str_replace('\\','/',substr($svsClass,4)).'.php';
        if(is_file($svsLocal))$svsLoader->addClassMap([$svsClass=>$svsLocal]);
    }
}
