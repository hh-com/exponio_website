<?php

namespace App\Duplicate;

use App\Ai\AiProgress;
use App\Ai\AiProvider;
use App\Entity\Item;
use App\Enum\DuplicateStatus;
use App\Enum\ItemStatus;
use App\Repository\ItemRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duplikat-Erkennung nach der KI-Erkennung eines neuen Uploads:
 *
 * 1. Vorauswahl ohne KI (CandidateFinder): höchstens drei Kandidaten.
 * 2. Ein KI-Aufruf vergleicht die neuen Fotos mit den Kandidaten (AI_PROVIDER=fake: FakeComparer).
 * 3. Entscheidung nach den Schwellen aus Einstellungen → KI:
 *    - Sicherheit ≥ „sicher ab“: Upload dem vorhandenen Objekt zuordnen (DuplicateMerger), kein neues Objekt.
 *    - Sicherheit ≥ „Hinweis ab“: neues Objekt bleibt Entwurf mit Hinweis „mögliches Duplikat von #ID“.
 *    - sonst: kein Duplikat.
 *
 * Automatisch zugeordnet werden nur frisch importierte Objekte. Der Bestand (app:duplicates:scan) bekommt nur Hinweise.
 */
final class DuplicateDetector
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly CandidateFinder $finder,
        private readonly ComparisonPromptBuilder $prompts,
        private readonly AiComparer $ai,
        private readonly FakeComparer $fake,
        private readonly DuplicateMerger $merger,
        private readonly SettingsProvider $settings,
        private readonly ItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly AiProgress $progress,
    ) {
    }

    /**
     * Prüft ein frisch importiertes, erkanntes Objekt. Ein Fehler beim Vergleich ändert nichts am Objekt;
     * die Prüfung wird beim nächsten app:scan wiederholt.
     */
    public function checkImported(Item $item): DuplicateOutcome
    {
        if (DuplicateStatus::Pending !== $item->getDuplicateStatus() || null === $item->getRecognizedAt()) {
            return new DuplicateOutcome(DuplicateOutcome::SKIPPED);
        }
        if (!$this->settings->get()->isDuplicateCheck()) {
            $item->setDuplicateStatus(null);
            $this->em->flush();

            return new DuplicateOutcome(DuplicateOutcome::SKIPPED);
        }

        try {
            [$candidates, $match, $candidate] = $this->evaluate($item, false);
        } catch (\Throwable $e) {
            $this->logger->error('Duplikatprüfung von Objekt {id} fehlgeschlagen: {message}', ['id' => $item->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            if (!$this->em->isOpen()) {
                throw $e;
            }

            return new DuplicateOutcome(DuplicateOutcome::FAILED, error: $e->getMessage());
        }

        return $this->apply($item, $candidates, $match, $candidate, true);
    }

    /**
     * Bestand prüfen (app:duplicates:scan): Vergleich nur mit älteren Objekten, nie automatisch zuordnen.
     */
    public function checkExisting(Item $item): DuplicateOutcome
    {
        [$candidates, $match, $candidate] = $this->evaluate($item, true);

        return $this->apply($item, $candidates, $match, $candidate, false);
    }

    /**
     * Nur die Vorauswahl, ohne KI und ohne zu speichern (--dry-run, Vorschau).
     *
     * @return list<Candidate>
     */
    public function candidates(Item $item, bool $olderOnly = false): array
    {
        return $this->finder->find($item, $olderOnly);
    }

    /**
     * Prüfungen nachholen, die nach der Erkennung noch ausstehen (z. B. weil der Vergleich fehlgeschlagen ist).
     *
     * @param callable(Item, DuplicateOutcome): void|null $onItem
     */
    public function checkPending(int $limit, ?callable $onItem = null): int
    {
        $count = 0;
        foreach ($this->items->findPendingDuplicateChecks($limit) as $item) {
            $outcome = $this->checkImported($item);
            ++$count;
            if (null !== $onItem) {
                $onItem($item, $outcome);
            }
        }

        return $count;
    }

    /** Vorschau im Backend: Prompt und Vergleichsauftrag für das zuletzt erkannte Objekt (ohne Fotos, ohne KI). */
    public function preview(): string
    {
        $prompt = $this->settings->get()->getDuplicatePromptText();
        $example = $this->items->findOneBy([], ['recognizedAt' => 'DESC', 'id' => 'DESC']);
        if (null === $example || null === $example->getRecognizedAt()) {
            return $prompt."\n\n--- Beispielanfrage ---\n(noch kein erkanntes Objekt)";
        }
        try {
            $candidates = $this->finder->find($example);
        } catch (\Throwable $e) {
            return $prompt."\n\n--- Beispielanfrage ---\n(Vorauswahl nicht möglich: ".$e->getMessage().')';
        }
        if ([] === $candidates) {
            return $prompt."\n\n--- Beispielanfrage ---\n".sprintf('(für #%d „%s“ gibt es keine Kandidaten – die KI würde nicht gefragt)', $example->getId(), $example->getTitle());
        }

        return $prompt."\n\n--- Beispielanfrage für #".$example->getId()." ---\n".$this->prompts->text($example, $candidates);
    }

    /**
     * @return array{list<Candidate>, ComparisonMatch|null, Candidate|null}
     */
    private function evaluate(Item $item, bool $olderOnly): array
    {
        $this->progress->step('Duplikatprüfung: suche ähnliche Objekte …');
        $candidates = $this->finder->find($item, $olderOnly);
        if ([] === $candidates) {
            return [[], null, null];
        }
        $this->progress->step(sprintf('Duplikatprüfung: %s vergleicht die Fotos mit %d Kandidat%s …', $this->progress->aiName(), \count($candidates), 1 === \count($candidates) ? '' : 'en'));
        $request = $this->prompts->build($item, $candidates);
        $response = ($this->provider->isFake() ? $this->fake : $this->ai)->compare($request);

        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[(int) $candidate->item->getId()] = $candidate;
        }
        $best = null;
        $matches = $response['data']['matches'] ?? null;
        foreach (\is_array($matches) ? $matches : [] as $row) {
            $match = \is_array($row) ? ComparisonMatch::fromArray($row) : null;
            if (null === $match || !$match->sameObject || !isset($byId[$match->candidateId])) {
                continue;
            }
            if (null === $best || $match->confidence > $best->confidence) {
                $best = $match;
            }
        }

        return [$candidates, $best, null === $best ? null : $byId[$best->candidateId]];
    }

    /** @param list<Candidate> $candidates */
    private function apply(Item $item, array $candidates, ?ComparisonMatch $match, ?Candidate $candidate, bool $autoMerge): DuplicateOutcome
    {
        $settings = $this->settings->get();
        if (null !== $match && null !== $candidate) {
            if ($autoMerge && $match->confidence >= $settings->getDuplicateThreshold()) {
                $upload = $this->merger->merge($item, $candidate->item, $match);

                return new DuplicateOutcome(DuplicateOutcome::MERGED, $candidates, $match, $candidate->item, $upload);
            }
            if ($match->confidence >= $settings->getDuplicateHintThreshold()) {
                $item->markPossibleDuplicate($candidate->item, $match->confidence, $match->reason, $match->toArray());
                if ($autoMerge && $item->isPublished()) {
                    // „Automatisch veröffentlichen“ greift bei einem möglichen Duplikat nicht: erst prüfen
                    $item->setStatus(ItemStatus::Review)->setPublishedAt(null);
                }
                $this->em->flush();

                return new DuplicateOutcome(DuplicateOutcome::POSSIBLE, $candidates, $match, $candidate->item);
            }
        }
        $item->markPossibleDuplicate(null);
        $item->setDuplicateStatus(DuplicateStatus::Unique);
        $this->em->flush();

        return new DuplicateOutcome(DuplicateOutcome::UNIQUE, $candidates, $match);
    }
}
