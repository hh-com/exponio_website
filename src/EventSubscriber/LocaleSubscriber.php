<?php

namespace App\EventSubscriber;

use App\Localization\Locales;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Öffentliche Seiten gibt es nur in freigeschalteten Sprachen – alle anderen liefern 404.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Locales $locales)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // nach dem Router (32), vor Symfonys LocaleListener (16)
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (true !== $request->attributes->get('_front')) {
            return;
        }
        $locale = (string) $request->attributes->get('_locale');
        if (!$this->locales->isPublic($locale)) {
            throw new NotFoundHttpException(sprintf('Die Sprache "%s" ist nicht freigeschaltet.', $locale));
        }
    }
}
