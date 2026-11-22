<?php

namespace App\Catalog;

use Symfony\Component\HttpFoundation\Request;

/**
 * Filter- und Sortierzustand einer Katalogseite.
 *
 * "fixed*" sind die durch die Seite vorgegebenen Filter (Objektart-, Länder- oder Themenseite),
 * die übrigen Felder kommen aus der URL und sind im Filter-Panel änderbar.
 */
final class CatalogQuery
{
    public const SORTS = ['new', 'year_asc', 'year_desc', 'title'];

    public ?string $q = null;
    public ?int $area = null;
    /** @var list<int> */
    public array $regions = [];
    /** @var list<int> */
    public array $topics = [];
    public ?int $yearFrom = null;
    public ?int $yearTo = null;
    /** @var array<string, list<string>> */
    public array $attributes = [];
    public string $sort = 'new';
    public int $page = 1;

    public ?int $fixedArea = null;
    public ?int $fixedRegion = null;
    public ?int $fixedTopic = null;

    public static function fromRequest(Request $request): self
    {
        $query = new self();
        $q = trim((string) $request->query->get('q', ''));
        $query->q = '' === $q ? null : mb_substr($q, 0, 100);
        $query->area = self::intOrNull($request->query->get('area'));
        $query->regions = self::intList($request->query->get('region'));
        $query->topics = self::intList($request->query->get('topic'));
        $query->yearFrom = self::intOrNull($request->query->get('from'));
        $query->yearTo = self::intOrNull($request->query->get('to'));

        $attributes = $request->query->all()['attr'] ?? [];
        if (\is_array($attributes)) {
            foreach ($attributes as $code => $values) {
                if (\is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,49}$/', $code) && \is_string($values)) {
                    $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $values)), static fn (string $v): bool => '' !== $v)));
                    if ([] !== $list) {
                        $query->attributes[$code] = \array_slice($list, 0, 20);
                    }
                }
            }
        }

        $sort = (string) $request->query->get('sort', 'new');
        $query->sort = \in_array($sort, self::SORTS, true) ? $sort : 'new';
        $query->page = max(1, min(1000, (int) $request->query->get('page', 1)));

        return $query;
    }

    public function effectiveArea(): ?int
    {
        return $this->fixedArea ?? $this->area;
    }

    /**
     * URL-Parameter (ohne feste Filter und ohne Standardwerte).
     *
     * @return array<string, mixed>
     */
    public function toParams(bool $withPage = true): array
    {
        $params = array_filter([
            'q' => $this->q,
            'area' => null === $this->fixedArea ? $this->area : null,
            'region' => [] === $this->regions ? null : implode(',', $this->regions),
            'topic' => [] === $this->topics ? null : implode(',', $this->topics),
            'from' => $this->yearFrom,
            'to' => $this->yearTo,
            'sort' => 'new' === $this->sort ? null : $this->sort,
            'page' => $withPage && $this->page > 1 ? $this->page : null,
        ], static fn ($v): bool => null !== $v);
        foreach ($this->attributes as $code => $values) {
            $params['attr'][$code] = implode(',', $values);
        }

        return $params;
    }

    public function withPage(int $page): self
    {
        $clone = clone $this;
        $clone->page = $page;

        return $clone;
    }

    public function hasUserFilters(): bool
    {
        return null !== $this->q || (null !== $this->area && null === $this->fixedArea) || [] !== $this->regions || [] !== $this->topics
            || null !== $this->yearFrom || null !== $this->yearTo || [] !== $this->attributes;
    }

    /**
     * Zustand für das Vue-Filterpanel.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q ?? '',
            'area' => $this->area,
            'regions' => $this->regions,
            'topics' => $this->topics,
            'from' => $this->yearFrom,
            'to' => $this->yearTo,
            'attr' => (object) $this->attributes,
            'sort' => $this->sort,
            'page' => $this->page,
            'fixed' => ['area' => $this->fixedArea, 'region' => $this->fixedRegion, 'topic' => $this->fixedTopic],
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }
        $ids = array_map('intval', array_filter(explode(',', $value), static fn (string $v): bool => ctype_digit($v)));

        return \array_slice(array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0))), 0, 50);
    }
}
