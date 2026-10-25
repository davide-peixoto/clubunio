<?php

namespace App\ClubUnio\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrors
{
    public function render(Throwable $error, Request $request): JsonResponse
    {
        $id = $request->attributes->get('clubunio.api.request_id') ?? (string) Str::uuid();
        $request->attributes->set('clubunio.api.request_id', $id);
        $status = match (true) {
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        $code = $error instanceof ValidationException ? 'validation_failed' : match ($status) {
            400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
            409 => 'conflict', 413 => 'payload_too_large', 415 => 'unsupported_media_type',
            422 => 'invalid_request', 429 => 'rate_limited', default => 'request_failed',
        };
        if ($status >= 500) {
            logger()->error('ClubUnio API failure', ['request_id' => $id, 'exception' => $error::class,
                'file' => basename($error->getFile()), 'line' => $error->getLine()]);
        }
        $response = response()->json(['error' => array_filter(['code' => $code,
            'message' => match ($status) {
                400 => 'Ungültige Anfrage.', 401 => 'Authentifizierung erforderlich.', 403 => 'Zugriff nicht erlaubt.', 404 => 'Nicht gefunden.',
                405 => 'Diese Aktion wird nicht unterstützt.', 409 => 'Konflikt. Daten neu laden und Vorgang prüfen.',
                413 => 'Anfrage zu gross.', 415 => 'Nicht unterstütztes Datenformat.', 422 => 'Eingaben prüfen.',
                429 => 'Zu viele Anfragen. Bitte später erneut versuchen.', default => 'Anfrage fehlgeschlagen.',
            }, 'request_id' => $id, 'fields' => $error instanceof ValidationException ? $error->errors() : null], fn ($v) => $v !== null)], $status);
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($status === 429) {
            $response->headers->set('Retry-After', 60);
        }

        return $response;
    }
}
