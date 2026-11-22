<?php

namespace App\Tests\Unit;

use App\EventSubscriber\BackgroundActionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BackgroundActionSubscriberTest extends TestCase
{
    public function testRedirectWithFlashesBecomesJson(): void
    {
        $request = $this->request('/admin/settings/test-ai', true);
        $session = $request->getSession();
        \assert($session instanceof Session);
        $session->getFlashBag()->add('warning', 'Erkannt, aber mögliches Duplikat.');
        $session->getFlashBag()->add('success', 'Erkannt: „Bund 1955“.');

        $response = $this->dispatch($request, new RedirectResponse('/admin/item/5/edit'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame([
            'redirect' => '/admin/item/5/edit',
            'messages' => [
                ['type' => 'warning', 'message' => 'Erkannt, aber mögliches Duplikat.'],
                ['type' => 'success', 'message' => 'Erkannt: „Bund 1955“.'],
            ],
        ], json_decode((string) $response->getContent(), true));
        self::assertSame([], $session->getFlashBag()->peekAll(), 'Meldungen sind verbraucht und erscheinen nicht noch einmal');
    }

    public function testOtherRequestsStayUnchanged(): void
    {
        $redirect = new RedirectResponse('/admin');
        self::assertSame($redirect, $this->dispatch($this->request('/admin/settings/test-ai', false), $redirect), 'ohne Header');
        self::assertSame($redirect, $this->dispatch($this->request('/de/sammlung', true), $redirect), 'nicht im Backend');
        $page = new Response('Fehler', 403);
        self::assertSame($page, $this->dispatch($this->request('/admin/x', true), $page), 'keine Weiterleitung');
    }

    private function request(string $path, bool $background): Request
    {
        $request = Request::create($path, 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($background) {
            $request->headers->set(BackgroundActionSubscriber::HEADER, '1');
        }

        return $request;
    }

    private function dispatch(Request $request, Response $response): Response
    {
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new BackgroundActionSubscriber())->onResponse($event);

        return $event->getResponse();
    }
}
