<?php

namespace App\Controller\Admin;

use App\Entity\Inquiry;
use App\Enum\InquiryStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * @extends AbstractCrudController<Inquiry>
 */
class InquiryCrudController extends AbstractCrudController
{
    private const STATUS = ['Neu' => InquiryStatus::New, 'Beantwortet' => InquiryStatus::Answered, 'Erledigt' => InquiryStatus::Closed];

    public static function getEntityFqcn(): string
    {
        return Inquiry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Nachricht')
            ->setEntityLabelInPlural('Nachrichten')
            ->setHelp(Crud::PAGE_INDEX, 'Nachrichten aus dem Kontaktformular. Sie werden nach der unter Einstellungen festgelegten Frist automatisch gelöscht (app:privacy:cleanup).')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'email', 'message'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Eingang')->hideOnForm();
        yield ChoiceField::new('status', 'Status')
            ->setChoices(self::STATUS)
            ->renderAsBadges(['new' => 'danger', 'answered' => 'success', 'closed' => 'secondary']);
        yield TextField::new('name', 'Name')->hideOnForm();
        yield EmailField::new('email', 'E-Mail')->hideOnForm();
        yield TextareaField::new('message', 'Nachricht')->hideOnForm()->hideOnIndex()->renderAsHtml(false);
        yield TextField::new('locale', 'Sprache')->hideOnForm()->hideOnIndex();
        yield TextareaField::new('adminNote', 'Interne Notiz')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $reply = Action::new('reply', 'Per E-Mail antworten', 'fa fa-reply')
            ->linkToUrl(static fn (Inquiry $i): string => 'mailto:'.rawurlencode($i->getEmail()).'?subject='.rawurlencode('Ihre Nachricht'))
            ->asPrimaryAction();

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $reply);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status', 'Status')->setChoices(self::STATUS));
    }
}
