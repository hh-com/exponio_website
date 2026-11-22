<?php

namespace App\Admin\Filter;

use App\Entity\Language;
use App\Enum\DuplicateStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;

/**
 * Ja/Nein-Filter der Objektliste, die sich nicht direkt auf eine Spalte beziehen:
 * „Hat Mängel“, „Übersetzung fehlt“, „Mehrfach hochgeladen“ und „Mögliches Duplikat“.
 */
final class ItemConditionFilter implements FilterInterface
{
    use FilterTrait;

    public const HAS_DEFECTS = 'hasDefects';
    public const MISSING_TRANSLATION = 'missingTranslation';
    public const MULTIPLE_UPLOADS = 'multipleUploads';
    public const POSSIBLE_DUPLICATE = 'possibleDuplicate';

    private int $languageCount = 1;

    public static function new(string $condition, string $label, int $languageCount = 1): self
    {
        $filter = (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($condition)
            ->setLabel($label)
            ->setFormType(BooleanFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
        $filter->languageCount = $languageCount;

        return $filter;
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $alias = $filterDataDto->getEntityAlias();
        $not = $filterDataDto->getValue() ? '' : 'NOT ';

        if (self::MULTIPLE_UPLOADS === $filterDataDto->getProperty()) {
            $queryBuilder->andWhere(sprintf('%s.uploadCount %s 1', $alias, $filterDataDto->getValue() ? '>' : '<='));

            return;
        }

        if (self::POSSIBLE_DUPLICATE === $filterDataDto->getProperty()) {
            $queryBuilder->andWhere(sprintf($filterDataDto->getValue() ? '%1$s.duplicateStatus = :fdPossible' : '(%1$s.duplicateStatus IS NULL OR %1$s.duplicateStatus <> :fdPossible)', $alias))
                ->setParameter('fdPossible', DuplicateStatus::Possible);

            return;
        }

        if (self::HAS_DEFECTS === $filterDataDto->getProperty()) {
            $queryBuilder->andWhere(sprintf('%s.id %sIN (SELECT IDENTITY(fd.item) FROM App\Entity\ItemTranslation fd WHERE fd.locale = :fdSource AND fd.defects IS NOT NULL)', $alias, $not))
                ->setParameter('fdSource', Language::SOURCE);

            return;
        }

        // Anzahl vorhandener Übersetzungen (mit Titel) kleiner als Anzahl freigeschalteter Sprachen
        $condition = sprintf('(SELECT COUNT(ft.id) FROM App\Entity\ItemTranslation ft WHERE ft.item = %s AND ft.title IS NOT NULL) < :ftLanguages', $alias);
        $queryBuilder->andWhere($filterDataDto->getValue() ? $condition : 'NOT ('.$condition.')')
            ->setParameter('ftLanguages', $this->languageCount);
    }
}
