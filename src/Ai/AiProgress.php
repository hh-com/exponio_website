<?php

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Zwischenstand langer KI-Aufgaben für das Backend.
 *
 * Der Browser schickt mit der Aktion eine zufällige ID mit (Header X-Progress-Id oder Formularfeld _progress)
 * und fragt währenddessen /admin/ai-progress?id=… ab (App\Controller\Admin\AiProgressController).
 * Die Dienste melden ihre Schritte mit step(). Ohne ID (Cronjob, Konsole) passiert nichts.
 */
final class AiProgress
{
    public const HEADER = 'X-Progress-Id';
    public const FIELD = '_progress';
    private const TTL = 900;
    private const MAX_STEPS = 20;

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire(service: 'ai_progress.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly AiProvider $provider,
    ) {
    }

    public static function isValidId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9-]{16,64}$/', $id);
    }

    /** Name der KI für Statusmeldungen, z. B. „Claude“ */
    public function aiName(): string
    {
        return $this->provider->isFake() ? 'Test-KI' : AiProvider::shortLabel($this->provider->name());
    }

    /** Meldet einen Zwischenschritt der laufenden Backend-Anfrage. */
    public function step(string $message): void
    {
        $id = $this->currentId();
        if (null === $id) {
            return;
        }
        $item = $this->cache->getItem(self::key($id));
        $steps = $item->isHit() ? self::normalize($item->get()) : [];
        $steps[] = ['message' => $message, 'at' => round(microtime(true), 3)];
        $this->cache->save($item->set(\array_slice($steps, -self::MAX_STEPS))->expiresAfter(self::TTL));
    }

    /** @return list<array{message: string, at: float}> */
    public function steps(string $id): array
    {
        if (!self::isValidId($id)) {
            return [];
        }
        $item = $this->cache->getItem(self::key($id));

        return $item->isHit() ? self::normalize($item->get()) : [];
    }

    private function currentId(): ?string
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return null;
        }
        $id = $request->headers->get(self::HEADER) ?? $request->request->get(self::FIELD);
        $id = \is_string($id) ? $id : '';

        return self::isValidId($id) ? $id : null;
    }

    /** @return list<array{message: string, at: float}> */
    private static function normalize(mixed $data): array
    {
        $steps = [];
        foreach (\is_array($data) ? $data : [] as $step) {
            if (\is_array($step) && \is_string($step['message'] ?? null) && is_numeric($step['at'] ?? null)) {
                $steps[] = ['message' => $step['message'], 'at' => (float) $step['at']];
            }
        }

        return $steps;
    }

    private static function key(string $id): string
    {
        return 'ai_progress.'.$id;
    }
}
