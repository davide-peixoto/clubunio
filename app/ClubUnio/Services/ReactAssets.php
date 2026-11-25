<?php
namespace App\ClubUnio\Services;

final class ReactAssets
{
    public function preloads(string $name):array
    {
        if(!$this->entry($name))return [];
        $paths=request()->attributes->get(self::class)['preloads'][$name]??[];
        if(!is_array($paths))return [];
        $key=self::class.':preloaded';$seen=request()->attributes->get($key,[]);$result=[];
        foreach($paths as $path){
            if(is_string($path)&&preg_match('~^js/react-ui/chunks/[a-zA-Z0-9_-]+\.js$~D',$path)&&!isset($seen[$path])&&is_file(public_path($path))){$seen[$path]=true;$result[]=$path;}
        }
        request()->attributes->set($key,$seen);
        return $result;
    }
    public function entry(string $name): ?string
    {
        if(!in_array($name,['navigation','dashboard','facility'],true))return null;
        $key=self::class;
        if(!request()->attributes->has($key)){
            $path=public_path('js/react-ui/manifest.json');
            $manifest=is_file($path)?json_decode(file_get_contents($path),true):null;
            request()->attributes->set($key,is_array($manifest)?$manifest:[]);
        }
        $entry=request()->attributes->get($key)[$name]??null;
        return is_string($entry)&&preg_match('~^js/react-ui/[a-zA-Z0-9_-]+\.js$~D',$entry)&&is_file(public_path($entry))?$entry:null;
    }
}
