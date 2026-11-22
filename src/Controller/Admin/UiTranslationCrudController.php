<?php

namespace App\Controller\Admin;

use App\Entity\UiTranslation;
use App\Enum\TranslationOrigin;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Von der KI übersetzte Oberflächentexte (Menü, Buttons, Hinweise) – korrigierbar.
 * Deutsch und Englisch liegen als Dateien im Repository (translations/messages.*.yaml).
 *
 * @extends AbstractCrudController<UiTranslation>
 */
class UiTranslationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UiTranslation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Oberflächentext')
            ->setEntityLabelInPlural('Oberflächentexte')
            ->setDefaultSort(['locale' => 'ASC', 'messageKey' => 'ASC'])
            ->setSearchFields(['messageKey', 'text'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Texte der Website-Oberfläche in den weiteren Sprachen. Korrekturen bleiben erhalten, bis sich der deutsche Text ändert. Gelöschte Einträge übersetzt die KI neu.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('locale', 'Sprache')->setDisabled();
        yield TextField::new('messageKey', 'Schlüssel')->setDisabled();
        yield TextareaField::new('text', 'Text')->setNumOfRows(3);
        yield Field::new('originLabel', 'Herkunft')->onlyOnIndex()->setTemplatePath(AdminTemplates::COMPUTED)
            ->formatValue(static fn ($value, UiTranslation $t): string => $t->getOrigin()->label());
        yield DateTimeField::new('updatedAt', 'Geändert')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('locale')->add('origin');
    }

    /** @return list<string> */
    public static function origins(): array
    {
        return array_map(static fn (TranslationOrigin $o): string => $o->value, TranslationOrigin::cases());
    }
}
