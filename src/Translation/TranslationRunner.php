<?php

namespace App\Translation;

use App\Ai\AiProgress;
use App\Entity\AbstractTranslation;
use App\Entity\Language;
use App\Entity\UiTranslation;
use App\Enum\TranslationOrigin;
use App\Localization\UiMessages;
use App\Repository\UiTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Übersetzt fehlende und veraltete Texte einer Sprache – erst die Oberfläche, dann die Inhalte.
 *
 * Es gibt keine Warteschlangen-Tabelle: Was zu tun ist, ergibt sich aus den Hashes.
 * Eine Übersetzung ist aktuell, wenn ihr sourceHash zum heutigen deutschen Text passt.
 * Mehrere Texte gehen gebündelt in einer Anfrage hinaus; $maxRequests begrenzt Kosten und Laufzeit.
 */
final class TranslationRunner
{
    private const BATCH_ENTRIES = 60;
    private const BATCH_CHARS = 9000;

    public function __construct(
        private readonly MachineTranslator $translator,
        private readonly UiMessages $uiMessages,
        private readonly UiTranslationRepository $uiTranslations,
        private readonly TranslatableSources $sources,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly AiProgress $progress,
    ) {
    }

    public function run(Language $language, int $maxRequests): TranslationStats
    {
        $stats = new TranslationStats();
        if ($language->isSource()) {
            return $stats;
        }
        if (!$language->hasUiFiles()) {
            $this->translateUi($language, $maxRequests, $stats);
        }
        $this->translateContent($language, $maxRequests, $stats);

        return $stats;
    }

    private function translateUi(Language $language, int $maxRequests, TranslationStats $stats): void
    {
        $existing = $this->uiTranslations->findByLocale($language->getCode());
        $pending = [];
        foreach ($this->uiMessages->source() as $key => $text) {
            $current = $existing[$key] ?? null;
            if (null === $current || $current->getSourceHash() !== UiTranslation::hash($text)) {
                $pending[$key] = $text;
            }
        }

        foreach ($this->batches($pending) as $batch) {
            if ($stats->requests >= $maxRequests) {
                break;
            }
            ++$stats->requests;
            $this->reportRequest($language, $stats, $maxRequests, 'Oberfläche', \count($batch));
            foreach ($this->translator->translate($batch, $language) as $key => $text) {
                $hash = UiTranslation::hash($pending[$key]);
                $row = $existing[$key] ?? null;
                if (null === $row) {
                    $row = new UiTranslation($language->getCode(), $key, $text, $hash);
                    $this->em->persist($row);
                } else {
                    $row->applyMachineTranslation($text, $hash);
                }
                unset($pending[$key]);
                ++$stats->uiTranslated;
            }
            $this->em->flush();
        }

        $stats->uiRemaining = \count($pending);
        if (0 === $stats->uiRemaining && null === $language->getUiTranslatedAt()) {
            $language->setUiTranslatedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function translateContent(Language $language, int $maxRequests, TranslationStats $stats): void
    {
        $code = $language->getCode();
        /** @var list<array{entity: TranslatableInterface<AbstractTranslation>, hash: string, values: array<string, string>}> $queue */
        $queue = [];
        foreach ($this->sources->all() as $entity) {
            $values = $entity->sourceValues();
            if ([] === $values) {
                continue;
            }
            $hash = $entity->sourceHash();
            $translation = $entity->translation($code);
            if (null !== $translation && !$translation->isEmpty() && $translation->isCurrent($hash)) {
                continue;
            }
            if (null !== $translation && TranslationOrigin::Manual === $translation->getOrigin()) {
                $this->logger->info('Deutscher Text geändert – manuelle Übersetzung ({locale}) von {class} #{id} wird neu übersetzt.', [
                    'locale' => $code, 'class' => $entity::class, 'id' => $entity->getId(),
                ]);
            }
            $queue[] = ['entity' => $entity, 'hash' => $hash, 'values' => $values];
        }

        // Einträge eines Objekts bleiben in einer Anfrage zusammen
        $batch = [];
        $size = 0;
        foreach ($queue as $job) {
            $jobSize = array_sum(array_map('mb_strlen', $job['values']));
            if ([] !== $batch && (\count($batch) >= self::BATCH_ENTRIES || $size + $jobSize > self::BATCH_CHARS)) {
                $this->translateBatch($batch, $language, $maxRequests, $stats);
                $batch = [];
                $size = 0;
            }
            $batch[] = $job;
            $size += $jobSize;
        }
        if ([] !== $batch) {
            $this->translateBatch($batch, $language, $maxRequests, $stats);
        }

        $stats->contentRemaining = \count($queue) - $stats->contentTranslated;
    }

    /**
     * Übersetzt mehrere Objekte in einer Anfrage; ein Objekt wird nur übernommen, wenn alle Felder ankamen.
     *
     * @param list<array{entity: TranslatableInterface<AbstractTranslation>, hash: string, values: array<string, string>}> $batch
     */
    private function translateBatch(array $batch, Language $language, int $maxRequests, TranslationStats $stats): void
    {
        if ($stats->requests >= $maxRequests) {
            return;
        }
        ++$stats->requests;
        $texts = [];
        foreach ($batch as $n => $job) {
            foreach ($job['values'] as $field => $text) {
                $texts[$n.'.'.$field] = $text;
            }
        }
        $this->reportRequest($language, $stats, $maxRequests, 'Inhalte', \count($batch));
        $result = $this->translator->translate($texts, $language);
        foreach ($batch as $n => $job) {
            $values = [];
            foreach (array_keys($job['values']) as $field) {
                if (isset($result[$n.'.'.$field])) {
                    $values[$field] = $result[$n.'.'.$field];
                }
            }
            if (\count($values) === \count($job['values'])) {
                $job['entity']->translationFor($language->getCode())->applyMachineTranslation($values, $job['hash']);
                ++$stats->contentTranslated;
            }
        }
        $this->em->flush();
    }

    /** Zwischenstand fürs Backend, z. B. „Englisch: Anfrage 2 von max. 5 (Inhalte, 12 Einträge) – warte auf Claude …“ */
    private function reportRequest(Language $language, TranslationStats $stats, int $maxRequests, string $part, int $entries): void
    {
        $this->progress->step(sprintf('%s: Anfrage %d von max. %d (%s, %d %s) – warte auf %s …', $language->getNameDe(), $stats->requests, $maxRequests, $part, $entries, 1 === $entries ? 'Eintrag' : 'Einträge', $this->progress->aiName()));
    }

    /**
     * @param array<string, string> $texts
     *
     * @return iterable<array<string, string>>
     */
    private function batches(array $texts): iterable
    {
        $batch = [];
        $size = 0;
        foreach ($texts as $key => $text) {
            if ([] !== $batch && (\count($batch) >= self::BATCH_ENTRIES || $size + mb_strlen($text) > self::BATCH_CHARS)) {
                yield $batch;
                $batch = [];
                $size = 0;
            }
            $batch[$key] = $text;
            $size += mb_strlen($text);
        }
        if ([] !== $batch) {
            yield $batch;
        }
    }
}
