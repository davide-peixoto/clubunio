<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\{ActiveTenant, SocialInsightsClient, SocialInsightsService};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class SocialInsightsController
{
    public function start(Request $request, string $platform, SocialInsightsService $service)
    {
        $actor = $request->user('clubunio'); $tenant = Filament::getTenant();
        $service->authorize($actor,$tenant,'update');
        abort_unless($service->enabled() && in_array($platform,['facebook','instagram'],true)
            && config('clubunio-social-insights.app_id') && config('clubunio-social-insights.app_secret'),409,trans('social_insights.setup'));
        $context = $request->validate(['context'=>'required|array:tenant,revision,host','context.tenant'=>'required|integer|min:1',
            'context.revision'=>'required|uuid','context.host'=>'sometimes|required|string|max:253'])['context'];
        $context['tenant'] = (int) $context['tenant'];
        app(ActiveTenant::class)->assertSnapshot($request,$context);
        abort_unless($request->getSchemeAndHttpHost() === rtrim(config('app.url'),'/'),403);
        $state = Str::random(64);
        $request->session()->put('social_insights.oauth',[
            'state'=>hash('sha256',$state),'platform'=>$platform,'context'=>$context,'actor'=>$actor->id,
            'host'=>$request->getSchemeAndHttpHost(),'until'=>time()+600,
        ]);
        $scope = ['pages_show_list','pages_read_engagement','read_insights'];
        if ($platform === 'instagram') $scope = [...$scope,'instagram_basic','instagram_manage_insights'];
        return redirect()->away('https://www.facebook.com/'.config('clubunio-social-insights.version').'/dialog/oauth?'.http_build_query([
            'client_id'=>config('clubunio-social-insights.app_id'),'redirect_uri'=>route('clubunio.social-insights.callback'),
            'response_type'=>'code','state'=>$state,'scope'=>implode(',',$scope),
        ]));
    }

    public function callback(Request $request, SocialInsightsService $service, SocialInsightsClient $client)
    {
        $state = $request->session()->pull('social_insights.oauth');
        $actor = $request->user('clubunio'); $tenant = Filament::getTenant();
        $service->authorize($actor,$tenant,'update');
        abort_unless(is_array($state) && $service->enabled() && ($state['until'] ?? 0) > time()
            && ($state['actor'] ?? null) === $actor->id && ($state['host'] ?? null) === $request->getSchemeAndHttpHost()
            && hash_equals($state['state'],hash('sha256',(string) $request->query('state',''))),419);
        app(ActiveTenant::class)->assertSnapshot($request,$state['context']);
        abort_unless(is_string($request->query('code')) && strlen($request->query('code')) < 4096 && ! $request->has('error'),422,trans('social_insights.failed'));
        $short = $client->exchange(['code'=>$request->query('code'),'redirect_uri'=>route('clubunio.social-insights.callback')]);
        $long = $client->exchange(['grant_type'=>'fb_exchange_token','fb_exchange_token'=>$short['access_token']]);
        $seconds = min(5184000,max(0,(int) ($long['expires_in'] ?? 0)));
        abort_unless($seconds > 0,502);
        $pages = array_values(array_filter($client->pages($long['access_token']),fn ($page)=>is_array($page)
            && $service->allowed($tenant,$state['platform'],(string) ($state['platform'] === 'instagram' ? data_get($page,'instagram_business_account.id') : ($page['id'] ?? '')))));
        abort_unless(count($pages)>0,403,trans('social_insights.no_test_account'));
        // Candidate Page tokens never appear in HTML or Livewire state.
        $request->session()->put('social_insights.selection',Crypt::encryptString(json_encode([
            'pages'=>$pages,'platform'=>$state['platform'],'context'=>$state['context'],'actor'=>$actor->id,
            'until'=>time()+600,'expires'=>now()->addSeconds($seconds)->toIso8601String(),
        ],JSON_THROW_ON_ERROR)));
        return redirect('/social-media/insights')->withHeaders(['Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer']);
    }
}
