<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\SponsorRunPublicService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SponsorRunPublicController
{
    public function landing()
    {
        return $this->secure(response()->view('clubunio.sponsor-run-public'));
    }

    public function info(Request $request, SponsorRunPublicService $service)
    {
        return $this->action($request, $service, 'info', 120, fn () => $service->info((string) $request->input('token', '')));
    }

    public function submit(Request $request, SponsorRunPublicService $service)
    {
        return $this->action($request, $service, 'submit', 20, function () use ($request, $service) {
            $service->submit((string) $request->input('token', ''), $request->all());

            return ['message' => 'Anfrage gespeichert. Die Bestätigung wird ausschliesslich an die Testadresse zugestellt.'];
        });
    }

    public function confirm(Request $request, SponsorRunPublicService $service)
    {
        return $this->action($request, $service, 'confirm', 20, function () use ($request, $service) {
            $service->confirm((string) $request->input('token', ''));

            return ['message' => 'Sponsorenzusage bestätigt.'];
        });
    }

    private function action(Request $request, SponsorRunPublicService $service, string $action, int $limit, callable $callback)
    {
        try {
            $service->rate('ip:'.$action, (string) $request->ip(), $limit);

            return $this->secure(response()->json($callback()));
        } catch (ValidationException $e) {
            return $this->secure(response()->json(['message' => 'Bitte die Eingaben prüfen.', 'errors' => $e->errors()], 422));
        } catch (HttpExceptionInterface $e) {
            return $this->secure(response()->json(['message' => $e->getStatusCode() === 429 ? 'Zu viele Anfragen. Bitte später erneut versuchen.' : 'Anfrage ungültig oder nicht mehr verfuegbar.'], $e->getStatusCode()));
        }
    }

    private function secure($response)
    {
        return $response->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"]);
    }
}
