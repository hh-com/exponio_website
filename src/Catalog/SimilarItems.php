<?php

namespace App\Catalog;

use App\Entity\Item;
use App\Entity\Language;
use App\Enum\ItemStatus;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ähnliche Objekte über eine einfache Punktwertung:
 * gleiche Serie, gleiches/benachbartes Land, gemeinsame Themen, gleiche Objektart, nahes Jahr.
 */
final class SimilarItems
{
    private const CANDIDATES = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRepository $items,
    ) {
    }

    /** @return list<Item> */
    public function find(Item $item, int $limit = 8): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i')->from(Item::class, 'i')
            ->andWhere('i.status = :published')->setParameter('published', ItemStatus::Published)
            ->andWhere('i.id <> :self')->setParameter('self', $item->getId())
            ->setMaxResults(self::CANDIDATES);

        $or = [];
        if (null !== $item->getCategory()) {
            $ids = [$item->getCategory()->getId()];
            if (null !== $parent = $item->getCategory()->getParent()) {
                $ids[] = $parent->getId();
                foreach ($parent->getChildren() as $sibling) {
                    $ids[] = $sibling->getId();
                }
            }
            $or[] = 'i.category IN (:categories)';
            $qb->setParameter('categories', array_values(array_unique($ids)));
        }
        if (null !== $item->getSeries()) {
            $or[] = 'i.id IN (SELECT IDENTITY(ss.item) FROM App\Entity\ItemTranslation ss WHERE ss.locale = :source AND ss.series = :series)';
            $qb->setParameter('series', $item->getSeries())->setParameter('source', Language::SOURCE);
        }
        if (!$item->getTopics()->isEmpty()) {
            $or[] = 'i.id IN (SELECT si.id FROM App\Entity\Item si JOIN si.topics st WHERE st.id IN (:topics))';
            $qb->setParameter('topics', $item->getTopics()->map(static fn ($t) => $t->getId())->getValues());
        }
        if (null !== $item->getArea() && null !== $item->getYear()) {
            $or[] = '(i.area = :area AND i.year BETWEEN :y1 AND :y2)';
            $qb->setParameter('area', $item->getArea())->setParameter('y1', $item->getYear() - 10)->setParameter('y2', $item->getYear() + 10);
        }
        if ([] === $or) {
            return $this->fallback($item, [], $limit);
        }

        /** @var list<Item> $candidates */
        $candidates = $qb->andWhere(implode(' OR ', $or))->orderBy('i.publishedAt', 'DESC')->getQuery()->getResult();
        $this->items->preload($candidates);
        $scored = [];
        foreach ($candidates as $candidate) {
            $scored[] = ['item' => $candidate, 'score' => self::score($item, $candidate)];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $b['item']->getId() <=> $a['item']->getId());
        $result = array_map(static fn (array $s): Item => $s['item'], \array_slice($scored, 0, $limit));

        if (\count($result) < $limit) {
            $result = [...$result, ...$this->fallback($item, $result, $limit - \count($result))];
        }
        $this->items->preload($result);

        return $result;
    }

    public static function score(Item $item, Item $candidate): float
    {
        $score = 0.0;
        if (null !== $item->getSeries() && $item->getSeries() === $candidate->getSeries()) {
            $score += 5;
        }
        $category = $item->getCategory();
        $other = $candidate->getCategory();
        if (null !== $category && null !== $other) {
            if ($category === $other) {
                $score += 3;
            } elseif ($category->getParent() === $other || $other->getParent() === $category
                || (null !== $category->getParent() && $category->getParent() === $other->getParent())) {
                $score += 1.5;
            }
        }
        $shared = 0;
        foreach ($candidate->getTopics() as $topic) {
            if ($item->getTopics()->contains($topic)) {
                ++$shared;
            }
        }
        $score += 2 * min(3, $shared);
        if (null !== $item->getArea() && $item->getArea() === $candidate->getArea()) {
            ++$score;
        }
        if (null !== $item->getYear() && null !== $candidate->getYear()) {
            $score += 2 * max(0, 1 - abs($item->getYear() - $candidate->getYear()) / 15);
        }

        return $score;
    }

    /**
     * @param list<Item> $exclude
     *
     * @return list<Item>
     */
    private function fallback(Item $item, array $exclude, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $ids = [$item->getId(), ...array_map(static fn (Item $i): ?int => $i->getId(), $exclude)];
        $qb = $this->em->createQueryBuilder()
            ->select('i')->from(Item::class, 'i')
            ->andWhere('i.status = :published')->setParameter('published', ItemStatus::Published)
            ->andWhere('i.id NOT IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('i.publishedAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $item->getArea()) {
            $qb->andWhere('i.area = :area')->setParameter('area', $item->getArea());
        }

        return $qb->getQuery()->getResult();
    }
}
