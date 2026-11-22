<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Backend-Aktionen im Hintergrund (assets/admin/aiProgress.ts): Der Browser schickt das Formular per fetch
 * mit dem Header X-Background-Action. Statt Weiterleitung plus Meldungen (addFlash) bekommt er dann JSON:
 *
 *   {"redirect": "/admin/…", "messages": [{"type": "success", "message": "…"}]}
 *
 * Die Controller bleiben unverändert; ohne den Header (z. B. ohne JavaScript) läuft alles wie bisher.
 */
final class BackgroundActionSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Background-Action';

    public static function getSubscribedEvents(): array
    {
        // vor dem Speichern der Session (SessionListener, Priorität -1000)
        return [KernelEvents::RESPONSE => ['onResponse', 0]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || '1' !== $request->headers->get(self::HEADER) || !$response->isRedirection()
            || !str_starts_with($request->getPathInfo(), '/admin') || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $messages = [];
        foreach ($session->getFlashBag()->all() as $type => $list) {
            foreach ($list as $message) {
                $text = \is_array($message) ? ($message['message'] ?? '') : $message;
                if (\is_string($text) && '' !== $text) {
                    $messages[] = ['type' => (string) $type, 'message' => $text];
                }
            }
        }

        $json = new JsonResponse(['redirect' => $response->headers->get('Location'), 'messages' => $messages]);
        $json->headers->set('Cache-Control', 'private, no-store');
        $event->setResponse($json);
    }
}
