<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Form\Admin\TranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Page>
 */
class PageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Page::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Seite')
            ->setEntityLabelInPlural('Seiten')
            ->setDefaultSort(['position' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Impressum, Datenschutz, Barrierefreiheit und weitere Inhaltsseiten. Welche Seite die Datenschutzerklärung ist, legst du unter Einstellungen fest.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titel')->setSortable(false)->setColumns(6);
        yield IntegerField::new('position', 'Reihenfolge')->setColumns(3);
        yield BooleanField::new('showInFooter', 'Im Fußbereich verlinken')->renderAsSwitch()->setColumns(3);
        yield BooleanField::new('active', 'Aktiv')->renderAsSwitch()->setColumns(3);
        yield FormField::addFieldset('Inhalt');
        yield TextEditorField::new('content', false)->hideOnIndex();
        yield FormField::addFieldset('Übersetzungen');
        yield CollectionField::new('otherTranslations', false)
            ->setEntryType(TranslationType::class)
            ->setEntryIsComplex()
            ->allowAdd(false)
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyWhenUpdating()
            ->setHelp('Die KI übersetzt Seiten automatisch. Rechtstexte (Impressum, Datenschutz) bitte in jeder Sprache prüfen.');
    }
}
