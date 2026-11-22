<?php

namespace App\Controller\Admin;

use App\Entity\ScanJob;
use App\Enum\ScanStatus;
use App\Import\FolderImporter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * @extends AbstractCrudController<ScanJob>
 */
class ScanJobCrudController extends AbstractCrudController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ScanJob::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Scan-Eintrag')
            ->setEntityLabelInPlural('Scan-Protokoll')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['folder', 'message'])
            ->setHelp(Crud::PAGE_INDEX, 'Jeder Upload-Ordner wird genau einmal importiert. Erkannte Duplikate zeigen auf das vorhandene Objekt. Eintrag löschen = Ordner beim nächsten Scan erneut importieren.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Zeitpunkt');
        yield TextField::new('folder', 'Ordner');
        yield ChoiceField::new('status', 'Status')
            ->setChoices(['Importiert' => ScanStatus::Imported, 'Als Duplikat zugeordnet' => ScanStatus::Merged, 'Fehlgeschlagen' => ScanStatus::Failed])
            ->renderAsBadges(['imported' => 'success', 'merged' => 'info', 'failed' => 'danger']);
        yield IntegerField::new('imageCount', 'Bilder');
        yield AssociationField::new('item', 'Objekt');
        yield TextField::new('message', 'Meldung');
    }

    public function configureActions(Actions $actions): Actions
    {
        $scan = Action::new('scanNow', 'Jetzt scannen', 'fa fa-rotate')
            // globale Aktion: Parameter als Array (Closures erhalten nur Entity-Aktionen)
            ->linkToRoute('admin_scan_job_scan', ['token' => $this->csrf->getToken('scan-now')->getValue()])
            ->renderAsForm()
            ->asPrimaryAction()
            ->createAsGlobalAction();

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $scan);
    }

    #[AdminRoute('/scan', name: 'scan', options: ['methods' => ['POST']])]
    public function scanNow(Request $request, FolderImporter $importer): Response
    {
        if (!$this->isCsrfTokenValid('scan-now', (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
        // Session freigeben: Sonst warten alle anderen Backend-Seiten, bis der Import fertig ist (PHP sperrt die Session je Anfrage)
        $request->getSession()->save();
        set_time_limit(600);
        try {
            $result = $importer->importNew(50);
            $this->addFlash('success', sprintf('%d Ordner importiert.', \count($result['imported'])));
            foreach ($result['failed'] as $folder => $message) {
                $this->addFlash('danger', sprintf('%s: %s', $folder, $message));
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_review_index');
    }
}
