<?php

namespace App\Controller\Admin;

use App\Import\FolderImporter;
use App\Media\BrandAssets;
use App\Repository\InquiryRepository;
use App\Repository\ItemRepository;
use App\Repository\ItemUploadRepository;
use App\Repository\ScanJobRepository;
use App\Service\SettingsProvider;
use App\Twig\Vite;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly ItemUploadRepository $uploads,
        private readonly InquiryRepository $inquiries,
        private readonly ScanJobRepository $scanJobs,
        private readonly SettingsProvider $settings,
        private readonly FolderImporter $importer,
        private readonly Vite $vite,
        private readonly BrandAssets $brand,
    ) {
    }

    public function index(): Response
    {
        $pendingFolders = null;
        $scanError = null;
        try {
            $pendingFolders = \count($this->importer->findNewFolders());
        } catch (\Throwable $e) {
            $scanError = $e->getMessage();
        }

        return $this->render('admin/dashboard.html.twig', [
            'counts' => $this->items->countByStatus(),
            'newInquiries' => $this->inquiries->countNew(),
            'latestInquiries' => $this->inquiries->findBy([], ['createdAt' => 'DESC'], 5),
            'latestItems' => $this->items->findBy([], ['updatedAt' => 'DESC'], 8),
            'lastScan' => $this->scanJobs->findLatest(),
            'pendingFolders' => $pendingFolders,
            'scanError' => $scanError,
            'scanDir' => $this->importer->getScanDir(),
            'uploads' => $this->uploads->stats(new \DateTimeImmutable('-30 days')) + [
                'multiple' => $this->items->countMultipleUploads(),
                'possible' => $this->items->countPossibleDuplicates(),
            ],
            'mostUploaded' => $this->items->findMostUploaded(5),
            'latestDuplicates' => $this->uploads->findLatestDuplicates(5),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="'.htmlspecialchars($this->brand->url('logo')).'" alt="" width="28" height="28" style="border-radius:6px;vertical-align:middle;margin-right:.5rem">'.htmlspecialchars($this->settings->get()->getSiteName()).' <small class="text-muted">Verwaltung</small>')
            ->setFaviconPath($this->brand->url('favicon'))
            ->setTheme(Theme::new()->primaryColor('#1a2a43', dark: '#8ea1c0'))
            ->setDefaultColorScheme('light');
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateFormat('dd.MM.yyyy')
            ->setDateTimeFormat('dd.MM.yyyy HH:mm')
            ->setTimezone('Europe/Berlin')
            ->setPaginatorPageSize(30)
            ->setDecimalSeparator(',')
            ->setThousandsSeparator('.')
            ->addFormTheme('admin/form/theme.html.twig');
    }

    public function configureAssets(): Assets
    {
        // Adresse für den Zwischenstand laufender KI-Aufgaben (assets/admin/aiProgress.ts)
        $progressUrl = htmlspecialchars($this->generateUrl('admin_ai_progress'));

        return Assets::new()
            ->addHtmlContentToHead($this->vite->tags('assets/admin.ts'))
            ->addHtmlContentToHead('<meta name="ai-progress-url" content="'.$progressUrl.'">');
    }

    public function configureMenuItems(): iterable
    {
        $counts = $this->items->countByStatus();
        $toReview = $counts['review'] + $counts['draft'] + $this->uploads->countProposals();
        $newInquiries = $this->inquiries->countNew();

        yield MenuItem::linkToDashboard('Übersicht', 'fa fa-gauge');
        yield MenuItem::linkTo(ReviewController::class, 'Prüfen & freigeben', 'fa fa-wand-magic-sparkles')
            ->setAction('index')
            ->setBadge($toReview > 0 ? $toReview : false, 'warning');

        yield MenuItem::section('Sammlung');
        yield MenuItem::linkTo(ItemCrudController::class, 'Objekte', 'fa fa-stamp');
        yield MenuItem::linkTo(CollectionAreaCrudController::class, 'Objektarten', 'fa fa-layer-group');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Länder & Gebiete', 'fa fa-earth-europe');
        yield MenuItem::linkTo(TopicCrudController::class, 'Themen', 'fa fa-tags');

        yield MenuItem::section('Kontakt');
        yield MenuItem::linkTo(InquiryCrudController::class, 'Nachrichten', 'fa fa-envelope')
            ->setBadge($newInquiries > 0 ? $newInquiries : false, 'danger');

        yield MenuItem::section('Website');
        yield MenuItem::linkTo(PageCrudController::class, 'Seiten', 'fa fa-file-lines');
        yield MenuItem::linkTo(LanguageCrudController::class, 'Sprachen', 'fa fa-language');
        yield MenuItem::linkTo(UiTranslationCrudController::class, 'Oberflächentexte', 'fa fa-font');
        $settingsId = $this->settings->get()->getId();
        if (null !== $settingsId) {
            yield MenuItem::linkTo(SettingsCrudController::class, 'Einstellungen', 'fa fa-sliders')
                ->setAction(Action::EDIT)
                ->setEntityId($settingsId);
        }
        yield MenuItem::linkToRoute('Website ansehen', 'fa fa-arrow-up-right-from-square', 'home', ['_locale' => 'de'])
            ->setLinkTarget('_blank');

        yield MenuItem::section('System');
        yield MenuItem::linkTo(AppTokenCrudController::class, 'App-Zugänge', 'fa fa-mobile-screen');
        yield MenuItem::linkTo(ScanJobCrudController::class, 'Scan-Protokoll', 'fa fa-folder-open');
        yield MenuItem::linkTo(UserCrudController::class, 'Benutzer', 'fa fa-user-shield');
    }
}
