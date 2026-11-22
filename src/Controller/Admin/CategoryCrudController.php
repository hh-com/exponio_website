<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\Admin\TranslationType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Category>
 */
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Land / Gebiet')
            ->setEntityLabelInPlural('Länder & Gebiete')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['translations.name'])
            ->setHelp(Crud::PAGE_INDEX, 'Länder, Regionen und Epochen als Baum (z. B. Deutschland › DDR). Auf der Website heißt der Bereich „Länder“. Die KI legt fehlende Einträge automatisch an.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield TextField::new('pathLabel', 'Pfad')->onlyOnIndex()->setVirtual(true);
        yield TextField::new('name', 'Name')->setColumns(6)->hideOnIndex();
        yield AssociationField::new('parent', 'Übergeordnet')
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb->leftJoin('entity.translations', 'pt')->addSelect('pt')->orderBy('entity.position', 'ASC'))
            ->setColumns(6)
            ->hideOnIndex();
        yield IntegerField::new('position', 'Reihenfolge')->setColumns(3);
        yield BooleanField::new('active', 'Aktiv')->renderAsSwitch()->setColumns(3);
        yield TextareaField::new('description', 'Beschreibung')->setColumns(12)->hideOnIndex();
        yield CollectionField::new('otherTranslations', 'Übersetzungen')
            ->setEntryType(TranslationType::class)
            ->setEntryIsComplex()
            ->allowAdd(false)
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyWhenUpdating();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('parent')->add('active');
    }
}
