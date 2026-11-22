<?php

namespace App\Catalog;

use App\Cache\ContentCache;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\Language;
use App\Enum\AttributeType;
use App\Enum\ItemStatus;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\TopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Katalogsuche mit Facetten. Jede Facette wird mit allen Filtern außer ihrem eigenen gezählt,
 * damit Mehrfachauswahl innerhalb einer Facette sinnvolle Zahlen zeigt.
 * Die Facetten werden im ContentCache gehalten (bis zur nächsten Inhaltsänderung).
 */
final class CatalogSearch
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRepository $items,
        private readonly CategoryTree $tree,
        private readonly CollectionAreaRepository $areas,
        private readonly TopicRepository $topics,
        private readonly TranslatorInterface $translator,
        private readonly ContentCache $cache,
    ) {
    }

    public function search(CatalogQuery $query, int $perPage, string $locale, bool $withFacets = true): CatalogResult
    {
        $total = (int) $this->base($query, $locale)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($query->page, $pages);

        $qb = $this->base($query, $locale)->select('i');
        $this->applySort($qb, $query->sort, $locale);
        /** @var list<Item> $items */
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();
        $this->items->preload($items);

        $facets = $withFacets
            ? $this->cache->get('facets.'.$locale.'.'.sha1((string) json_encode($query->withPage(1)->toArray())), fn (): array => $this->facets($query, $locale))
            : [];

        return new CatalogResult($items, $total, $page, $pages, $facets);
    }

    /** @return array<string, mixed> */
    public function facets(CatalogQuery $query, string $locale): array
    {
        $facets = [];

        // Objektarten
        $counts = $this->groupCount($this->base($query, $locale, ['area']), 'IDENTITY(i.area)');
        $facets['areas'] = [];
        if (null === $query->fixedArea) {
            foreach ($this->areas->findActive() as $area) {
                if (($counts[$area->getId()] ?? 0) > 0 || $area->getId() === $query->area) {
                    $facets['areas'][] = ['id' => $area->getId(), 'label' => $area->getName($locale), 'icon' => $area->getIcon(), 'count' => $counts[$area->getId()] ?? 0];
                }
            }
        }

        // Länder (Baum, aufsummiert)
        $direct = $this->groupCount($this->base($query, $locale, ['region'])->andWhere('i.category IS NOT NULL'), 'IDENTITY(i.category)');
        $facets['regions'] = $this->tree->toFacetTree($this->tree->aggregate($direct), $locale, $query->fixedRegion);

        // Themen
        $qb = $this->base($query, $locale, ['topic'])->innerJoin('i.topics', 'ft');
        $topicCounts = $this->groupCount($qb, 'ft.id', 'COUNT(DISTINCT i.id)');
        $facets['topics'] = [];
        foreach ($this->topics->findAllOrdered(activeOnly: true) as $topic) {
            $count = $topicCounts[$topic->getId()] ?? 0;
            if ($topic->getId() !== $query->fixedTopic && ($count > 0 || \in_array($topic->getId(), $query->topics, true))) {
                $facets['topics'][] = ['id' => $topic->getId(), 'label' => $topic->getName($locale), 'count' => $count];
            }
        }

        // Jahre
        $years = $this->base($query, $locale, ['year'])
            ->select('MIN(i.year) AS minYear, MAX(COALESCE(i.yearTo, i.year)) AS maxYear')
            ->getQuery()->getSingleResult();
        $facets['years'] = [
            'min' => null !== $years['minYear'] ? (int) $years['minYear'] : null,
            'max' => null !== $years['maxYear'] ? (int) $years['maxYear'] : null,
        ];

        // Merkmale der gewählten Objektart
        $facets['attributes'] = [];
        $area = null !== $query->effectiveArea() ? $this->areas->find($query->effectiveArea()) : null;
        if ($area instanceof CollectionArea) {
            foreach ($area->getFilterableDefinitions() as $definition) {
                $code = (string) $definition->getCode();
                $qb = $this->base($query, $locale, ['attr:'.$code])
                    ->innerJoin('i.attributeValues', 'fa', 'WITH', 'fa.definition = :facetDef')
                    ->setParameter('facetDef', $definition->getId());
                $valueCounts = $this->groupCount($qb, 'fa.value', 'COUNT(DISTINCT i.id)');
                $selected = $query->attributes[$code] ?? [];
                $choices = AttributeType::Boolean === $definition->getType()
                    ? [['value' => '1', 'label' => $this->translator->trans('common.yes', [], 'messages', $locale)], ['value' => '0', 'label' => $this->translator->trans('common.no', [], 'messages', $locale)]]
                    : $definition->getOptions($locale);
                $options = [];
                foreach ($choices as $choice) {
                    $count = $valueCounts[$choice['value']] ?? 0;
                    if ($count > 0 || \in_array($choice['value'], $selected, true)) {
                        $options[] = $choice + ['count' => $count];
                    }
                }
                if ([] !== $options) {
                    $facets['attributes'][] = [
                        'code' => $code,
                        'label' => $definition->getLabel($locale),
                        'type' => $definition->getType()->value,
                        'options' => $options,
                    ];
                }
            }
        }

        return $facets;
    }

    /**
     * Basisabfrage veröffentlichter Objekte mit allen Filtern außer den ausgeschlossenen.
     *
     * @param list<string> $exclude area|region|topic|year|attr:<code>
     */
    public function base(CatalogQuery $query, string $locale, array $exclude = []): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->from(Item::class, 'i')
            ->andWhere('i.status = :published')->setParameter('published', ItemStatus::Published);

        // feste Filter der Seite (nie ausgeschlossen)
        if (null !== $query->fixedArea) {
            $qb->andWhere('i.area = :fixedArea')->setParameter('fixedArea', $query->fixedArea);
        }
        if (null !== $query->fixedRegion) {
            $qb->andWhere('i.category IN (:fixedRegions)')->setParameter('fixedRegions', $this->tree->descendantIds($query->fixedRegion));
        }
        if (null !== $query->fixedTopic) {
            $qb->andWhere('i.id IN (SELECT fti.id FROM App\Entity\Item fti JOIN fti.topics ftt WHERE ftt.id = :fixedTopic)')
                ->setParameter('fixedTopic', $query->fixedTopic);
        }

        if (!\in_array('area', $exclude, true) && null === $query->fixedArea && null !== $query->area) {
            $qb->andWhere('i.area = :area')->setParameter('area', $query->area);
        }
        if (!\in_array('region', $exclude, true) && [] !== $query->regions) {
            $ids = [];
            foreach ($query->regions as $region) {
                array_push($ids, ...$this->tree->descendantIds($region));
            }
            $qb->andWhere('i.category IN (:regions)')->setParameter('regions', array_values(array_unique($ids)));
        }
        if (!\in_array('topic', $exclude, true) && [] !== $query->topics) {
            $qb->andWhere('i.id IN (SELECT ti.id FROM App\Entity\Item ti JOIN ti.topics tt WHERE tt.id IN (:topics))')
                ->setParameter('topics', $query->topics);
        }
        if (!\in_array('year', $exclude, true)) {
            if (null !== $query->yearFrom) {
                $qb->andWhere('COALESCE(i.yearTo, i.year) >= :yearFrom')->setParameter('yearFrom', $query->yearFrom);
            }
            if (null !== $query->yearTo) {
                $qb->andWhere('i.year <= :yearTo')->setParameter('yearTo', $query->yearTo);
            }
        }

        $n = 0;
        foreach ($query->attributes as $code => $values) {
            if (\in_array('attr:'.$code, $exclude, true)) {
                continue;
            }
            ++$n;
            $qb->andWhere(sprintf(
                'EXISTS (SELECT a%1$d.id FROM App\Entity\ItemAttribute a%1$d JOIN a%1$d.definition d%1$d WHERE a%1$d.item = i AND d%1$d.code = :ac%1$d AND a%1$d.value IN (:av%1$d))',
                $n,
            ))->setParameter('ac'.$n, $code)->setParameter('av'.$n, $values);
        }

        if (null !== $query->q) {
            $this->applyTextSearch($qb, $query->q, $locale);
        }

        return $qb;
    }

    /**
     * Volltextsuche (alle Wörter müssen vorkommen) in Titel, Serie, Beschreibung und Mängeln
     * (aktuelle Sprache und Deutsch), Ländernamen (alle Sprachen), Merkmalswerten und Jahr.
     */
    private function applyTextSearch(QueryBuilder $qb, string $q, string $locale): void
    {
        $words = \array_slice(array_values(array_filter(
            preg_split('/\s+/u', str_replace(['%', '_'], ' ', $q)) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 2 || ctype_digit($w),
        )), 0, 6);
        $qb->setParameter('searchLocales', array_values(array_unique([$locale, Language::SOURCE])));
        foreach ($words as $w => $word) {
            $conditions = [
                sprintf('i.id IN (SELECT IDENTITY(st%1$d.item) FROM App\Entity\ItemTranslation st%1$d WHERE st%1$d.locale IN (:searchLocales) AND (st%1$d.title LIKE :w%1$d OR st%1$d.series LIKE :w%1$d OR st%1$d.description LIKE :w%1$d OR st%1$d.defects LIKE :w%1$d))', $w),
                sprintf('i.category IN (SELECT IDENTITY(sc%1$d.category) FROM App\Entity\CategoryTranslation sc%1$d WHERE sc%1$d.name LIKE :w%1$d)', $w),
                sprintf('i.id IN (SELECT IDENTITY(sa%1$d.item) FROM App\Entity\ItemAttribute sa%1$d WHERE sa%1$d.value LIKE :w%1$d)', $w),
            ];
            if (ctype_digit($word) && 4 === \strlen($word)) {
                $conditions[] = 'i.year = :y'.$w;
                $qb->setParameter('y'.$w, (int) $word);
            }
            $qb->andWhere('('.implode(' OR ', $conditions).')')->setParameter('w'.$w, '%'.$word.'%');
        }
    }

    private function applySort(QueryBuilder $qb, string $sort, string $locale): void
    {
        match ($sort) {
            'year_asc' => $qb->addSelect('CASE WHEN i.year IS NULL THEN 1 ELSE 0 END AS HIDDEN yearNull')
                ->orderBy('yearNull', 'ASC')->addOrderBy('i.year', 'ASC'),
            'year_desc' => $qb->addSelect('CASE WHEN i.year IS NULL THEN 1 ELSE 0 END AS HIDDEN yearNull')
                ->orderBy('yearNull', 'ASC')->addOrderBy('i.year', 'DESC'),
            'title' => $qb
                ->leftJoin('i.translations', 'sortT', 'WITH', 'sortT.locale = :sortLocale')->setParameter('sortLocale', $locale)
                ->leftJoin('i.translations', 'sortD', 'WITH', 'sortD.locale = :sortSource')->setParameter('sortSource', Language::SOURCE)
                ->addSelect('COALESCE(sortT.title, sortD.title) AS HIDDEN sortTitle')
                ->orderBy('sortTitle', 'ASC'),
            default => $qb->orderBy('i.publishedAt', 'DESC'),
        };
        $qb->addOrderBy('i.id', 'DESC');
    }

    /** @return array<int|string, int> */
    private function groupCount(QueryBuilder $qb, string $group, string $count = 'COUNT(i.id)'): array
    {
        $rows = $qb->select($group.' AS k, '.$count.' AS cnt')
            ->groupBy('k')
            ->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            if (null !== $row['k']) {
                $result[$row['k']] = (int) $row['cnt'];
            }
        }

        return $result;
    }
}
