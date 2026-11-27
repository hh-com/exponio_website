<?php

namespace App\Recognition;

use App\Ai\AiConfig;
use App\Ai\AiProgress;
use App\Ai\AiProvider;
use App\Duplicate\DuplicateDetector;
use App\Duplicate\DuplicateOutcome;
use App\Entity\Item;
use App\Entity\RecognitionResult;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Führt die Erkennung für ein Objekt aus und speichert Ergebnis oder Fehler.
 * Modelle laut Einstellungen → KI (alle aktiven Zeilen) oder AI_PROVIDER=fake (Entwicklung und Tests ohne API-Key).
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
        private readonly AiConfig $config,
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

    /**
     * Fragt jede aktive Zeile „Modelle für die Erkennung“ nacheinander und speichert jedes Ergebnis
     * (Item::getRecognitions()). Ins Formular kommt das erste erfolgreiche in der Reihenfolge der Zeilen, also
     * normalerweise das der Hauptzeile. Fehlgeschlagen ist die Erkennung nur, wenn kein Modell etwas geliefert hat.
     */
    public function recognize(Item $item): bool
    {
        $this->lastDuplicate = new DuplicateOutcome(DuplicateOutcome::SKIPPED);
        try {
            $this->progress->step('Fotos und Prompt werden vorbereitet …');
            $request = $this->prompts->build($item);
            $results = $this->runAll($item, $request);
            $item->replaceRecognitions($results);

            $applied = null;
            foreach ($results as $result) {
                if ($result->isSuccessful()) {
                    $applied = $result;
                    break;
                }
            }
            if (null === $applied) {
                $errors = array_map(fn (RecognitionResult $r): string => (\count($results) > 1 ? $this->label($r).': ' : '').$r->getError(), $results);
                throw new RecognitionException(implode("\n", $errors));
            }
            $this->progress->step(\count($results) > 1
                ? sprintf('Ergebnis von %s wird übernommen …', $this->label($applied))
                : 'Antwort erhalten – Ergebnis wird übernommen …');
            $this->applier->apply($item, (array) $applied->getData(), (string) $applied->getModelUsed(), $request->areas);
            $item->markAppliedRecognition($applied);
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
     * Eine Anfrage je aktiver Zeile; ein Fehler bei einem Modell hält die anderen nicht auf.
     *
     * @return list<RecognitionResult>
     */
    private function runAll(Item $item, RecognitionRequest $request): array
    {
        $rows = $this->config->recognizers();
        $photos = \count($request->images);
        $results = [];
        foreach ($rows as $position => $row) {
            $result = new RecognitionResult($item, $position, $row->provider, (string) $row->model);
            $this->progress->step(sprintf(
                '%s%d Foto%s an %s gesendet – warte auf Antwort …',
                \count($rows) > 1 ? sprintf('Modell %d von %d: ', $position + 1, \count($rows)) : '',
                $photos,
                1 === $photos ? '' : 's',
                $this->isFake() ? 'Test-KI' : $this->label($result),
            ));
            $start = hrtime(true);
            try {
                $response = $this->isFake()
                    ? $this->fake->recognize($request, $row, $position)
                    : $this->ai->recognize($request, $row);
                $result->succeed($response['data'], $response['model'], $response['usage'], self::elapsed($start), $row->cost($response['usage']['input'], $response['usage']['output']));
            } catch (\Throwable $e) {
                $this->logger->warning('Erkennung von Objekt {id} mit {provider} ({model}) fehlgeschlagen: {message}', ['id' => $item->getId(), 'provider' => $row->provider, 'model' => $row->model, 'message' => $e->getMessage(), 'exception' => $e]);
                $result->fail($e->getMessage(), self::elapsed($start));
            }
            $results[] = $result;
        }

        return $results;
    }

    /** z. B. „Claude (claude-sonnet-5-5)“ */
    private function label(RecognitionResult $result): string
    {
        return sprintf('%s (%s)', AiProvider::shortLabel($result->getProvider()), $result->getModel());
    }

    private static function elapsed(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
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
