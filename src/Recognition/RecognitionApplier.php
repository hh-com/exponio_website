<?php

namespace App\Recognition;

use App\Entity\AttributeDefinition;
use App\Entity\Category;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\Topic;
use App\Enum\AttributeType;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Repository\CategoryRepository;
use App\Repository\TopicRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Überträgt eine KI-Antwort auf ein Objekt. Fehlende Länder und Themen werden angelegt,
 * ungültige Merkmalswerte verworfen. Danach wartet das Objekt in der Prüf-Warteschlange
 * (oder wird bei aktivierter Auto-Freigabe und hoher Konfidenz direkt veröffentlicht).
 *
 * Alle Texte kommen auf Deutsch; die anderen Sprachen entstehen später über "app:translate".
 * Der Schätzwert wird gespeichert, aber nie automatisch freigegeben.
 */
final class RecognitionApplier
{
    private const MAX_TOPICS = 6;

    /** @var array<string, Category> neu angelegte Länder dieses Durchlaufs */
    private array $newCategories = [];

    /** @var array<string, Topic> */
    private array $newTopics = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly SettingsProvider $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<CollectionArea> $areas
     */
    public function apply(Item $item, array $data, string $model, array $areas): void
    {
        $confidence = is_numeric($data['confidence'] ?? null) ? max(0.0, min(1.0, (float) $data['confidence'])) : null;
        $item->setAiResult($data)
            ->setAiModel(mb_substr($model, 0, 80))
            ->setAiConfidence($confidence)
            ->setRecognizedAt(new \DateTimeImmutable())
            ->setAiError(null);

        foreach ($areas as $area) {
            if ($area->getAiKey() === ($data['area'] ?? null)) {
                $item->setArea($area);
                break;
            }
        }

        if ('' !== $title = $this->str($data['title'] ?? null, 255)) {
            $item->setTitle($title);
        }
        $item->setDescription($this->str($data['description'] ?? null) ?: null);
        $item->setSeries($this->str($data['series'] ?? null, 255) ?: null);
        $item->setDefects($this->str($data['defects'] ?? null) ?: null);
        $item->setMetaDescription($this->str($data['meta_description'] ?? null, 300) ?: null);

        $issueDate = $this->date($data['issue_date'] ?? null);
        $item->setIssueDate($issueDate);
        $year = $this->year($data['year'] ?? null) ?? $issueDate?->format('Y');
        $year = null === $year ? null : (int) $year;
        $yearTo = $this->year($data['year_to'] ?? null);
        $item->setYear($year);
        $item->setYearTo(null !== $year && null !== $yearTo && $yearTo > $year ? $yearTo : null);

        $path = $this->stringList($data['region_path'] ?? []);
        if ([] !== $path) {
            $item->setCategory($this->resolveCategory($path));
        }

        foreach ($item->getTopics()->toArray() as $topic) {
            $item->removeTopic($topic);
        }
        foreach (\array_slice($this->stringList($data['topics'] ?? []), 0, self::MAX_TOPICS) as $name) {
            $item->addTopic($this->resolveTopic(mb_substr($name, 0, 120)));
        }

        $this->applyAttributes($item, \is_array($data['attributes'] ?? null) ? $data['attributes'] : []);
        $this->applyImages($item, \is_array($data['images'] ?? null) ? $data['images'] : []);

        $value = $data['catalog_value_eur'] ?? null;
        $item->setCatalogValue(is_numeric($value) && $value >= 0 ? (float) $value : null);

        $settings = $this->settings->get();
        $publish = $settings->isAutoPublish()
            && true === ($data['is_collectible'] ?? false)
            && null !== $confidence && $confidence >= $settings->getAutoPublishThreshold();
        $item->setStatus($publish ? ItemStatus::Published : ItemStatus::Review);
    }

