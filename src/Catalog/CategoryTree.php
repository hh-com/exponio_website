<?php

namespace App\Catalog;

use App\Cache\ContentCache;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ItemRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Baum der Länder und Gebiete im Speicher (eine Abfrage pro Request): Kinder, Nachfahren, Zählungen.
 */
final class CategoryTree implements ResetInterface
{
    /** @var array<int, Category>|null */
    private ?array $nodes = null;

    /** @var array<int, list<int>> parentId (0 = Wurzel) => Kind-IDs */
    private array $children = [];

    /** @var array<int, int>|null */
    private ?array $publishedCounts = null;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ItemRepository $items,
        private readonly ContentCache $cache,
    ) {
    }

    public function get(int $id): ?Category
    {
        return $this->nodes()[$id] ?? null;
    }

    /** @return list<Category> */
    public function roots(): array
    {
        return $this->childrenOf(null);
    }

    /** @return list<Category> */
    public function childrenOf(?int $id): array
    {
        $nodes = $this->nodes();

        return array_map(static fn (int $childId): Category => $nodes[$childId], $this->children[$id ?? 0] ?? []);
    }

    /** @return list<int> die ID selbst und alle Nachfahren */
    public function descendantIds(int $id): array
    {
        $this->nodes();
        $result = [];
        $stack = [$id];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (isset($result[$current])) {
                continue;
            }
            $result[$current] = true;
            foreach ($this->children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($result);
    }

    /**
     * Summiert direkte Zählungen (Gebiet-ID => Anzahl) über alle Nachfahren auf.
     *
     * @param array<int|string, int> $direct
     *
     * @return array<int, int>
     */
    public function aggregate(array $direct): array
    {
        $totals = [];
        foreach ($this->nodes() as $id => $category) {
            $sum = 0;
            foreach ($this->descendantIds($id) as $descendant) {
                $sum += $direct[$descendant] ?? 0;
            }
            $totals[$id] = $sum;
        }

        return $totals;
    }

    /** @return array<int, int> veröffentlichte Objekte je Gebiet inkl. Untergebiete */
    public function publishedCounts(): array
    {
        return $this->publishedCounts ??= $this->cache->get('counts.categories', fn (): array => $this->aggregate($this->items->countPublishedByCategory()));
    }

    /**
     * Baum als Array für JSON/Twig, nur Knoten mit Treffern.
     *
     * @param array<int, int> $counts aggregierte Zählungen
     *
     * @return list<array{id: int, label: string, count: int, children: list<mixed>}>
     */
    public function toFacetTree(array $counts, string $locale, ?int $rootId = null, int $maxDepth = 3): array
    {
        $build = function (?int $parentId, int $depth) use (&$build, $counts, $locale, $maxDepth): array {
            $nodes = [];
            foreach ($this->childrenOf($parentId) as $category) {
                $count = $counts[$category->getId()] ?? 0;
                if ($count <= 0) {
                    continue;
                }
                $nodes[] = [
                    'id' => (int) $category->getId(),
                    'label' => $category->getName($locale),
                    'count' => $count,
                    'children' => $depth < $maxDepth ? $build($category->getId(), $depth + 1) : [],
                ];
            }

            return $nodes;
        };

        return $build($rootId, 1);
    }

    /** @return array<int, Category> */
    private function nodes(): array
    {
        if (null !== $this->nodes) {
            return $this->nodes;
        }
        $this->nodes = [];
        $this->children = [];
        foreach ($this->categories->findAllOrdered(activeOnly: true) as $category) {
            $this->nodes[(int) $category->getId()] = $category;
        }
        foreach ($this->nodes as $id => $category) {
            $parentId = $category->getParent()?->getId();
            // Kinder inaktiver Eltern hängen an der Wurzel
            $key = (null !== $parentId && isset($this->nodes[$parentId])) ? $parentId : 0;
            $this->children[$key][] = $id;
        }

        return $this->nodes;
    }

    public function reset(): void
    {
        $this->nodes = null;
        $this->children = [];
        $this->publishedCounts = null;
    }
}
