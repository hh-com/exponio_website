<?php

namespace App\Repository;

use App\Entity\Item;
use App\Enum\DuplicateStatus;
use App\Enum\ItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    public function findPublished(int $id): ?Item
    {
        return $this->findOneBy(['id' => $id, 'status' => ItemStatus::Published]);
    }

    /** @return list<Item> */
    public function findLatest(int $limit, bool $featuredOnly = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Published)
            ->orderBy('i.publishedAt', 'DESC')->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        if ($featuredOnly) {
            $qb->andWhere('i.featured = true');
        }

        $items = $qb->getQuery()->getResult();
        $this->preload($items);

        return $items;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<Item>
     */
    public function findPublishedByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Published)
            ->andWhere('i.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        $this->preload($items);
        // Reihenfolge der übergebenen IDs beibehalten
        $position = array_flip($ids);
        usort($items, static fn (Item $a, Item $b): int => ($position[(int) $a->getId()] ?? 0) <=> ($position[(int) $b->getId()] ?? 0));

        return $items;
    }

    /**
     * Lädt Texte, Bilder, Land und Merkmale mehrerer Objekte in wenigen Abfragen (statt N+1).
     * Listen, die keine Merkmale zeigen, lassen sie mit $withAttributes = false weg – das ist die größte Abfrage.
     *
     * @param list<Item> $items
     */
    public function preload(array $items, bool $withAttributes = true): void
    {
        if ([] === $items) {
            return;
        }
        $ids = array_map(static fn (Item $i): ?int => $i->getId(), $items);
        $em = $this->getEntityManager();
        $queries = [
            'SELECT i, t FROM App\Entity\Item i LEFT JOIN i.translations t WHERE i.id IN (:ids)',
            'SELECT i, img, imgt FROM App\Entity\Item i LEFT JOIN i.images img LEFT JOIN img.translations imgt WHERE i.id IN (:ids)',
            'SELECT i, c, ct FROM App\Entity\Item i LEFT JOIN i.category c LEFT JOIN c.translations ct WHERE i.id IN (:ids)',
        ];
        if ($withAttributes) {
            $queries[] = 'SELECT i, a, d, dt FROM App\Entity\Item i LEFT JOIN i.attributeValues a LEFT JOIN a.definition d LEFT JOIN d.translations dt WHERE i.id IN (:ids)';
        }
        foreach ($queries as $dql) {
            $em->createQuery($dql)->setParameter('ids', $ids)->getResult();
        }
    }

    /**
     * Wie preload() (ohne Merkmale) für alle Objekte, die schon geladen sind – für die Liste im Backend,
     * deren Abfrage EasyAdmin selbst ausführt: Texte, Bilder und Land kommen dann in drei Abfragen statt je Zeile.
     */
    public function preloadLoaded(): void
    {
        $uow = $this->getEntityManager()->getUnitOfWork();
        $items = [];
        foreach ($uow->getIdentityMap()[Item::class] ?? [] as $entity) {
            if ($entity instanceof Item && !$uow->isUninitializedObject($entity)) {
                $items[] = $entity;
            }
        }
        $this->preload($items, withAttributes: false);
    }

    /** @return array<string, int> Status => Anzahl */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS cnt')
            ->groupBy('i.status')
            ->getQuery()->getArrayResult();
        $counts = array_fill_keys(array_map(static fn (ItemStatus $s): string => $s->value, ItemStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof ItemStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Entwürfe und Objekte zur Prüfung, außerdem mögliche Duplikate und Objekte mit vorgeschlagenen Bildern
     * (auch wenn sie schon veröffentlicht sind), älteste zuerst.
     *
     * @return list<Item>
     */
    public function findReviewQueue(int $limit = 500): array
    {
        $items = $this->createQueryBuilder('i')
            ->andWhere('(i.status IN (:statuses) OR i.duplicateStatus = :possible OR i.id IN (SELECT IDENTITY(q.item) FROM App\Entity\ItemUpload q WHERE q.proposal = true))')
            ->setParameter('statuses', [ItemStatus::Draft, ItemStatus::Review])
            ->setParameter('possible', DuplicateStatus::Possible)
            ->orderBy('i.createdAt', 'ASC')->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        // die Warteschlange zeigt nur Titel und Vorschaubild
        $this->preload($items, withAttributes: false);

        return $items;
    }

    /** @return list<Item> Entwürfe ohne (erfolgreiche) Erkennung */
    public function findUnrecognized(int $limit, bool $includeFailed = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Draft)
            ->andWhere('i.recognizedAt IS NULL')
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults($limit);
        if (!$includeFailed) {
            $qb->andWhere('i.aiError IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Item> erkannte, frisch importierte Objekte, deren Duplikatprüfung noch aussteht */
    public function findPendingDuplicateChecks(int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.duplicateStatus = :pending')->setParameter('pending', DuplicateStatus::Pending)
            ->andWhere('i.recognizedAt IS NOT NULL')
            ->andWhere('i.aiError IS NULL')
            ->orderBy('i.createdAt', 'ASC')->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Bestand für app:duplicates:scan: noch nie geprüfte Objekte (mit $recheck auch die als „kein Duplikat“ geprüften),
     * älteste zuerst. Frisch importierte (pending) prüft app:scan.
     *
     * @return list<Item>
     */
    public function findForDuplicateScan(bool $recheck, ?int $id = null): array
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.id', 'ASC');
        if (null !== $id) {
            return $qb->andWhere('i.id = :id')->setParameter('id', $id)->getQuery()->getResult();
        }
        if ($recheck) {
            $qb->andWhere('(i.duplicateStatus IS NULL OR i.duplicateStatus = :unique)')->setParameter('unique', DuplicateStatus::Unique);
        } else {
            $qb->andWhere('i.duplicateStatus IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countPossibleDuplicates(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.duplicateStatus = :possible')->setParameter('possible', DuplicateStatus::Possible)
            ->getQuery()->getSingleScalarResult();
    }

    public function countMultipleUploads(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.uploadCount > 1')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Item> am häufigsten hochgeladene Objekte (mindestens zweimal) */
    public function findMostUploaded(int $limit): array
    {
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.uploadCount > 1')
            ->orderBy('i.uploadCount', 'DESC')->addOrderBy('i.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        $this->preload($items, withAttributes: false);

        return $items;
    }

    /** @return array<int, int> Objektart-ID => Anzahl veröffentlichter Objekte */
    public function countPublishedByArea(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.area) AS id, COUNT(i.id) AS cnt')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Published)
            ->groupBy('i.area')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'cnt' => (int) $r['cnt']], $rows), 'cnt', 'id');
    }

    /** @return array<int, int> Gebiet-ID => Anzahl (nur direkt zugeordnet) */
    public function countPublishedByCategory(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.category) AS id, COUNT(i.id) AS cnt')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Published)
            ->andWhere('i.category IS NOT NULL')
            ->groupBy('i.category')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'cnt' => (int) $r['cnt']], $rows), 'cnt', 'id');
    }

    /** @return array<int, int> Thema-ID => Anzahl */
    public function countPublishedByTopic(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT t.id AS id, COUNT(i.id) AS cnt FROM App\Entity\Item i JOIN i.topics t WHERE i.status = :status GROUP BY t.id'
        )->setParameter('status', ItemStatus::Published)->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'cnt' => (int) $r['cnt']], $rows), 'cnt', 'id');
    }

    /** @return array{count: int, minYear: ?int, maxYear: ?int} */
    public function publishedStats(): array
    {
        $row = $this->createQueryBuilder('i')
            ->select('COUNT(i.id) AS cnt, MIN(i.year) AS minYear, MAX(i.year) AS maxYear')
            ->andWhere('i.status = :status')->setParameter('status', ItemStatus::Published)
            ->getQuery()->getSingleResult();

        return [
            'count' => (int) $row['cnt'],
            'minYear' => null !== $row['minYear'] ? (int) $row['minYear'] : null,
            'maxYear' => null !== $row['maxYear'] ? (int) $row['maxYear'] : null,
        ];
    }
}
