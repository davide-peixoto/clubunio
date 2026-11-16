<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobilePublicIdentityController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobilePublicIdentityReady::class]; }
    public function clubs(Request $request) { return response()->json(['data' => ['clubs' => app(MobilePublicIdentityPolicy::class)->clubs($request->attributes->get('mobile.public.origin'))]]); }
    public function options(Request $request)
    {
        $tenant = $request->attributes->get('mobile.public.tenant'); $policy = app(MobilePublicIdentityPolicy::class);
        $data = ['id' => (int) $tenant->id, 'name' => $tenant->name,
            'registration' => $policy->enabled($tenant, 'registration'), 'password_reset' => $policy->enabled($tenant, 'password_reset'),
            'membership' => $policy->enabled($tenant, 'membership')];
        if ($data['membership']) { $data['intake'] = app(MobilePublicIntake::class)->options($request, $tenant); }
        return response()->json(['data' => $data]);
    }
    public function register(Request $request) { return response()->json(['data' => app(MobileIdentityChallenges::class)->request($request, 'register')]); }
    public function forgot(Request $request) { return response()->json(['data' => app(MobileIdentityChallenges::class)->request($request, 'reset')]); }
    public function membership(Request $request) { return response()->json(['data' => app(MobilePublicIntake::class)->submit($request, $request->attributes->get('mobile.public.tenant'))]); }
    public function activate(Request $request)
    {
        if ($request->isMethod('GET')) { return $this->proofForm($request, false); }
        try { $data = app(MobileIdentityChallenges::class)->activate($request); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { if ($request->expectsJson()) { throw $error; } return $this->invalidProof($error->getStatusCode()); }
        return $request->expectsJson() ? response()->json(['data' => $data])
            : $this->page('E-Mail bestätigt', 'Dein Vereinszugang wird durch den Verein geprüft. Öffne danach die App und melde dich mit deinen registrierten Angaben an.');
    }
    public function reset(Request $request)
    {
        if ($request->isMethod('GET')) { return $this->proofForm($request, true); }
        try { $data = app(MobileIdentityChallenges::class)->reset($request); }
        catch (\Illuminate\Validation\ValidationException $error) {
            if ($request->expectsJson()) { throw $error; }
            return $this->proofForm($request,true,false,'Bitte Benutzernamen und Passwort prüfen. Das Passwort braucht mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes. Beide Passwörter müssen übereinstimmen.');
        }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { if ($request->expectsJson()) { throw $error; } return $this->invalidProof($error->getStatusCode()); }
        return $request->expectsJson() ? response()->json(['data' => $data])
            : $this->page('Passwort geändert', 'Deine bisherigen mobilen Sitzungen wurden beendet. Öffne die App und melde dich mit deinem neuen Passwort an.');
    }
    public function confirmEmail(Request $request)
    {
        if ($request->isMethod('GET')) { return $this->proofForm($request,false,true); }
        try { $data=app(MobileIdentityChallenges::class)->confirmEmail($request); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { if ($request->expectsJson()) { throw $error; } return $this->invalidProof($error->getStatusCode()); }
        return $request->expectsJson()?response()->json(['data'=>$data])
            :$this->page('Recovery-E-Mail bestätigt','Deine bestätigte Recovery-Adresse kann für das Zurücksetzen deines Passworts verwendet werden. Benutzername und Vereinsrechte bleiben unverändert.');
    }
    private function invalidProof(int $status)
    {
        return $this->page('Vorgang nicht abgeschlossen','Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet. Bitte fordere den Vorgang in der App erneut an.',false,'',$status);
    }
    private function proofForm(Request $request, bool $reset, bool $enrollment=false, string $error='')
    {
        // Secrets live in the fragment, never in an outgoing URL/query/access log.
        abort_if($request->query->count() !== 0, 400);
        app(MobilePublicIdentityPolicy::class)->authorize($request->attributes->get('mobile.public.tenant'), $enrollment?'email_enrollment':($reset?'password_reset':'registration'));
        $title = $enrollment?'Recovery-E-Mail bestätigen':($reset?'Passwort zurücksetzen':'E-Mail-Adresse bestätigen');
        $fields = $reset ? '<label>Benutzername (bei mehreren Konten)<input name="username" type="text" autocomplete="username" maxlength="255"></label><label>Neues Passwort<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>'
            .'<label>Passwort wiederholen<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>' : '';
        $token=$request->isMethod('POST')?(string)$request->input('token',''):'';
        if (!preg_match('/^[0-9a-f-]{36}\.[0-9a-f]{64}$/D',$token)) { $token=''; }
        $content = ($error!==''?'<p role="alert">'.htmlspecialchars($error,ENT_QUOTES,'UTF-8').'</p>':'')
            .'<p>Bestätige diesen Vorgang, um fortzufahren. Dieser Link ist zeitlich begrenzt und nur einmal verwendbar.</p>'
            .'<p id="invalid" hidden>Dieser Link ist unvollständig. Bitte öffne den vollständigen Link aus deiner E-Mail.</p>'
            .'<form method="post"><input id="proof" name="token" type="hidden" value="'.htmlspecialchars($token,ENT_QUOTES,'UTF-8').'">'.$fields.'<button id="submit" disabled>'.$title.'</button></form>';
        $script = "const proof=location.hash.substring(1)||document.getElementById('proof').value;history.replaceState(null,'',location.pathname);if(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\.[0-9a-f]{64}$/.test(proof)){document.getElementById('proof').value=proof;document.getElementById('submit').disabled=false;}else{document.getElementById('invalid').hidden=false;}";
        return $this->page($title, $content, true, $script,$error!==''?422:200);
    }
    private function page(string $title, string $body, bool $html = false, string $script = '', int $status=200)
    {
        $nonce = base64_encode(random_bytes(18)); $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $body = $html ? $body : '<p>'.htmlspecialchars($body, ENT_QUOTES, 'UTF-8').'</p>';
        $html = '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$title.' · ClubUnio</title><style nonce="'.$nonce.'">body{font:18px system-ui;background:#f4f7fb;color:#17324e;margin:0;padding:24px}main{max-width:520px;margin:10vh auto}label,input{display:block}input{box-sizing:border-box;width:100%;padding:12px;margin:8px 0 20px}button{border:0;border-radius:8px;background:#164b8a;color:white;padding:14px 20px;font:inherit}button:disabled{opacity:.5}</style>'
            .'<main><h1>'.$title.'</h1>'.$body.'</main>'.($script !== '' ? '<script nonce="'.$nonce.'">'.$script.'</script>' : '').'</html>';
        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"]);
    }
}
