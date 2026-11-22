<?php

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Translation\TranslationRunner;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Alle Sprachen der Welt. Freischalten → die KI übersetzt Oberfläche und Inhalte (app:translate);
 * öffentlich wird die Sprache, sobald die Oberfläche vollständig übersetzt ist.
 *
 * @extends AbstractCrudController<Language>
 */
class LanguageCrudController extends AbstractCrudController
{
    /** KI-Anfragen bei „Jetzt übersetzen“ (der Rest folgt per Cronjob) */
    private const MANUAL_REQUESTS = 5;

    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Language::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sprache')
            ->setEntityLabelInPlural('Sprachen')
            ->setDefaultSort(['active' => 'DESC', 'position' => 'ASC', 'nameDe' => 'ASC'])
            ->setSearchFields(['code', 'nameDe', 'nativeName'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Schalte Sprachen mit „Aktiv“ frei. Die KI übersetzt danach die Oberfläche und alle veröffentlichten Inhalte (Cronjob app:translate oder „Jetzt übersetzen“). Auf der Website erscheint eine Sprache, sobald ihre Oberfläche fertig übersetzt ist. Deutsch ist die Ausgangssprache.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $translate = Action::new('translateNow', 'Jetzt übersetzen', 'fa fa-language')
            ->linkToRoute('admin_language_translate', fn (Language $l): array => [
                'entityId' => $l->getCode(),
                'token' => $this->csrf->getToken('translate'.$l->getCode())->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(static fn (Language $l): bool => $l->isActive() && !$l->isSource());

        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $translate);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code')->setDisabled();
        yield TextField::new('nameDe', 'Sprache')->setDisabled();
        yield TextField::new('nativeName', 'Eigenname')->setDisabled();
        yield BooleanField::new('active', 'Aktiv')->renderAsSwitch()
            ->setDisabled(false)
            ->setHelp('Deutsch bleibt immer aktiv.');
        yield Field::new('status', 'Status')
            ->onlyOnIndex()
            ->setTemplatePath(AdminTemplates::COMPUTED)
            ->formatValue(static fn ($value, Language $l): string => match (true) {
                !$l->isActive() => '–',
                $l->isPublic() => 'öffentlich',
                default => 'Übersetzung läuft',
            });
        yield TextField::new('direction', 'Schrift')->setVirtual(true)->hideOnForm()
            ->formatValue(static fn ($value, Language $l): string => $l->isRtl() ? 'rechts → links' : 'links → rechts');
        yield IntegerField::new('position', 'Reihenfolge');
        yield DateTimeField::new('uiTranslatedAt', 'Oberfläche fertig seit')->hideOnForm()
            ->formatValue(static fn (?\DateTimeInterface $value): string => $value?->format('d.m.Y H:i') ?? '–');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active');
    }

    #[AdminRoute('/{entityId}/translate', name: 'translate', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '[a-z]{2}']])]
    public function translate(Request $request, string $entityId, EntityManagerInterface $em, TranslationRunner $runner): Response
    {
        $language = $em->find(Language::class, $entityId) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('translate'.$entityId, (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
        // Session freigeben: Sonst warten alle anderen Backend-Seiten, bis die KI fertig ist (PHP sperrt die Session je Anfrage)
        $request->getSession()->save();
        set_time_limit(600);
        try {
            $stats = $runner->run($language, self::MANUAL_REQUESTS);
            $this->addFlash($stats->isComplete() ? 'success' : 'info', sprintf('%s: %s%s', $language->getNameDe(), $stats->summary(), $stats->isComplete() ? '' : ' – der Rest folgt mit dem Cronjob.'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Übersetzung fehlgeschlagen: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_language_index');
    }
}
