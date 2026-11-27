<?php

namespace App\Controller\Admin;

use App\Entity\AppToken;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Zugänge der Exponio-App zur Upload-Schnittstelle: anlegen (das Token erscheint danach genau einmal,
 * siehe AppTokenCreatedController) und löschen (sperrt das Gerät sofort). Bearbeiten gibt es nicht.
 *
 * @extends AbstractCrudController<AppToken>
 */
class AppTokenCrudController extends AbstractCrudController
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public static function getEntityFqcn(): string
    {
        return AppToken::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('App-Zugang')
            ->setEntityLabelInPlural('App-Zugänge')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['label'])
            ->setHelp(Crud::PAGE_INDEX, 'Mit einem App-Zugang lädt die Exponio-App Fotos direkt in die Website hoch: In der App unter Einstellungen die Adresse der Website und das Token eintragen. Lege für jedes Gerät einen eigenen Zugang an. Löschen sperrt das Gerät sofort.')
            ->setHelp(Crud::PAGE_NEW, 'Nach dem Speichern erscheint das Token genau einmal. Gespeichert wird nur eine Prüfsumme davon.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::EDIT, Action::DETAIL)
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $a): Action => $a->setLabel('Neuer App-Zugang'))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, static fn (Action $a): Action => $a->setLabel('Token erzeugen'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Gerät')
            ->setHelp('Zum Wiedererkennen, z. B. „Pixel 10“.')
            ->setFormTypeOption('attr', ['maxlength' => 80, 'placeholder' => 'Pixel 10']);
        yield TextField::new('hint', 'Token')->hideOnForm()
            ->formatValue(static fn (mixed $value): string => AppToken::PREFIX.'…'.$value);
        yield DateTimeField::new('createdAt', 'Angelegt')->hideOnForm();
        yield DateTimeField::new('lastUsedAt', 'Zuletzt benutzt')->hideOnForm()
            ->formatValue(static fn (mixed $value): string => $value instanceof \DateTimeInterface ? $value->format('d.m.Y H:i') : 'noch nie');
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        // nur in der Session bis zur einmaligen Anzeige, nicht als Flash (die erscheinen als Toast und verschwinden)
        $this->requestStack->getSession()->set(AppTokenCreatedController::SESSION_KEY, [
            'label' => (string) $entityInstance->getLabel(),
            'token' => (string) $entityInstance->takePlainToken(),
        ]);
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        if (Action::NEW === $action) {
            return $this->redirectToRoute('admin_app_token_created_show');
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
