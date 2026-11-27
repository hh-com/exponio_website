<?php

namespace App\Security;

use App\Controller\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Antworten der Firewall „app_api“ als JSON: ohne Token (Einstiegspunkt) und mit falschem Token (Fehlschlag).
 */
final class AppApiAuthentication implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized('Die Anfrage enthielt kein Token. Sendet die App eines, entfernt der Webserver vermutlich die Kopfzeile „Authorization“ (siehe README, Abschnitt App-Zugang).');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized('Das Token ist ungültig oder der App-Zugang wurde gelöscht. Im Backend unter System → App-Zugänge einen neuen anlegen.');
    }

    private function unauthorized(string $message): Response
    {
        $response = ApiResponse::error('AUTH_FAILED', $message, Response::HTTP_UNAUTHORIZED);
        $response->headers->set('WWW-Authenticate', 'Bearer realm="exponio"');

        return $response;
    }
}
