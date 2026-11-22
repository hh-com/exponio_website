<?php

namespace App\Controller\Admin;

use App\Entity\CollectionArea;
use App\Form\Admin\AttributeDefinitionType;
use App\Form\Admin\TranslationType;
use App\Repository\ItemRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<CollectionArea>
 */
class CollectionAreaCrudController extends AbstractCrudController
{
    public function __construct(private readonly ItemRepository $items)
    {
    }

    public static function getEntityFqcn(): string
    {
        return CollectionArea::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Objektart')
            ->setEntityLabelInPlural('Objektarten')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['translations.name'])
            ->setHelp(Crud::PAGE_INDEX, 'Objektarten wie Briefmarken, Belege oder Münzen – jede mit eigenen Merkmalen, Filtern und Hinweisen für die KI. Aktive Objektarten erscheinen im Menü „Katalog“.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Allgemein', 'fa fa-circle-info');
        yield TextField::new('name', 'Name')->setSortable(false)->setColumns(6);
        yield ChoiceField::new('icon', 'Symbol')->setChoices([
            'Briefmarke' => 'stamp', 'Brief/Beleg' => 'envelope', 'Münze' => 'coin',
            'Banknote' => 'banknote', 'Postkarte' => 'postcard', 'Sonstiges' => 'box',
        ])->setColumns(4);
        yield IntegerField::new('position', 'Reihenfolge')->setColumns(4);
        yield BooleanField::new('active', 'Aktiv')->renderAsSwitch()->setColumns(4);
        yield TextareaField::new('description', 'Beschreibung')->setColumns(12)->hideOnIndex()
            ->setHelp('Wird auf der Seite der Objektart angezeigt und hilft der KI bei der Zuordnung.');

        yield FormField::addTab('Merkmale', 'fa fa-list-check');
        yield CollectionField::new('attributeDefinitions', 'Merkmale')
            ->setEntryType(AttributeDefinitionType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Merkmale erscheinen im Objekt-Formular, auf der Detailseite und – wenn markiert – als Filter. Die KI füllt sie anhand von Code, Bezeichnung, Optionen und Hinweis.');

        yield FormField::addTab('Übersetzungen', 'fa fa-language');
        yield CollectionField::new('otherTranslations', 'Übersetzungen')
            ->setEntryType(TranslationType::class)
            ->setEntryIsComplex()
            ->allowAdd(false)
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyWhenUpdating();
    }

    public function configureActions(Actions $actions): Actions
    {
        // Objektarten mit Objekten nicht löschen (Objekte würden ihre Objektart verlieren)
        return $actions->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action): Action => $action->displayIf(
            fn (CollectionArea $area): bool => 0 === $this->items->count(['area' => $area]),
        ));
    }
}
