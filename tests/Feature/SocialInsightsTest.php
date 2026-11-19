<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{SocialInsightsClient,SocialInsightsService};
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SocialInsightsTest extends TestCase
{
    public function test_only_exact_nonproduction_test_accounts_are_allowed(): void
    {
        $this->app->instance('env','staging');
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-social-insights.enabled'=>true,
            'clubunio-social-insights.test_tenant'=>'20','clubunio-social-insights.instagram_ids'=>['12345']]);
        $tenant = new Tenant; $tenant->setRawAttributes(['id'=>20]);
        $service = new SocialInsightsService;
        self::assertTrue($service->allowed($tenant,'instagram','12345'));
        self::assertFalse($service->allowed($tenant,'facebook','12345'));
        self::assertFalse($service->allowed($tenant,'instagram','99999'));
        $tenant->setRawAttributes(['id'=>21]);
        self::assertFalse($service->allowed($tenant,'instagram','12345'));
        config(['app.url'=>'https://clubunio.com']); self::assertFalse($service->enabled());
        config(['app.url'=>'https://stage.clubunio.com']); self::assertTrue($service->enabled());
        $this->app->instance('env','production'); self::assertFalse($service->enabled());
    }

    public function test_import_preserves_zero_and_marks_unsupported_metrics_unavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn ($request)=>Http::response(match ($request['metric']) {
            'views'=>['data'=>[['name'=>'views','values'=>[['value'=>0]]]]],
            'reach'=>['data'=>[['name'=>'reach','values'=>[['value'=>30]]]]],
            default=>['error'=>['code'=>100,'message'=>'Unsupported metric']],
        },in_array($request['metric'],['views','reach'],true)?200:400));
        $values = (new SocialInsightsClient)->metrics('synthetic-token',['id'=>'12345','like_count'=>0,'comments_count'=>2],true);
        self::assertSame(0,$values['views']); self::assertSame(0,$values['likes']);
        self::assertSame(30,$values['reach']); self::assertNull($values['shares']); self::assertNull($values['saves']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r)=>$r->method()==='GET' && str_starts_with($r->url(),'https://graph.facebook.com/v27.0/12345/insights'));
    }

    public function test_facebook_metrics_use_current_metric_names_and_counts(): void
    {
        Http::fake(fn ($r)=>Http::response(['data'=>[['name'=>$r['metric'],'values'=>[['value'=>19]]]]]));
        $values = (new SocialInsightsClient)->metrics('synthetic-token',[
            'id'=>'123_456','reactions'=>['summary'=>['total_count'=>4]],'comments'=>['summary'=>['total_count'=>0]],
        ],false);
        self::assertSame(4,$values['likes']); self::assertSame(0,$values['comments']);
        self::assertNull($values['shares']); self::assertNull($values['saves']); self::assertSame(19,$values['reach']);
        Http::assertSent(fn ($r)=>$r['metric']==='post_media_view');
        Http::assertSent(fn ($r)=>$r['metric']==='post_total_media_view_unique');
    }

    public function test_pagination_never_follows_provider_urls_or_leaks_tokens_to_other_hosts(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['data'=>[],'paging'=>['next'=>'https://attacker.invalid/?access_token=secret','cursors'=>['after'=>'cursor-one']]])
            ->push(['data'=>[]]);
        self::assertSame([], (new SocialInsightsClient)->pages('synthetic-token'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r)=>!str_starts_with($r->url(),'https://graph.facebook.com/'));
        Http::assertSent(fn ($r)=>($r->data()['after'] ?? null)==='cursor-one');
    }

    public function test_expired_tokens_are_not_treated_as_missing_metrics(): void
    {
        Http::fake(['*'=>Http::response(['error'=>['code'=>190,'message'=>'synthetic-token']],400)]);
        try { (new SocialInsightsClient)->get('synthetic-token','123/insights',[],true); self::fail(); }
        catch (HttpException $error) { self::assertSame(401,$error->getStatusCode()); self::assertStringNotContainsString('synthetic-token',$error->getMessage()); }
    }

    public function test_rate_limit_does_not_become_a_zero_kpi(): void
    {
        Http::fake(['*'=>Http::response(['error'=>['code'=>4]],400)]);
        try { (new SocialInsightsClient)->get('synthetic-token','123/insights',[],true); self::fail(); }
        catch (HttpException $error) { self::assertSame(429,$error->getStatusCode()); }
    }

    public function test_only_safe_original_links_are_retained(): void
    {
        self::assertSame('https://www.instagram.com/p/test/',SocialInsightsClient::safeLink('https://www.instagram.com/p/test/'));
        foreach (['javascript:alert(1)','https://evil.invalid/post','https://facebook.com/post?access_token=secret','https://user@facebook.com/post'] as $url) {
            self::assertNull(SocialInsightsClient::safeLink($url));
        }
        self::assertSame(0,SocialInsightsClient::number(0)); self::assertNull(SocialInsightsClient::number(null));
        self::assertNull(SocialInsightsClient::number(-1));
    }

    public function test_new_routes_require_login(): void
    {
        $this->get('/social-media/insights')->assertRedirect('/login');
        $this->get('/social-connect/callback?state=invalid')->assertRedirect('/login');
        $this->post('/social-connect/facebook')->assertRedirect('/login');
    }
}
