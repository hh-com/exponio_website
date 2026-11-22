<?php

namespace App\Controller\Admin;

use App\Entity\Topic;
use App\Form\Admin\TranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Topic>
 */
class TopicCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Topic::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Thema')
            ->setEntityLabelInPlural('Themen')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['translations.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield TextField::new('name', 'Name')->setSortable(false)->setColumns(6);
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
}
