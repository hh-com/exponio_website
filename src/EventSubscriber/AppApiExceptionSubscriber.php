<?php

namespace App\EventSubscriber;

use App\Controller\Api\ApiResponse;
use App\Import\UploadInboxException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fehler unter /api/app/ als JSON statt als HTML-Fehlerseite, damit die App sie verständlich anzeigen kann.
 * Anmeldefehler beantwortet vorher die Firewall (App\Security\AppApiAuthentication).
 */
final class AppApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // nach der Firewall (1, beantwortet fehlende/ungültige Tokens), vor Symfonys Fehlerseite (-128). Bei gleicher
        // Priorität wie ErrorListener::logKernelException (0) läuft dieser Subscriber zuerst (App-Dienste sind früher
        // registriert, siehe debug:event-dispatcher kernel.exception): Erwartete Fehler wie eine falsche Prüfsumme
        // landen so nicht als „Uncaught PHP Exception“ (CRITICAL) im Log.
        return [KernelEvents::EXCEPTION => ['onException', 0]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/app/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof UploadInboxException) {
            if ($e->status >= 500) {
                $this->logger->error('App-Upload: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }
            $event->setResponse(ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details));
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $event->setResponse(match ($status) {
                404 => ApiResponse::error('NOT_FOUND', 'Diese Adresse gibt es in der Schnittstelle nicht. Ist die App aktuell?', 404),
                405 => ApiResponse::error('METHOD_NOT_ALLOWED', 'Diese Anfrage-Methode ist hier nicht erlaubt. Ist die App aktuell?', 405),
                403 => ApiResponse::error('FORBIDDEN', 'Dieser Zugang darf das nicht.', 403),
                default => ApiResponse::error('HTTP_'.$status, 'Die Anfrage wurde abgelehnt (HTTP '.$status.').', $status),
            });
        } else {
            $this->logger->error('App-Upload fehlgeschlagen: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $event->setResponse(ApiResponse::error('SERVER_ERROR', 'Interner Fehler der Website. Details stehen im Log (var/log).', 500));
        }
    }
}
