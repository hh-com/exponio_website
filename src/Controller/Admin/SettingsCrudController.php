<?php

namespace App\Controller\Admin;

use App\Ai\AiConfig;
use App\Ai\AiProvider;
use App\Ai\DefaultPrompts;
use App\Ai\GeminiClient;
use App\Ai\GeminiCredentials;
use App\Duplicate\DuplicateDetector;
use App\Entity\Settings;
use App\Form\Admin\TranslationType;
use App\Media\BrandAssets;
use App\Recognition\PromptBuilder;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\PercentField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Einstellungen der Website (Name, Logo, Texte), KI (Gemini-Key, Modelle, Prompts, Duplikat-Erkennung), Import, Datenschutz und Suchmaschinen.
 *
 * @extends AbstractCrudController<Settings>
 */
class SettingsCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly GeminiCredentials $credentials,
        private readonly AiConfig $aiConfig,
        private readonly AiProvider $aiProvider,
        private readonly PromptBuilder $prompts,
        private readonly DuplicateDetector $duplicates,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly BrandAssets $brand,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Settings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Einstellungen')
            ->setEntityLabelInPlural('Einstellungen')
            ->setPageTitle(Crud::PAGE_EDIT, 'Einstellungen');
    }

    public function configureActions(Actions $actions): Actions
    {
        $tokenRoute = fn (string $route): Action => Action::new($route)
            ->linkToRoute('admin_settings_'.$route, fn (Settings $s): array => ['token' => $this->csrf->getToken('settings-'.$route)->getValue()])
            ->renderAsForm();

        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->add(Crud::PAGE_EDIT, $tokenRoute('test_ai')->setLabel('KI-Verbindung testen')->setIcon('fa fa-plug-circle-check'))
            ->add(Crud::PAGE_EDIT, $tokenRoute('remove_key')->setLabel('Gemini-Key entfernen')->setIcon('fa fa-key')->asDangerAction()
                ->displayIf(static fn (Settings $s): bool => null !== $s->getGeminiApiKey()))
            ->add(Crud::PAGE_EDIT, $tokenRoute('reset_logo')->setLabel('Standardlogo verwenden')->setIcon('fa fa-image')
                ->displayIf(static fn (Settings $s): bool => null !== $s->getLogoPath()))
            ->add(Crud::PAGE_EDIT, $tokenRoute('reset_prompts')->setLabel('Prompts auf Standard')->setIcon('fa fa-rotate-left')
                ->displayIf(static fn (Settings $s): bool => null !== $s->getAiRecognitionPrompt() || null !== $s->getAiTranslationPrompt() || null !== $s->getAiDuplicatePrompt()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Website', 'fa fa-globe');
        yield TextField::new('siteName', 'Name der Website')->setColumns(6)
            ->setHelp('Erscheint im Kopf- und Fußbereich, im Seitentitel, im Backend und als App-Name beim Ablegen auf dem Startbildschirm.');
        yield TextField::new('ownerName', 'Name des Sammlers')->setColumns(6)->hideOnIndex()
            ->setHelp('Erscheint in den strukturierten Daten (schema.org „Person“). Leer lassen, wenn nicht gewünscht.');
        yield EmailField::new('contactEmail', 'Kontakt-E-Mail')->setColumns(6)
            ->setHelp('Empfängt Nachrichten aus dem Kontaktformular.');
        yield AssociationField::new('privacyPage', 'Datenschutzseite')->setColumns(6)->hideOnIndex()
            ->setHelp('Wird beim Kontaktformular verlinkt.');
        yield TextField::new('tagline', 'Untertitel')->setColumns(12)->hideOnIndex();
        yield TextareaField::new('intro', 'Einleitung Startseite')->setColumns(6)->hideOnIndex();
        yield TextareaField::new('footerText', 'Text im Fußbereich')->setColumns(6)->hideOnIndex();
        yield ChoiceField::new('currency', 'Währung des Schätzwerts')->setChoices(['Euro (€)' => 'EUR', 'Schweizer Franken' => 'CHF', 'US-Dollar' => 'USD', 'Pfund' => 'GBP'])->setColumns(4)->hideOnIndex();
        yield IntegerField::new('itemsPerPage', 'Objekte pro Seite')->setColumns(4)->hideOnIndex();

        yield FormField::addFieldset('Logo')->setHelp($this->logoStatus());
        yield TextField::new('newLogo', $this->brand->isCustom() ? 'Anderes Logo hochladen' : 'Eigenes Logo hochladen')
            ->setFormType(FileType::class)
            ->setFormTypeOptions(['required' => false, 'attr' => ['accept' => 'image/png,image/jpeg,image/webp']])
            ->setColumns(6)->onlyOnForms()
            ->setHelp(sprintf('PNG, JPEG oder WebP, mindestens %1$d × %1$d px, am besten quadratisch – sonst wird mittig quadratisch zugeschnitten. Transparenz bleibt erhalten. Daraus entstehen auch Favicon, App-Symbole und das Vorschaubild für soziale Netzwerke.', Settings::LOGO_MIN_EDGE));

        yield FormField::addTab('Übersetzungen', 'fa fa-language');
        yield CollectionField::new('otherTranslations', 'Übersetzungen der Texte oben')
            ->setEntryType(TranslationType::class)
            ->setEntryIsComplex()
            ->allowAdd(false)
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->onlyWhenUpdating();

        yield FormField::addTab('KI', 'fa fa-wand-magic-sparkles');
        yield FormField::addFieldset('Gemini-Zugang')->setHelp($this->keyStatus());
        yield TextField::new('newGeminiApiKey', 'Neuer Gemini-API-Key')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('always_empty', true)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->setColumns(6)->onlyOnForms()
            ->setHelp('Nur ausfüllen, um den Key zu setzen oder zu ändern. Er wird verschlüsselt gespeichert und nie wieder angezeigt. Key anlegen: https://aistudio.google.com/apikey – für den Datenschutz einen bezahlten Tarif (Abrechnungskonto) verwenden.');
        yield TextField::new('aiModel', 'Modell für die Erkennung')->setColumns(3)->hideOnIndex()
            ->setFormTypeOption('attr', ['placeholder' => $this->aiConfig->defaultModel()])
            ->setHelp('Leer = '.$this->aiConfig->defaultModel());
        yield TextField::new('aiTranslationModel', 'Modell für Übersetzungen')->setColumns(3)->hideOnIndex()
            ->setFormTypeOption('attr', ['placeholder' => $this->aiConfig->defaultTranslationModel()])
            ->setHelp('Leer = '.$this->aiConfig->defaultTranslationModel());

        yield FormField::addFieldset('Prompts');
        yield TextareaField::new('recognitionPromptText', 'Prompt für die Objekterkennung')
            ->setNumOfRows(22)->onlyOnForms()
            ->setHelp(sprintf(
                'Platzhalter: %s (Objektarten mit Merkmalen und erlaubten Werten), %s (vorhandene Länder), %s (vorhandene Themen). Fehlt %1$s, hängt die Website die Liste automatisch an. Das Antwortformat (JSON-Schema) legt der Code fest.',
                DefaultPrompts::PLACEHOLDER_AREAS,
                DefaultPrompts::PLACEHOLDER_REGIONS,
                DefaultPrompts::PLACEHOLDER_TOPICS,
            ));
        yield TextareaField::new('translationPromptText', 'Prompt für Übersetzungen')
            ->setNumOfRows(12)->onlyOnForms()
            ->setHelp(sprintf('Platzhalter: %s (Zielsprache).', DefaultPrompts::PLACEHOLDER_LANGUAGE));
        yield TextareaField::new('promptPreview', 'Vorschau: fertiger Erkennungs-Prompt')
            ->setVirtual(true)->onlyWhenUpdating()
            ->setNumOfRows(12)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('disabled', true)
            ->setFormTypeOption('data', $this->prompts->preview())
            ->setHelp('So sieht der Prompt mit den aktuellen Daten aus (Stand beim Öffnen der Seite).');

        yield FormField::addFieldset('Duplikate')
            ->setHelp('Lädst du ein Objekt hoch, das schon in der Sammlung ist, ordnet die Website den Upload dem vorhandenen Objekt zu und zählt ihn. Ablauf: Vorauswahl ähnlicher Objekte (Objektart, Land, Jahr, Katalognummer, Nennwert, Serie, Wahrnehmungs-Hash der Fotos), dann vergleicht Gemini die Fotos mit höchstens drei Kandidaten in einem Aufruf. „Besser“ heißt: zuerst bessere Erhaltung, bei gleicher Erhaltung bessere Fotos.');
        yield BooleanField::new('duplicateCheck', 'Duplikate beim Import erkennen')->renderAsSwitch()->setColumns(6)->hideOnIndex()
            ->setHelp('Läuft nach der KI-Erkennung jedes neuen Upload-Ordners. Vorhandene Objekte prüft app:duplicates:scan.');
        yield BooleanField::new('duplicateAutoReplacePublished', 'Veröffentlichte Objekte automatisch aktualisieren')->renderAsSwitch()->setColumns(6)->hideOnIndex()
            ->setHelp('Aus (empfohlen): Bessere Fotos werden bei veröffentlichten Objekten nur vorgeschlagen; du bestätigst sie unter „Prüfen & freigeben“.');
        yield PercentField::new('duplicateThreshold', 'Sicher ab')->setStoredAsFractional()->setNumDecimals(0)->setColumns(3)->hideOnIndex()
            ->setHelp('Ab dieser Sicherheit der KI wird automatisch zugeordnet (Standard 85 %).');
        yield PercentField::new('duplicateHintThreshold', 'Hinweis ab')->setStoredAsFractional()->setNumDecimals(0)->setColumns(3)->hideOnIndex()
            ->setHelp('Ab hier entsteht ein Entwurf mit „mögliches Duplikat von #ID“ (Standard 50 %). Darunter gilt der Upload als neues Objekt.');
        yield TextareaField::new('duplicatePromptText', 'Prompt für den Duplikat-Vergleich')
            ->setNumOfRows(16)->onlyOnForms()
            ->setHelp('Keine Platzhalter nötig: Die Angaben zum neuen Objekt und zu den Kandidaten sowie die beschrifteten Fotos hängt die Website an. Das Antwortformat (JSON-Schema) legt der Code fest.');
        yield TextareaField::new('duplicatePromptPreview', 'Vorschau: Vergleichsauftrag')
            ->setVirtual(true)->onlyWhenUpdating()
            ->setNumOfRows(12)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('disabled', true)
            ->setFormTypeOption('data', $this->duplicates->preview())
            ->setHelp('Prompt und Auftrag für das zuletzt erkannte Objekt, ohne Fotos (Stand beim Öffnen der Seite).');

        yield FormField::addTab('Import', 'fa fa-folder-open');
        yield AssociationField::new('defaultScanArea', 'Objektart für neue Uploads')->setColumns(6)->hideOnIndex()
            ->setHelp('Vorbelegung; die KI kann eine andere Objektart wählen.');
        yield BooleanField::new('autoPublish', 'Automatisch veröffentlichen')->renderAsSwitch()->setColumns(3)->hideOnIndex()
            ->setHelp('Ab der Mindest-Konfidenz ohne manuelle Prüfung online stellen. Der Schätzwert bleibt trotzdem verborgen.');
        yield PercentField::new('autoPublishThreshold', 'Mindest-Konfidenz')->setStoredAsFractional()->setNumDecimals(0)->setColumns(3)->hideOnIndex();

        yield FormField::addTab('Datenschutz & Suchmaschinen', 'fa fa-shield-halved');
        yield IntegerField::new('messageRetentionMonths', 'Nachrichten löschen nach (Monaten)')->setColumns(4)->hideOnIndex()
            ->setHelp('Kontaktnachrichten werden danach automatisch gelöscht (Cronjob app:privacy:cleanup).');
        yield BooleanField::new('allowAiCrawlers', 'KI-Crawler erlauben')->renderAsSwitch()->setColumns(4)->hideOnIndex()
            ->setHelp('Erlaubt KI-Suchsystemen (GPTBot, ClaudeBot, Google-Extended, PerplexityBot …) das Lesen der Website (robots.txt). Gut für die Sichtbarkeit in KI-Antworten.');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $key = $entityInstance->takeNewGeminiApiKey();
        if (null !== $key) {
            $this->credentials->store($key);
            $this->addFlash('success', 'Der Gemini-API-Key wurde verschlüsselt gespeichert.');
        }

        $oldLogo = $entityInstance->getLogoPath();
        $logo = $entityInstance->takeNewLogo();
        if (null !== $logo) {
            try {
                $entityInstance->setLogoPath($this->brand->store($logo->getPathname()));
                $this->addFlash('success', 'Das neue Logo ist online. Favicon, App-Symbole und Vorschaubild wurden daraus erzeugt.');
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', 'Das Logo wurde nicht übernommen: '.$e->getMessage());
            }
        }

        parent::updateEntity($entityManager, $entityInstance);

        // alte Logo-Dateien erst nach dem Speichern löschen – bis dahin verweist die Website noch auf sie
        if (null !== $oldLogo && $oldLogo !== $entityInstance->getLogoPath()) {
            $this->brand->delete($oldLogo);
        }
    }

    #[AdminRoute('/test-ai', name: 'test_ai', options: ['methods' => ['POST']])]
    public function testAi(Request $request, GeminiClient $client, SettingsProvider $settings): Response
    {
        $this->checkToken($request, 'test_ai');
        // Session freigeben: Sonst warten alle anderen Backend-Seiten auf die Antwort der KI (PHP sperrt die Session je Anfrage)
        $request->getSession()->save();
        if ($this->aiProvider->isFake()) {
            $this->addFlash('warning', 'AI_PROVIDER=fake ist gesetzt – es wird keine echte KI verwendet.');
        } else {
            try {
                $model = $client->ping($this->aiConfig->recognitionModel());
                $this->addFlash('success', sprintf('Verbindung zu Gemini funktioniert (Modell %s).', $model));
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Test fehlgeschlagen: '.$e->getMessage());
            }
        }

        return $this->redirectToSettings($settings);
    }

    #[AdminRoute('/remove-key', name: 'remove_key', options: ['methods' => ['POST']])]
    public function removeKey(Request $request, EntityManagerInterface $em, SettingsProvider $settings): Response
    {
        $this->checkToken($request, 'remove_key');
        $this->credentials->store(null);
        $em->flush();
        $this->addFlash('success', 'Der gespeicherte Gemini-API-Key wurde entfernt.');

        return $this->redirectToSettings($settings);
    }

    #[AdminRoute('/reset-logo', name: 'reset_logo', options: ['methods' => ['POST']])]
    public function resetLogo(Request $request, EntityManagerInterface $em, SettingsProvider $settings): Response
    {
        $this->checkToken($request, 'reset_logo');
        $oldLogo = $settings->get()->getLogoPath();
        $settings->get()->setLogoPath(null);
        $em->flush();
        if (null !== $oldLogo) {
            $this->brand->delete($oldLogo);
        }
        $this->addFlash('success', 'Die Website verwendet wieder das Standardlogo.');

        return $this->redirectToSettings($settings);
    }

    #[AdminRoute('/reset-prompts', name: 'reset_prompts', options: ['methods' => ['POST']])]
    public function resetPrompts(Request $request, EntityManagerInterface $em, SettingsProvider $settings): Response
    {
        $this->checkToken($request, 'reset_prompts');
        $settings->get()->setAiRecognitionPrompt(null)->setAiTranslationPrompt(null)->setAiDuplicatePrompt(null);
        $em->flush();
        $this->addFlash('success', 'Alle Prompts verwenden wieder den Standardtext.');

        return $this->redirectToSettings($settings);
    }

    private function keyStatus(): string
    {
        return match (true) {
            $this->aiProvider->isFake() => 'Testmodus: AI_PROVIDER=fake – die KI wird nicht aufgerufen.',
            GeminiCredentials::SOURCE_ENV === $this->credentials->source() => sprintf('Der Key kommt aus GEMINI_API_KEY in .env.local (%s) und hat Vorrang.', $this->credentials->hint()),
            $this->credentials->isUnreadable() => 'Der gespeicherte Key lässt sich nicht mehr entschlüsseln (APP_SECRET wurde geändert). Bitte neu eingeben.',
            null !== $this->credentials->source() => sprintf('Key hinterlegt, endet auf %s.', $this->credentials->hint()),
            default => 'Noch kein Key hinterlegt – Erkennung und Übersetzung sind erst danach möglich.',
        };
    }

    /** Vorschau des aktuellen Logos auf dem Grund der Logo-Kachel, wie auf der Website */
    private function logoStatus(): string
    {
        return sprintf(
            '<img src="%s" alt="" width="64" height="64" style="border-radius:.75rem;background:#1e2835;vertical-align:middle;margin-right:.75rem">%s',
            htmlspecialchars($this->brand->url('logo')),
            $this->brand->isCustom() ? 'Eigenes Logo. „Standardlogo verwenden“ (oben) stellt das mitgelieferte Logo wieder her.' : 'Mitgeliefertes Standardlogo.',
        );
    }

    private function checkToken(Request $request, string $action): void
    {
        if (!$this->isCsrfTokenValid('settings-'.$action, (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
    }

    private function redirectToSettings(SettingsProvider $settings): Response
    {
        return $this->redirectToRoute('admin_settings_edit', ['entityId' => $settings->get()->getId()]);
    }
}
