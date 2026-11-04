<?php

// Anonymous acceptance session only: never change an existing user's preference.
$base = $argv[1] ?? 'https://dev.clubunio.ch';
if (! in_array($base,['https://dev.clubunio.ch','http://localhost:8093'],true)) { throw new LogicException('Unexpected target'); }
$cookie = tempnam(sys_get_temp_dir(),'cu-lang-');
$client = curl_init();
curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>30]);
$fetch = function (string $path, ?array $data=null) use ($client,$base) {
    curl_setopt($client,CURLOPT_URL,$base.$path);
    curl_setopt($client,CURLOPT_POST,$data !== null);
    if ($data !== null) { curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($data)); }
    $body = curl_exec($client);
    if ($body === false) { throw new RuntimeException('HTTP transport failed'); }
    return [curl_getinfo($client,CURLINFO_RESPONSE_CODE),$body];
};
try {
    [$code,$html]=$fetch('/login');
    if ($code !== 200) { throw new RuntimeException('Login failed'); }
    $document=new DOMDocument;
    @$document->loadHTML($html);
    $xpath=new DOMXPath($document);
    $token=$xpath->evaluate('string(//form[contains(@action,"/language")]/input[@name="_token"]/@value)');
    if (! $token) { throw new RuntimeException('Language form missing'); }
    foreach (['en'=>'Username','de'=>'Benutzername'] as $locale=>$text) {
        [$code]=$fetch('/language',['_token'=>$token,'locale'=>$locale]);
        if ($code !== 302) { throw new RuntimeException('Language switch failed: '.$code); }
        [$code,$html]=$fetch('/login');
        if ($code !== 200 || ! str_contains($html,$text) || ! preg_match('/<html[^>]*lang="'.$locale.'"/',$html)) {
            throw new RuntimeException('Language response incorrect: '.$locale);
        }
        echo $locale." login and switch OK\n";
    }
} finally {
    curl_close($client);
    if (is_file($cookie)) { unlink($cookie); }
}
