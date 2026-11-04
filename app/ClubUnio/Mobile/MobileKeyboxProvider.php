<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{Crypt, DB, Http};

/** Fixed Igloo hourly protocol. No free URLs, automatic retries or lock operations. */
final class MobileKeyboxProvider
{
    public function credentials(Tenant $tenant): array
    {
        abort_unless(config('clubunio-mobile.keybox.provider_enabled',false)
            && in_array($tenant->slug,config('clubunio-mobile.keybox.tenant_slugs',[]),true),503);
        $values=[];
        foreach (['IGLOOHOME_CLIENT_ID','IGLOOHOME_CLIENT_SECRET'] as $key) {
            $cipher=DB::connection('clubunio')->table('clubora.tenant_settings')->where('tenant_id',$tenant->id)->where('area','keybox')->where('key',$key)
                ->where('enabled',true)->where('is_secret',true)->value('encrypted_value');
            try { $value=$cipher?Crypt::decryptString($cipher):''; } catch (\Throwable) { $value=''; }
            abort_unless(is_string($value)&&strlen($value)>0&&strlen($value)<=4096&&!preg_match('/[\r\n]/',$value),503);
            $values[$key]=$value;
        }
        $protocol=config('clubunio-mobile.keybox.protocol','legacy'); abort_unless(in_array($protocol,['legacy','home'],true),503);
        return ['id'=>$values['IGLOOHOME_CLIENT_ID'],'secret'=>$values['IGLOOHOME_CLIENT_SECRET'],'protocol'=>$protocol];
    }
    public function fingerprint(array $credentials): string { return hash_hmac('sha256',json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key')); }
    public function hourly(array $credentials,string $device,Time $from,Time $until,string $accessName): array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$device)&&$until->greaterThan($from)&&$until->diffInHours($from,true)<=672,422);
        $token=Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->asForm()->acceptJson()->withBasicAuth($credentials['id'],$credentials['secret'])
            ->post('https://auth.igloohome.co/oauth2/token',['grant_type'=>'client_credentials','scope'=>'igloohomeapi/algopin-hourly']);
        abort_unless($token->successful()&&strlen($token->body())<=16384&&is_string($token->json('access_token'))&&strlen($token->json('access_token'))<=8192,503);
        $base=$credentials['protocol']==='home'?'https://api.igloohome.co/home':'https://api.igloodeveloper.co/igloohome';
        $response=Http::timeout(20)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withToken($token->json('access_token'))
            ->post($base.'/devices/'.rawurlencode($device).'/algopin/hourly',['variance'=>1,'startDate'=>$from->format('Y-m-d\TH:00:00P'),
                'endDate'=>$until->format('Y-m-d\TH:00:00P'),'accessName'=>mb_substr($accessName,0,100)]);
        abort_unless($response->successful()&&strlen($response->body())<=16384,503);
        $data=$response->json();
        abort_unless(is_array($data)&&($data['success']??true)===true&&is_string($data['pin']??null)
            &&preg_match('/^[0-9]{4,16}$/D',$data['pin'])&&is_string($data['pinId']??null)&&strlen($data['pinId'])>=1&&strlen($data['pinId'])<=255,503);
        return ['pin'=>$data['pin'],'pinId'=>$data['pinId']];
    }
}
