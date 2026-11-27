<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Antworten der App-Schnittstelle: immer JSON, nie gecacht. Fehler haben die Form
 * {"error": {"code": "AUTH_FAILED", "message": "…", …}} mit stabilem Code für die App und deutscher Meldung.
 */
final class ApiResponse
{
    /** @param array<string, mixed> $data */
    public static function ok(array $data): JsonResponse
    {
        return self::noStore(new JsonResponse($data));
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => ['code' => $code, 'message' => $message] + $details], $status));
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $response;
    }
}
