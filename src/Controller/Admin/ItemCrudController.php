<?php

namespace App\Controller\Admin;

use App\Admin\Filter\ItemConditionFilter;
use App\Duplicate\DuplicateOutcome;
use App\Entity\Item;
use App\Entity\Language;
use App\Enum\ItemStatus;
use App\Form\Admin\ItemAttributesType;
use App\Form\Admin\ItemImageType;
use App\Form\Admin\TranslationType;
use App\Media\CropBox;
use App\Media\ImageCropper;
use App\Media\ImageRotator;
use App\Media\MediaStorage;
use App\Recognition\RecognitionService;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\LanguageRepository;
use App\Service\SettingsProvider;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * @extends AbstractCrudController<Item>
 */
class ItemCrudController extends AbstractCrudController
{
    public const STATUS_CHOICES = [
        'Entwurf' => ItemStatus::Draft,
        'Zu prüfen' => ItemStatus::Review,
        'Veröffentlicht' => ItemStatus::Published,
        'Archiviert' => ItemStatus::Archived,
    ];
    private const MAX_SEARCH_TERMS = 6;
    /** Wert von ItemImage::$cropChange: Zuschnitt aufheben */
    private const CROP_RESET = 'reset';

    private bool $listPreloaded = false;

    public function __construct(
        private readonly CollectionAreaRepository $areas,
        private readonly ItemRepository $items,
        private readonly LanguageRepository $languages,
        private readonly MediaStorage $media,
        private readonly ImageRotator $rotator,
        private readonly ImageCropper $cropper,
        private readonly SettingsProvider $settings,
        private readonly AppExtension $app,
        private readonly Packages $packages,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Item::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Objekt')
            ->setEntityLabelInPlural('Objekte')
            ->setDefaultSort(['createdAt' => 'DESC'])
            // die eigentliche Suche steckt in createIndexQueryBuilder()
            ->setSearchFields(['id'])
            ->setHelp(Crud::PAGE_INDEX, 'Die Suche findet Nummer, Titel, Beschreibung, Serie und Mängel (alle Sprachen), Merkmale wie die Michel-Nr., Land, Themen, Lagerort, Upload-Ordner und interne Notizen. Mehrere Wörter müssen alle vorkommen; Filter lassen sich dazu kombinieren. „Uploads“ zählt, wie oft ein Objekt hochgeladen wurde (erkannte Duplikate eingeschlossen).')
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Item $item): string => sprintf('Objekt #%d bearbeiten', $item->getId()))
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Item $item): string => sprintf('#%d %s', $item->getId(), $item->getTitle()))
            ->setPaginatorPageSize(30);
    }

    /**
     * Textsuche über Übersetzungen, Merkmale, Land, Themen und interne Felder.
     * Die Suche von EasyAdmin wird dafür abgeschaltet (leere Suchanfrage an die Elternklasse).
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $this->listPreloaded = false;
        $withoutQuery = new SearchDto($searchDto->getRequest(), $searchDto->getSearchableProperties(), null, $searchDto->getDefaultSort(), $searchDto->getCustomSort(), $searchDto->getAppliedFilters(), $searchDto->getSearchMode());
        $qb = parent::createIndexQueryBuilder($withoutQuery, $entityDto, $fields, $filters);

        foreach (\array_slice($searchDto->getQueryTerms(), 0, self::MAX_SEARCH_TERMS) as $n => $term) {
            $like = '%'.addcslashes($term, '%_').'%';
            $conditions = [
                sprintf('entity.id IN (SELECT IDENTITY(qt%1$d.item) FROM App\Entity\ItemTranslation qt%1$d WHERE qt%1$d.title LIKE :q%1$d OR qt%1$d.description LIKE :q%1$d OR qt%1$d.series LIKE :q%1$d OR qt%1$d.defects LIKE :q%1$d)', $n),
                sprintf('entity.id IN (SELECT IDENTITY(qa%1$d.item) FROM App\Entity\ItemAttribute qa%1$d WHERE qa%1$d.value LIKE :q%1$d)', $n),
                sprintf('entity.category IN (SELECT IDENTITY(qc%1$d.category) FROM App\Entity\CategoryTranslation qc%1$d WHERE qc%1$d.name LIKE :q%1$d)', $n),
                sprintf('entity.id IN (SELECT qi%1$d.id FROM App\Entity\Item qi%1$d JOIN qi%1$d.topics qtp%1$d JOIN qtp%1$d.translations qtt%1$d WHERE qtt%1$d.name LIKE :q%1$d)', $n),
                sprintf('entity.storageLocation LIKE :q%1$d OR entity.sourceFolder LIKE :q%1$d OR entity.internalNotes LIKE :q%1$d', $n),
            ];
            if (ctype_digit(ltrim($term, '#'))) {
                $conditions[] = sprintf('entity.id = :qid%d', $n);
                $qb->setParameter('qid'.$n, (int) ltrim($term, '#'));
            }
            $qb->andWhere('('.implode(' OR ', $conditions).')')->setParameter('q'.$n, $like);
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        $currency = $this->settings->get()->getCurrency();

        // ---- Übersicht ----
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield Field::new('thumbnail', 'Bild')
            ->onlyOnIndex()
            ->setTemplatePath(AdminTemplates::COMPUTED)
            ->formatValue(function ($value, Item $item): string {
                // erste Zeile: Texte, Bilder und Land aller Zeilen der Seite auf einmal laden (sonst mehrere Abfragen je Zeile)
                if (!$this->listPreloaded) {
                    $this->listPreloaded = true;
                    $this->items->preloadLoaded();
                }
                $image = $item->getMainImage();

                return null === $image ? '' : sprintf(
                    '<img src="%s" alt="" style="width:56px;height:56px;object-fit:contain;background:#f2ede2;border-radius:8px;padding:3px">',
                    htmlspecialchars($this->packages->getUrl($image->getRelativePath(400))),
                );
            });

        // ---- Allgemein ----
        yield FormField::addTab('Allgemein', 'fa fa-circle-info');
        yield ChoiceField::new('status', 'Status')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges(['draft' => 'secondary', 'review' => 'warning', 'published' => 'success', 'archived' => 'dark'])
            ->setColumns(4);
        yield AssociationField::new('area', 'Objektart')
            ->renderAsNativeWidget()
            ->setColumns(4)
            ->setHelp('Bestimmt die Merkmale im Tab „Merkmale“.');
        yield BooleanField::new('featured', 'Hervorheben')->renderAsSwitch()->setColumns(4)->hideOnIndex()
            ->setHelp('Erscheint auf der Startseite unter „Ausgewählte Stücke“.');
        yield TextField::new('title', 'Titel')->setSortable(false)->setColumns(12);
        yield TextareaField::new('description', 'Beschreibung')->setNumOfRows(5)->setColumns(12)->hideOnIndex();
        yield TextareaField::new('defects', 'Mängel')->setNumOfRows(2)->setColumns(6)->hideOnIndex()
            ->setHelp('Sichtbare Mängel, z. B. „kurzer Zahn oben rechts“. Wird auf der Website bei der Erhaltung gezeigt.');
        yield TextareaField::new('metaDescription', 'Meta-Beschreibung (Suchmaschinen)')->setNumOfRows(2)->setColumns(6)->hideOnIndex()
            ->setHelp('Höchstens ca. 155 Zeichen. Leer = Anfang der Beschreibung.');

        // ---- Einordnung ----
        yield FormField::addTab('Einordnung', 'fa fa-sitemap');
        yield AssociationField::new('category', 'Land')
            ->autocomplete()
            ->setCrudController(CategoryCrudController::class)
            ->setSortable(false)
            ->setColumns(6);
        yield AssociationField::new('topics', 'Themen')
            ->autocomplete()
            ->setCrudController(TopicCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->setColumns(6)
            ->hideOnIndex();
        yield IntegerField::new('year', 'Jahr')->setThousandsSeparator('')->setColumns(3);
        yield IntegerField::new('yearTo', 'bis Jahr')->setThousandsSeparator('')->setColumns(3)->hideOnIndex()->setHelp('nur bei Zeiträumen');
        yield DateField::new('issueDate', 'Ausgabetag')->setColumns(3)->hideOnIndex();
        yield TextField::new('series', 'Serie / Ausgabe')->setColumns(6)->hideOnIndex();

        // ---- Merkmale ----
        yield FormField::addTab('Merkmale', 'fa fa-list-check');
        yield Field::new('attributeData', false)
            ->setFormType(ItemAttributesType::class)
            ->setFormTypeOption('areas', $this->areas->findAllWithDefinitions())
            ->onlyOnForms();

        // ---- Schätzwert ----
        yield FormField::addTab('Schätzwert', 'fa fa-scale-balanced');
        yield MoneyField::new('catalogValue', 'Schätzwert (KI)')->setCurrency($currency)->setStoredAsCents(false)->setColumns(4)->hideOnIndex()
            ->setHelp('Von der KI geschätzter Katalogwert – nur ein Richtwert.');
        yield BooleanField::new('showEstimate', 'Schätzwert anzeigen')->renderAsSwitch()->setColumns(4)
            ->setHelp('Nur wenn eingeschaltet, erscheint der Wert auf der Website (als „unverbindliche Schätzung“).');

        // ---- Bilder ----
        yield FormField::addTab('Bilder', 'fa fa-images');
        yield CollectionField::new('images', 'Bilder')
            ->setEntryType(ItemImageType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms()
            ->setHelp('Bilder werden beim Speichern web-optimiert (WebP, 2000/800/400 px, ohne Metadaten). Die Vorderseite ist das Hauptbild. Mit den Knöpfen unter der Vorschau drehen oder zuschneiden, etwa wenn neben der Marke Text steht, den die KI sonst mitliest. Ausgeführt wird beides beim Speichern; den Zuschnitt eines Bildes aus dem Upload-Ordner kannst du später wieder aufheben.');

        // ---- Übersetzungen ----
        yield FormField::addTab('Übersetzungen', 'fa fa-language');
        yield CollectionField::new('otherTranslations', 'Übersetzungen')
            ->setEntryType(TranslationType::class)
            ->setEntryIsComplex()
            ->allowAdd(false)
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyWhenUpdating()
            ->setHelp('Die KI übersetzt veröffentlichte Objekte automatisch (app:translate). Änderungen hier bleiben erhalten, bis sich der deutsche Text ändert. Gelöschte Übersetzungen werden neu erstellt.');

        // ---- Intern & KI ----
        yield FormField::addTab('Intern & KI', 'fa fa-lock');
        yield TextField::new('storageLocation', 'Lagerort (intern)')->setColumns(6)->hideOnIndex();
        yield TextField::new('sourceFolder', 'Upload-Ordner')->setColumns(6)->setDisabled()->hideOnIndex();
        yield TextareaField::new('internalNotes', 'Interne Notizen')->setNumOfRows(3)->hideOnIndex();
        yield TextareaField::new('aiHint', 'Hinweis an die KI')->setNumOfRows(3)->hideOnIndex()
            ->setFormTypeOption('attr', ['maxlength' => Item::AI_HINT_MAX, 'placeholder' => 'z. B. „DDR 1953, 24 Pf Karl Marx“ oder „Der Text unter der Marke gehört nicht dazu“'])
            ->setHelp('Geht nach dem Speichern bei jeder KI-Erkennung mit und hat Vorrang vor der Einschätzung der KI. Bekannte Angaben eintragen, den Rest ergänzt die KI anhand der Fotos.');
        yield NumberField::new('aiConfidence', 'KI-Konfidenz')->setNumDecimals(2)->setDisabled()->setColumns(3)->hideOnIndex();
        yield TextField::new('aiModel', 'KI-Modell')->setDisabled()->setColumns(3)->hideOnIndex();
        yield DateTimeField::new('recognizedAt', 'Erkannt am')->setDisabled()->setColumns(3)->hideOnIndex();
        yield TextareaField::new('aiError', 'Letzter KI-Fehler')->setDisabled()->setNumOfRows(2)->hideOnIndex();
        yield Field::new('aiResultPretty', 'KI-Antwort (JSON)')
            ->onlyOnDetail()
            ->setTemplatePath(AdminTemplates::COMPUTED)
            ->formatValue(static fn ($value, Item $item): string => null === $item->getAiResult() ? '–' : '<pre style="white-space:pre-wrap;font-size:12px;max-height:420px;overflow:auto">'.htmlspecialchars((string) json_encode($item->getAiResult(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)).'</pre>');

        // ---- Uploads ----
        yield FormField::addTab('Uploads', 'fa fa-clock-rotate-left')->onlyOnDetail();
        yield Field::new('uploads', 'Upload-Historie')
            ->onlyOnDetail()
            ->setTemplatePath(AdminTemplates::UPLOADS)
            ->setHelp('Jeder Upload der App mit Ergebnis der Duplikat-Erkennung. Ersetzte Fotos bleiben erhalten und lassen sich wieder zeigen; die Originale in app_uploads/ bleiben unverändert.');

        yield IntegerField::new('uploadCount', 'Uploads')->hideOnForm()->setTextAlign('center')
            ->setHelp('Wie oft das Objekt hochgeladen wurde.');
        yield DateTimeField::new('updatedAt', 'Geändert')->onlyOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $publish = Action::new('publish', 'Freigeben', 'fa fa-check')
            ->linkToRoute('admin_item_publish', fn (Item $item): array => [
                'entityId' => $item->getId(),
                'token' => $this->csrf->getToken('publish'.$item->getId())->getValue(),
            ])
            ->renderAsForm()
            ->asSuccessAction()
            ->displayIf(static fn (Item $item): bool => ItemStatus::Published !== $item->getStatus());

        $recognize = Action::new('recognize', 'KI-Erkennung', 'fa fa-wand-magic-sparkles')
            ->linkToRoute('admin_item_recognize', fn (Item $item): array => [
                'entityId' => $item->getId(),
                'token' => $this->csrf->getToken('recognize'.$item->getId())->getValue(),
            ])
            ->renderAsForm()
            ->setHtmlAttributes(['data-ai-progress' => 'KI-Erkennung läuft', 'data-ai-result' => 'Ergebnis anzeigen'])
            ->displayIf(static fn (Item $item): bool => !$item->getImages()->isEmpty());

        $view = Action::new('viewOnSite', 'Ansehen', 'fa fa-arrow-up-right-from-square')
            ->linkToUrl(fn (Item $item): string => $this->app->itemUrl($item, false, Language::SOURCE))
            ->setHtmlAttributes(['target' => '_blank']);

        $review = Action::new('goReview', 'Prüfen & freigeben', 'fa fa-wand-magic-sparkles')
            ->linkToRoute('admin_review_index')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $publish)
            ->add(Crud::PAGE_INDEX, $view)
            ->add(Crud::PAGE_INDEX, $review)
            ->add(Crud::PAGE_DETAIL, $publish)
            ->add(Crud::PAGE_DETAIL, $recognize)
            ->add(Crud::PAGE_DETAIL, $view)
            ->add(Crud::PAGE_EDIT, $view)
            ->add(Crud::PAGE_EDIT, $recognize)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::STATUS_CHOICES))
            ->add(EntityFilter::new('area', 'Objektart'))
            ->add(EntityFilter::new('category', 'Land')->autocomplete())
            ->add(EntityFilter::new('topics', 'Themen')->canSelectMultiple())
            ->add(NumericFilter::new('year', 'Jahr'))
            ->add(BooleanFilter::new('featured', 'Hervorgehoben'))
            ->add(BooleanFilter::new('showEstimate', 'Schätzwert angezeigt'))
            ->add(ItemConditionFilter::new(ItemConditionFilter::MULTIPLE_UPLOADS, 'Mehrfach hochgeladen'))
            ->add(NumericFilter::new('uploadCount', 'Anzahl Uploads'))
            ->add(ItemConditionFilter::new(ItemConditionFilter::POSSIBLE_DUPLICATE, 'Mögliches Duplikat'))
            ->add(ItemConditionFilter::new(ItemConditionFilter::HAS_DEFECTS, 'Hat Mängel'))
            ->add(ItemConditionFilter::new(ItemConditionFilter::MISSING_TRANSLATION, 'Übersetzung fehlt', \count($this->languages->findActive())));
    }

    public function createEntity(string $entityFqcn): Item
    {
        $item = new Item();
        $item->setArea($this->settings->get()->getDefaultScanArea() ?? ($this->areas->findActive()[0] ?? null));

        return $item;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processImages($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processImages($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    #[AdminRoute('/{entityId}/publish', name: 'publish', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function publish(Request $request, int $entityId, EntityManagerInterface $em): Response
    {
        $item = $em->find(Item::class, $entityId) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('publish'.$item->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
        $item->setStatus(ItemStatus::Published);
        $em->flush();
        $this->addFlash('success', sprintf('„%s“ ist jetzt veröffentlicht. Die Übersetzungen folgen mit dem nächsten Lauf von app:translate.', $item->getTitle()));

        return $this->redirectToRoute('admin_item_index');
    }

    #[AdminRoute('/{entityId}/recognize', name: 'recognize', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function recognize(Request $request, int $entityId, EntityManagerInterface $em, RecognitionService $recognition): Response
    {
        $item = $em->find(Item::class, $entityId) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('recognize'.$item->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
        // Session freigeben: Sonst warten alle anderen Backend-Seiten, bis die KI fertig ist (PHP sperrt die Session je Anfrage)
        $request->getSession()->save();
        set_time_limit(600);
        $ok = $recognition->recognize($item);
        $duplicate = $recognition->lastDuplicate();
        if ($ok && DuplicateOutcome::MERGED === $duplicate->result && null !== $duplicate->target) {
            $this->addFlash('success', sprintf('Erkannt: %s. Der Upload gehört jetzt zu diesem Objekt.', $duplicate->describe()));

            return $this->redirectToRoute('admin_item_detail', ['entityId' => $duplicate->target->getId()]);
        }
        if ($ok && DuplicateOutcome::POSSIBLE === $duplicate->result) {
            $this->addFlash('warning', 'Erkannt, aber '.$duplicate->describe().'.');
        }
        if ($ok) {
            $this->addFlash('success', sprintf('Erkannt: „%s“ (Konfidenz %d %%). Bitte prüfen.', $item->getTitle(), (int) round(100 * (float) $item->getAiConfidence())));
        } else {
            $this->addFlash('danger', 'Erkennung fehlgeschlagen: '.$item->getAiError());
        }

        return $this->redirectToRoute('admin_item_edit', ['entityId' => $item->getId()]);
    }

    /**
     * Neue/ersetzte Uploads web-optimiert speichern, gewünschte Zuschnitte und Drehungen ausführen, leere Einträge entfernen.
     * Der Zuschnitt aus dem Formular bezieht sich auf das Bild vor der neuen Drehung, deshalb erst zuschneiden, dann drehen.
     */
    private function processImages(Item $item): void
    {
        $folder = 'items/'.($item->getSourceFolder() ?? 'manual-'.date('Ymd-His'));
        foreach ($item->getImages()->toArray() as $image) {
            $file = $image->getFile();
            $rotate = $image->getRotate();
            $crop = $this->cropChange($image->getCropChange());
            $image->setRotate(0)->setCropChange(null);
            if (null === $file) {
                if (null === $image->getPath()) {
                    $item->removeImage($image);
                    continue;
                }
                try {
                    if (self::CROP_RESET === $crop) {
                        $this->cropper->reset($image);
                    } elseif ($crop instanceof CropBox) {
                        $this->cropper->crop($image, $crop);
                    }
                } catch (\RuntimeException $e) {
                    $this->addFlash('warning', 'Ein Bild konnte nicht zugeschnitten werden: '.$e->getMessage());
                }
                if (0 !== $rotate) {
                    try {
                        $this->rotator->rotate($image, $rotate);
                    } catch (\RuntimeException $e) {
                        $this->addFlash('warning', 'Ein Bild konnte nicht gedreht werden: '.$e->getMessage());
                    }
                }
                continue;
            }
            $old = $image->getPath();
            // Zuschnitt und Drehung gelten für die neu gewählte Datei (das Formular zeigt sie in der Vorschau)
            $saved = $this->media->store($file->getPathname(), $folder.'/'.bin2hex(random_bytes(3)), $rotate, $crop instanceof CropBox ? $crop : null);
            $image->setPath($saved['path'])->setDimensions($saved['width'], $saved['height'])->setHash($saved['hash'])
                ->setFile(null)->setSourceFile(null)->setRotation(0)->setCrop(null);
            if (null !== $old) {
                $this->media->delete($old);
            }
        }
    }

    /** Feld „cropChange“ aus dem Formular: null (unverändert), CROP_RESET oder der Ausschnitt */
    private function cropChange(?string $value): CropBox|string|null
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        if (self::CROP_RESET === $value) {
            return self::CROP_RESET;
        }
        try {
            $data = json_decode($value, true, 4, \JSON_THROW_ON_ERROR);

            return \is_array($data) ? CropBox::fromArray($data) : throw new \InvalidArgumentException('Ungültiger Bildausschnitt.');
        } catch (\JsonException|\InvalidArgumentException $e) {
            $this->addFlash('warning', 'Ein Bild wurde nicht zugeschnitten: '.($e instanceof \JsonException ? 'Ungültiger Bildausschnitt.' : $e->getMessage()));

            return null;
        }
    }
}
