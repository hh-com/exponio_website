<?php

namespace App\Recognition;

use App\Ai\AiProgress;
use App\Ai\AiProvider;
use App\Duplicate\DuplicateDetector;
use App\Duplicate\DuplicateOutcome;
use App\Entity\Item;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Führt die Erkennung für ein Objekt aus und speichert Ergebnis oder Fehler.
 * KI-Anbieter laut Einstellungen (Gemini, Claude) oder AI_PROVIDER=fake (Entwicklung und Tests ohne API-Key).
 *
 * Bei frisch importierten Objekten folgt direkt die Duplikatprüfung. Ist es ein sicheres Duplikat, wird das
 * Objekt dem vorhandenen zugeordnet und gelöscht – Item::getMergedUpload() zeigt dann den Upload beim vorhandenen Objekt.
 */
final class RecognitionService
{
    private DuplicateOutcome $lastDuplicate;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiRecognizer $ai,
        private readonly FakeRecognizer $fake,
        private readonly PromptBuilder $prompts,
        private readonly RecognitionApplier $applier,
        private readonly DuplicateDetector $duplicates,
        private readonly ItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly AiProgress $progress,
    ) {
        $this->lastDuplicate = new DuplicateOutcome(DuplicateOutcome::SKIPPED);
    }

    public function isFake(): bool
    {
        return $this->provider->isFake();
    }

    /** Ergebnis der Duplikatprüfung nach der letzten erfolgreichen Erkennung */
    public function lastDuplicate(): DuplicateOutcome
    {
        return $this->lastDuplicate;
    }

    public function recognize(Item $item): bool
    {
        $this->lastDuplicate = new DuplicateOutcome(DuplicateOutcome::SKIPPED);
        try {
            $this->progress->step('Fotos und Prompt werden vorbereitet …');
            $request = $this->prompts->build($item);
            $photos = \count($request->images);
            $this->progress->step(sprintf('%d Foto%s an %s gesendet – warte auf Antwort …', $photos, 1 === $photos ? '' : 's', $this->progress->aiName()));
            $response = ($this->isFake() ? $this->fake : $this->ai)->recognize($request);
            $this->progress->step('Antwort erhalten – Ergebnis wird übernommen …');
            $this->applier->apply($item, $response['data'], $response['model'], $request->areas);
            $this->em->flush();
            $this->lastDuplicate = $this->duplicates->checkImported($item);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Erkennung von Objekt {id} fehlgeschlagen: {message}', ['id' => $item->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            if (!$this->em->isOpen()) {
                throw $e;
            }
            $item->setAiError(mb_substr($e->getMessage(), 0, 2000));
            $this->em->flush();

            return false;
        }
    }

    /**
     * Erkennt bis zu $limit noch nicht erkannte Entwürfe.
     *
     * @param callable(Item, bool): void|null $onItem
     *
     * @return array{ok: int, failed: int}
     */
    public function recognizePending(int $limit, bool $retryFailed = false, ?callable $onItem = null): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        foreach ($this->items->findUnrecognized($limit, $retryFailed) as $item) {
            $ok = $this->recognize($item);
            ++$result[$ok ? 'ok' : 'failed'];
            if (null !== $onItem) {
                $onItem($item, $ok);
            }
        }

        return $result;
    }
}
