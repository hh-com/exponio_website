<?php

namespace App\Translation;

use App\Entity\AbstractTranslation;
use App\Entity\Item;
use App\Enum\ItemStatus;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\PageRepository;
use App\Repository\TopicRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Alle öffentlichen Inhalte, die übersetzt werden sollen – in sinnvoller Reihenfolge:
 * erst Einstellungen, Objektarten und Merkmale, Länder, Themen, Seiten, dann veröffentlichte Objekte.
 * Entwürfe werden nicht übersetzt (spart Kosten).
 *
 * Die Liste wird einmal je Lauf geladen: app:translate geht damit alle Sprachen durch, ohne für jede Sprache
 * erneut alle Objekte samt Übersetzungen und Bildern aus der Datenbank zu holen.
 */
final class TranslatableSources implements ResetInterface
{
    /** @var list<TranslatableInterface<AbstractTranslation>>|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly PageRepository $pages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<TranslatableInterface<AbstractTranslation>> */
    public function all(): array
    {
        return $this->loaded ??= iterator_to_array($this->load(), false);
    }

    public function reset(): void
    {
        $this->loaded = null;
    }

    /** @return iterable<TranslatableInterface<AbstractTranslation>> */
    private function load(): iterable
    {
        yield $this->settings->get();
        foreach ($this->areas->findActive() as $area) {
            yield $area;
            yield from $area->getAttributeDefinitions();
        }
        yield from $this->categories->findAllOrdered(activeOnly: true);
        yield from $this->topics->findAllOrdered(activeOnly: true);
        foreach ($this->pages->findActive() as $page) {
            yield $page;
        }

        /** @var list<Item> $items */
        $items = $this->em->createQuery('SELECT i, t FROM App\Entity\Item i LEFT JOIN i.translations t WHERE i.status = :status ORDER BY i.publishedAt DESC')
            ->setParameter('status', ItemStatus::Published)->getResult();
        if ([] !== $items) {
            $this->em->createQuery('SELECT i, img, imgt FROM App\Entity\Item i LEFT JOIN i.images img LEFT JOIN img.translations imgt WHERE i.id IN (:ids)')
                ->setParameter('ids', array_map(static fn (Item $i): ?int => $i->getId(), $items))->getResult();
        }
        foreach ($items as $item) {
            yield $item;
            yield from $item->getImages();
        }
    }
}
