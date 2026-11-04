<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Http\Request;
final class MobileKeyboxController implements \Illuminate\Routing\Controllers\HasMiddleware {
    public static function middleware(): array { return [MobileKeyboxReady::class]; }
    private function service() { return app(MobileKeyboxService::class); }
    private function args(Request $r): array { return [$r->attributes->get('mobile.actor'),$r->attributes->get('mobile.tenant')]; }
    public function index(Request $r) { return response()->json(['data'=>$this->service()->index(...$this->args($r))]); }
    public function show(Request $r) { return response()->json(['data'=>$this->service()->show(...[...$this->args($r),(int)$r->route('keybox')])]); }
    public function command(Request $r) { return response()->json(['data'=>$this->service()->command(...[...$this->args($r),(int)$r->route('keybox'),$r->route('command')])]); }
    public function bus(Request $r) { return response()->json(['data'=>$this->service()->bus(...[...$this->args($r),(int)$r->route('reservation')])]); }
    public function issue(Request $r) { return response()->json(['data'=>$this->service()->prepare($r,(int)$r->route('keybox'),null)],202); }
    public function issueBus(Request $r) { return response()->json(['data'=>$this->service()->prepare($r,null,(int)$r->route('reservation'))],202); }
}