    public function normalizeValue(AttributeDefinition $definition, string $value): ?string
    {
        $value = trim($value);
        if ('' === $value || 'null' === strtolower($value)) {
            return null;
        }

        return match ($definition->getType()) {
            AttributeType::Text => mb_substr($value, 0, 191),
            AttributeType::Number => is_numeric($n = str_replace(',', '.', $value)) ? rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.') : null,
            AttributeType::Boolean => match (mb_strtolower($value)) {
                '1', 'true', 'ja', 'yes' => '1',
                '0', 'false', 'nein', 'no' => '0',
                default => null,
            },
            AttributeType::Select => $this->matchOption($definition, $value),
        };
    }

    /** @param array<mixed> $attributes */
    private function applyAttributes(Item $item, array $attributes): void
    {
        $area = $item->getArea();
        if (null === $area) {
            return;
        }
        $values = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $definition = $area->getDefinition((string) ($attribute['code'] ?? ''));
            if (null === $definition) {
                continue;
            }
            $normalized = $this->normalizeValue($definition, (string) ($attribute['value'] ?? ''));
            if (null !== $normalized) {
                $values[(string) $definition->getCode()] = $normalized;
            }
        }
        foreach ($area->getAttributeDefinitions() as $definition) {
            $item->setAttributeValue($definition, $values[(string) $definition->getCode()] ?? null);
        }
        $item->pruneForeignAttributes();
    }

    /**
     * Seite (Vorder-/Rückseite/Detail) und Alt-Text je Foto; die Bildnummer beginnt bei 1.
     *
     * @param array<mixed> $images
     */
    private function applyImages(Item $item, array $images): void
    {
        $byPosition = $item->getImages()->getValues();
        foreach ($images as $info) {
            if (!\is_array($info) || !is_numeric($info['index'] ?? null)) {
                continue;
            }
            $image = $byPosition[(int) $info['index'] - 1] ?? null;
            if (null === $image) {
                continue;
            }
            $image->setSide(ImageSide::tryFrom((string) ($info['side'] ?? '')));
            $image->setAlt($this->str($info['alt'] ?? null, 255) ?: null);
        }
    }

    private function matchOption(AttributeDefinition $definition, string $value): ?string
    {
        $needle = mb_strtolower($value);
        foreach ($definition->getOptions() as $option) {
            if ($needle === mb_strtolower($option['value']) || $needle === mb_strtolower($option['label'])) {
                return $option['value'];
            }
        }

        return null;
    }

    /** @param list<string> $path */
    private function resolveCategory(array $path): Category
    {
        $parent = null;
        foreach (\array_slice($path, 0, 5) as $level => $name) {
            $name = mb_substr($name, 0, 150);
            $key = ($parent?->getId() ?? spl_object_id($parent ?? $this)).'|'.mb_strtolower($name);
            $parentIsNew = null !== $parent && null === $parent->getId();
            $category = $this->newCategories[$key]
                ?? ($parentIsNew ? null : $this->categories->findOneByNameUnder($name, $parent))
                // oberste Stufe: auch tiefer liegende Gebiete gleichen Namens akzeptieren (z. B. "DDR")
                ?? (0 === $level ? $this->categories->findOneByName($name) : null);
            if (null === $category) {
                $category = new Category($name, $parent);
                $this->em->persist($category);
                $this->newCategories[$key] = $category;
            }
            $parent = $category;
        }
        \assert(null !== $parent);

        return $parent;
    }

    private function resolveTopic(string $name): Topic
    {
        $key = mb_strtolower($name);
        $topic = $this->newTopics[$key] ?? $this->topics->findOneByName($name);
        if (null === $topic) {
            $topic = new Topic($name);
            $this->em->persist($topic);
            $this->newTopics[$key] = $topic;
        }

        return $topic;
    }

    private function str(mixed $value, ?int $max = null): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $value = trim($value);

        return null === $max ? $value : mb_substr($value, 0, $max);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v): string => $this->str($v), $value), static fn (string $v): bool => '' !== $v));
    }

    private function year(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1000 && (int) $value <= 2200 ? (int) $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
