<?php

namespace App\Controller\Api;

use App\EventSubscriber\AppUploadImportSubscriber;
use App\Import\UploadInbox;
use App\Import\UploadInboxException;
use App\Security\AppTokenHandler;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Upload-Schnittstelle für die Exponio-App. Anmeldung mit „Authorization: Bearer <Token>“ (Backend → System → App-Zugänge).
 * Wohin die Dateien kommen, entscheidet die Website (SCAN_DIR, siehe App\Import\UploadInbox).
 *
 *   GET  /api/app/v1/status[?writeTest=1]            Verbindung, Token und Schreibrechte prüfen
 *   GET  /api/app/v1/uploads/{id}                    Dateien eines offenen Uploads (zum Fortsetzen nach Abbruch)
 *   PUT  /api/app/v1/uploads/{id}/files/{name}       eine Datei; Body = Inhalt, Kopfzeile X-Content-SHA256 = Prüfsumme
 *   POST /api/app/v1/uploads/{id}/complete           {"files": [{"name", "size", "sha256"}, …]} → fertiger Ordner
 *
 * Ein fertiger Ordner wird direkt nach der Antwort importiert (App\EventSubscriber\AppUploadImportSubscriber).
 * {id} ist der Zeitstempel der App (2026-11-20_211530), {name} 01.webp, 02.jpg … oder info.txt.
 * Fehler kommen als JSON mit Code (App\Controller\Api\ApiResponse, App\EventSubscriber\AppApiExceptionSubscriber).
 */
#[IsGranted(AppTokenHandler::ROLE)]
#[Route('/api/app/v1', name: 'api_app_')]
final class AppUploadController extends AbstractController
{
    public const API_VERSION = 1;

    public function __construct(
        private readonly UploadInbox $inbox,
        private readonly SettingsProvider $settings,
        private readonly AppUploadImportSubscriber $importAfterUpload,
    ) {
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $this->inbox->check($request->query->getBoolean('writeTest'));

        return ApiResponse::ok([
            'api' => self::API_VERSION,
            'site' => $this->settings->get()->getSiteName(),
            'writeTested' => $request->query->getBoolean('writeTest'),
            'maxFileSize' => UploadInbox::MAX_FILE_SIZE,
        ]);
    }

    #[Route('/uploads/{id}', name: 'upload_state', methods: ['GET'])]
    public function state(string $id): JsonResponse
    {
        $files = $this->inbox->openFiles($id);

        return ApiResponse::ok(['state' => null === $files ? 'none' : 'open', 'files' => $files ?? []]);
    }

    #[Route('/uploads/{id}/files/{name}', name: 'upload_file', methods: ['PUT'])]
    public function putFile(string $id, string $name, Request $request): JsonResponse
    {
        $length = $request->headers->get('Content-Length');
        // Body nicht in den Speicher laden: Fotos können mehrere MB groß sein
        $body = $request->getContent(true);

        return ApiResponse::ok($this->inbox->storeFile(
            $id,
            $name,
            $body,
            (string) $request->headers->get('X-Content-SHA256'),
            null !== $length && ctype_digit($length) ? (int) $length : null,
        ));
    }

    #[Route('/uploads/{id}/complete', name: 'upload_complete', methods: ['POST'])]
    public function complete(string $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data) || !\is_array($data['files'] ?? null) || !array_is_list($data['files'])) {
            throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, 'Erwartet wird JSON der Form {"files": [{"name", "size", "sha256"}, …]}.');
        }

        $done = $this->inbox->complete($id, $data['files']);
        // auch bei „already“: Fiel der erste Sofortimport aus, holt ihn die Wiederholung nach (sonst ist der Ordner nicht mehr neu)
        $this->importAfterUpload->schedule($done['folder']);

        return ApiResponse::ok($done);
    }
}
