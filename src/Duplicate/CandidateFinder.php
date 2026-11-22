<?php

namespace App\Duplicate;

use App\Entity\Category;
use App\Entity\Item;
use App\Enum\DuplicateStatus;
use App\Media\ImageHasher;
use App\Media\MediaStorage;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stufe 1 der Duplikat-Erkennung: günstige Vorauswahl ohne KI.
 *
 * Verglichen werden Objektart, Land, Jahr, Katalognummern (Michel, KM#, Pick …), Nennwert und Serie aus der
 * KI-Bestimmung sowie die Wahrnehmungs-Hashes der Fotos (auch gedreht und auch frühere Fotos eines Objekts).
 * Die besten Kandidaten (höchstens drei) vergleicht danach Gemini in einem Aufruf.
 */
final class CandidateFinder
{
    public const MAX_CANDIDATES = 3;
    /** Hamming-Abstand (von 64 Bit), bis zu dem zwei Fotos als nahezu gleich gelten */
    public const HASH_STRONG = 6;
    public const HASH_MEDIUM = 12;
    public const HASH_LOOSE = 18;
    /** Merkmale mit Katalognummern: gleiche Nummer ist ein sehr starkes Signal */
    public const CATALOG_CODES = ['michel', 'catalog_ref', 'pick', 'catalog_numbers', 'stationery_number'];
    /** Merkmale mit dem Nennwert */
    public const VALUE_CODES = ['face_value', 'denomination'];

    private const MIN_SCORE = 4;
    private const MAX_PREFILTER = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRepository $items,
        private readonly MediaStorage $media,
    ) {
    }

    /**
     * @param bool $olderOnly nur Objekte mit kleinerer Nummer (Bestandsprüfung: jedes Paar nur einmal)
     *
     * @return list<Candidate> wahrscheinlichste zuerst
     */
    public function find(Item $item, bool $olderOnly = false, int $limit = self::MAX_CANDIDATES): array
    {
        $distances = $this->hashDistances($item, $olderOnly);
        $ids = array_keys(array_filter($distances, static fn (int $distance): bool => $distance <= self::HASH_LOOSE));
        $ids = array_values(array_unique([...$ids, ...$this->prefilter($item, $olderOnly)]));
        if ([] === $ids) {
            return [];
        }

        $candidates = [];
        foreach ($this->load($ids) as $other) {
            $candidate = $this->score($item, $other, $distances[(int) $other->getId()] ?? null);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }
        // nahezu gleiche Fotos zuerst, dann nach Punkten und Bildabstand
        usort($candidates, static fn (Candidate $a, Candidate $b): int => [$b->hasSamePhoto(), $b->score, $a->hashDistance ?? 64] <=> [$a->hasSamePhoto(), $a->score, $b->hashDistance ?? 64]);

        return \array_slice($candidates, 0, $limit);
    }

    /**
     * Katalognummern vergleichbar machen: „Mi.-Nr. 213 A“ → 213a, „KM# 45“ → 45, „Sn:US 123; Yt:FR 45“ → snus123, ytfr45.
     *
     * @return list<string>
     */
    public static function normalizeCatalog(string $value): array
    {
        $result = [];
        foreach (preg_split('/[;,\/]+/', mb_strtolower($value)) ?: [] as $part) {
            $part = (string) preg_replace('/\b(michel|mi|nr|no|km|pick|p)\b|[\s.#:\-]+/u', '', $part);
            if ('' !== $part && !\in_array($part, $result, true)) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * Nennwerte vergleichbar machen: nur die Zahlen („10 Pf“ und „10 Pfennig“ → 10).
     *
     * @return list<string>
     */
    public static function normalizeValue(string $value): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $value, $matches);
        $numbers = array_map(static function (string $number): string {
            $number = str_replace(',', '.', $number);
            $number = str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : ltrim($number, '0');

            return '' === $number ? '0' : $number;
        }, $matches[0]);

        return [] === $numbers ? self::normalizeCatalog($value) : [implode('/', $numbers)];
    }

    /** @return array<int, int> Objekt-ID => kleinster Abstand zwischen einem neuen Foto und einem Foto dieses Objekts */
    private function hashDistances(Item $item, bool $olderOnly): array
    {
        $own = [];
        foreach ($item->getImages() as $image) {
            $hashes = null === $image->getPath() ? [] : $this->media->rotatedHashes($image->getPath());
            if ([] === $hashes && null !== $image->getHash()) {
                $hashes = [$image->getHash()];
            }
            array_push($own, ...$hashes);
        }
        if ([] === $own) {
            return [];
        }

        // auch ausgeblendete (frühere) Fotos zählen: sie gehören über ihren Upload zum Objekt
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(img.item) AS itemId, IDENTITY(u.item) AS uploadItemId, img.hash AS hash FROM App\Entity\ItemImage img LEFT JOIN img.upload u WHERE img.hash IS NOT NULL'
        )->getArrayResult();

        $distances = [];
        foreach ($rows as $row) {
            $id = (int) ($row['itemId'] ?? $row['uploadItemId'] ?? 0);
            if (0 === $id || $id === $item->getId() || ($olderOnly && $id > (int) $item->getId())) {
                continue;
            }
            foreach ($own as $hash) {
                $distance = ImageHasher::distance($hash, (string) $row['hash']);
                if ($distance < ($distances[$id] ?? 65)) {
                    $distances[$id] = $distance;
                }
            }
        }

        return $distances;
    }

    /** @return list<int> Objekte mit passendem Land, Jahr oder gleicher Katalognummer */
    private function prefilter(Item $item, bool $olderOnly): array
    {
        $qb = $this->items->createQueryBuilder('i')
            ->select('i.id')
            ->leftJoin('i.category', 'c')
            ->andWhere('i.id <> :id')->setParameter('id', (int) $item->getId())
            ->setMaxResults(self::MAX_PREFILTER);
        if (null !== $item->getArea()) {
            $qb->andWhere('(i.area = :area OR i.area IS NULL)')->setParameter('area', $item->getArea());
        }
        if ($olderOnly) {
            $qb->andWhere('i.id < :id');
        }

        $conditions = [];
        $category = $item->getCategory();
        if (null !== $category) {
            $conditions[] = 'i.category IN (:categories) OR c.parent = :category';
            $qb->setParameter('categories', array_map(static fn (Category $c): ?int => $c->getId(), $this->lineage($category)))
                ->setParameter('category', $category);
        }
        if (null !== $item->getYear()) {
            $conditions[] = 'i.year BETWEEN :yearFrom AND :yearTo';
            $qb->setParameter('yearFrom', $item->getYear() - 1)->setParameter('yearTo', $item->getYear() + 1);
        }
        $catalog = array_values(array_filter(array_map(static fn (string $code): ?string => $item->getAttributeValue($code), self::CATALOG_CODES)));
        if ([] !== $catalog) {
            $conditions[] = 'i.id IN (SELECT IDENTITY(pa.item) FROM App\Entity\ItemAttribute pa JOIN pa.definition pd WHERE pd.code IN (:codes) AND pa.value IN (:values))';
            $qb->setParameter('codes', self::CATALOG_CODES)->setParameter('values', $catalog);
        }
        if ([] === $conditions) {
            return [];
        }
        $qb->andWhere('(('.implode(') OR (', $conditions).'))');

        return array_map(static fn (array $row): int => (int) $row['id'], $qb->getQuery()->getArrayResult());
    }

    /**
     * Frisch importierte, noch ungeprüfte Objekte sind keine Kandidaten – sonst würde ein älteres Objekt
     * einem neueren zugeordnet, das gleichzeitig hochgeladen wurde.
     *
     * @param list<int> $ids
     *
     * @return list<Item>
     */
    private function load(array $ids): array
    {
        /** @var list<Item> $items */
        $items = $this->items->createQueryBuilder('i')
            ->andWhere('i.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('(i.duplicateStatus IS NULL OR i.duplicateStatus <> :pending)')->setParameter('pending', DuplicateStatus::Pending)
            ->getQuery()->getResult();
        $this->items->preload($items);

        return $items;
    }

    private function score(Item $new, Item $other, ?int $hashDistance): ?Candidate
    {
        $samePhoto = null !== $hashDistance && $hashDistance <= self::HASH_STRONG;
        if (null !== $new->getArea() && null !== $other->getArea() && $new->getArea() !== $other->getArea() && !$samePhoto) {
            return null;
        }
        $score = 0;
        $reasons = [];

        $catalog = $this->compareCodes($new, $other, self::CATALOG_CODES, false);
        if (true === $catalog) {
            $score += 5;
            $reasons[] = 'gleiche Katalognummer';
        } elseif (false === $catalog) {
            $score -= 4;
        }

        $a = $new->getCategory();
        $b = $other->getCategory();
        if (null !== $a && null !== $b) {
            if ($a === $b) {
                $score += 3;
                $reasons[] = 'gleiches Land';
            } elseif (\in_array($a, $this->lineage($b), true) || \in_array($b, $this->lineage($a), true)) {
                $score += 2;
                $reasons[] = 'verwandtes Gebiet';
            } else {
                $score -= 3;
            }
        }

        if (null !== $new->getYear() && null !== $other->getYear()) {
            $difference = abs($new->getYear() - $other->getYear());
            if (0 === $difference) {
                $score += 2;
                $reasons[] = 'gleiches Jahr';
            } elseif (1 === $difference) {
                ++$score;
                $reasons[] = 'Jahr ±1';
            } else {
                $score -= 3;
            }
        }

        $value = $this->compareCodes($new, $other, self::VALUE_CODES, true);
        if (true === $value) {
            $score += 2;
            $reasons[] = 'gleicher Nennwert';
        } elseif (false === $value) {
            $score -= 2;
        }

        $series = self::normalizeText((string) $new->getSeries());
        if ('' !== $series && $series === self::normalizeText((string) $other->getSeries())) {
            $score += 2;
            $reasons[] = 'gleiche Serie';
        }

        if (null !== $hashDistance) {
            if ($samePhoto) {
                $score += 6;
                $reasons[] = sprintf('Foto nahezu gleich (Abstand %d/64)', $hashDistance);
            } elseif ($hashDistance <= self::HASH_MEDIUM) {
                $score += 3;
                $reasons[] = sprintf('Foto ähnlich (Abstand %d/64)', $hashDistance);
            } elseif ($hashDistance <= self::HASH_LOOSE) {
                ++$score;
            }
        }

        if ($score < self::MIN_SCORE && !$samePhoto) {
            return null;
        }

        return new Candidate($other, $score, $reasons, $hashDistance, true === $catalog);
    }

    /**
     * true = mindestens ein Merkmal stimmt überein, false = alle vergleichbaren Merkmale weichen ab, null = nicht vergleichbar.
     *
     * @param list<string> $codes
     */
    private function compareCodes(Item $a, Item $b, array $codes, bool $numeric): ?bool
    {
        $result = null;
        foreach ($codes as $code) {
            $valueA = $a->getAttributeValue($code);
            $valueB = $b->getAttributeValue($code);
            if (null === $valueA || null === $valueB) {
                continue;
            }
            $normalizedA = $numeric ? self::normalizeValue($valueA) : self::normalizeCatalog($valueA);
            $normalizedB = $numeric ? self::normalizeValue($valueB) : self::normalizeCatalog($valueB);
            if ([] !== array_intersect($normalizedA, $normalizedB)) {
                return true;
            }
            $result = false;
        }

        return $result;
    }

    /** @return list<Category> das Gebiet selbst und alle übergeordneten */
    private function lineage(Category $category): array
    {
        $lineage = [];
        for ($current = $category; null !== $current && \count($lineage) < 10; $current = $current->getParent()) {
            $lineage[] = $current;
        }

        return $lineage;
    }

    private static function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/[\s"„“”\'.,]+/u', ' ', mb_strtolower($text)));
    }
}
