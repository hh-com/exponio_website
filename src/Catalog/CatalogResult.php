<?php

namespace App\Catalog;

use App\Entity\Item;

final readonly class CatalogResult
{
    /**
     * @param list<Item>           $items
     * @param array<string, mixed> $facets
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pages,
        public array $facets,
    ) {
    }
}
