<?php

namespace App\EventSubscriber;

use App\Import\FolderImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sofortimport nach einem Upload der App: Ist ein Upload abgeschlossen (App\Controller\Api\AppUploadController::complete),
 * wird sein Ordner nach dem Senden der Antwort importiert (kernel.terminate), statt auf den Cronjob zu warten.
 *
 * Das Objekt entsteht wie bei „Ordner scannen“ als Entwurf und erscheint unter „Prüfen & freigeben“; KI-Erkennung und
 * Duplikatprüfung macht weiter app:scan. Schlägt der Import fehl, steht das im Scan-Protokoll und im Log – die Antwort an
 * die App ist dann schon gesendet und bleibt ein Erfolg, denn der Upload selbst ist vollständig.
 *
 * Mit PHP-FPM bekommt die App die Antwort sofort, mit mod_php erst nach dem Import (meist wenige Sekunden).
 */
final class AppUploadImportSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, true> */
    private array $folders = [];

    public function __construct(
        private readonly FolderImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function schedule(string $folder): void
    {
        $this->folders[$folder] = true;
    }

    public function onTerminate(): void
    {
        $folders = array_keys($this->folders);
        $this->folders = [];
        if ([] === $folders) {
            return;
        }
        // wie „Ordner scannen“ im Backend: Webbilder erzeugen dauert, und ein Abbruch der Verbindung darf den Import nicht stoppen
        ignore_user_abort(true);
        set_time_limit(600);
        foreach ($folders as $folder) {
            try {
                // Fehler einzelner Ordner protokolliert der Importer selbst (Log und Scan-Protokoll)
                $this->importer->importNew(1, $folder);
            } catch (\Throwable $e) {
                $this->logger->error('Sofortimport von {folder} fehlgeschlagen, der Cronjob versucht es erneut: {message}', ['folder' => $folder, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }

    public function reset(): void
    {
        $this->folders = [];
    }
}
