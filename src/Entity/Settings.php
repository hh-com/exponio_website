<?php

namespace App\Entity;

use App\Ai\DefaultPrompts;
use App\Ai\RecognizerConfig;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Website-Einstellungen (genau ein Datensatz, im Backend unter „Einstellungen“).
 *
 * @implements TranslatableInterface<SettingsTranslation>
 */
#[ORM\Entity]
class Settings implements TranslatableInterface
{
    /** @use TranslatableTrait<SettingsTranslation> */
    use TranslatableTrait;

    /** Mindestgröße eines hochgeladenen Logos (kürzere Seite) in Pixeln */
    public const LOGO_MIN_EDGE = 256;

    /** Höchstzahl Zeilen „Modelle für die Erkennung“ – jede aktive kostet bei jedem Objekt eine Anfrage */
    public const MAX_RECOGNIZERS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $siteName = 'Exponio';

    /** Eigenes Logo: Pfad im Medienordner ohne Endung (App\Media\BrandAssets); leer = mitgeliefertes Logo */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $logoPath = null;

    /** Nur für das Backend-Formular: neu hochgeladenes Logo (BrandAssets erzeugt daraus die Dateien, es wird nie gespeichert) */
    #[Assert\Image(
        maxSize: '8M',
        mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
        mimeTypesMessage: 'Bitte ein PNG-, JPEG- oder WebP-Bild hochladen.',
        minWidth: self::LOGO_MIN_EDGE,
        minHeight: self::LOGO_MIN_EDGE,
        maxWidth: 6000,
        maxHeight: 6000,
    )]
    private ?UploadedFile $newLogo = null;

    /** Name des Sammlers (Impressum, strukturierte Daten „Person“) */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ownerName = null;

    /** @var Collection<string, SettingsTranslation> */
    #[ORM\OneToMany(targetEntity: SettingsTranslation::class, mappedBy: 'settings', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column]
    private bool $autoPublish = false;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 1)]
    private float $autoPublishThreshold = 0.9;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CollectionArea $defaultScanArea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Page $privacyPage = null;

    #[ORM\Column]
    #[Assert\Range(min: 6, max: 96)]
    private int $itemsPerPage = 24;

    /**
     * Modelle für die Erkennung, je Zeile Anbieter, Modell, aktiv und Preise (App\Ai\RecognizerConfig::toArray()).
     * Jede aktive Zeile bestimmt jedes Objekt, die oberste aktive ist die Hauptzeile: Ihr Ergebnis kommt ins Formular,
     * ihr Anbieter übernimmt Duplikat-Vergleich und Übersetzung. Leer = AI_PROVIDER aus .env mit dessen Standardmodell.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiRecognizers = null;

    /** Modell für Übersetzungen (des Anbieters der Hauptzeile); leer = Standardmodell aus .env */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $aiTranslationModel = null;

    /** Prompt für die Objekterkennung; leer = Standardtext (App\Ai\DefaultPrompts) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiRecognitionPrompt = null;

    /** Prompt für Übersetzungen; leer = Standardtext */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiTranslationPrompt = null;

    /** Prompt für den Duplikat-Vergleich; leer = Standardtext */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiDuplicatePrompt = null;

    /** Neue Uploads nach der Erkennung auf Duplikate prüfen */
    #[ORM\Column(options: ['default' => true])]
    private bool $duplicateCheck = true;

    /** Ab dieser Sicherheit der KI wird ein neuer Upload automatisch dem vorhandenen Objekt zugeordnet */
    #[ORM\Column(options: ['default' => 0.85])]
    #[Assert\Range(min: 0, max: 1)]
    private float $duplicateThreshold = 0.85;

    /** Ab dieser Sicherheit erscheint der Hinweis „mögliches Duplikat“ in „Prüfen & freigeben“ */
    #[ORM\Column(options: ['default' => 0.5])]
    #[Assert\Range(min: 0, max: 1)]
    private float $duplicateHintThreshold = 0.5;

    /** Bessere Fotos auch bei veröffentlichten Objekten ohne Rückfrage übernehmen (Standard: nur vorschlagen) */
    #[ORM\Column(options: ['default' => false])]
    private bool $duplicateAutoReplacePublished = false;

    /** Gemini-API-Key, verschlüsselt (App\Ai\SecretBox) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $geminiApiKey = null;

    /** Anthropic-API-Key, verschlüsselt (App\Ai\SecretBox) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $anthropicApiKey = null;

    /** Mistral-API-Key, verschlüsselt (App\Ai\SecretBox) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mistralApiKey = null;

    /** OpenAI-API-Key, verschlüsselt (App\Ai\SecretBox) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $openaiApiKey = null;

    /** Dürfen KI-Crawler (GPTBot, ClaudeBot, …) die Seite lesen? */
    #[ORM\Column]
    private bool $allowAiCrawlers = true;

    /** Kontaktnachrichten werden nach so vielen Monaten gelöscht */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 60)]
    private int $messageRetentionMonths = 6;

    /**
     * Nur für das Backend-Formular (App\Form\Admin\ApiKeysType): je Anbieter ein neu eingegebener Key oder
     * „entfernen“. Wird nie gespeichert; SettingsCrudController übernimmt die Keys verschlüsselt.
     *
     * @var array<string, mixed>
     */
    private array $newApiKeys = [];

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->setTagline('Briefmarken, Belege und mehr – sorgfältig erfasst');
    }

    protected function createTranslation(string $locale): SettingsTranslation
    {
        return new SettingsTranslation($this, $locale);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTagline(?string $locale = null): ?string
    {
        return $this->translated('tagline', $locale);
    }

    public function setTagline(?string $tagline): static
    {
        $this->setSourceText('tagline', $tagline);

        return $this;
    }

    public function getIntro(?string $locale = null): ?string
    {
        return $this->translated('intro', $locale);
    }

    public function setIntro(?string $intro): static
    {
        $this->setSourceText('intro', $intro);

        return $this;
    }

    public function getFooterText(?string $locale = null): ?string
    {
        return $this->translated('footerText', $locale);
    }

    public function setFooterText(?string $footerText): static
    {
        $this->setSourceText('footerText', $footerText);

        return $this;
    }

    public function getSiteName(): string
    {
        return $this->siteName;
    }

    public function setSiteName(?string $siteName): static
    {
        $this->siteName = (string) $siteName;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    /** Formularfeld „Logo hochladen“ – bleibt immer leer */
    public function getNewLogo(): ?UploadedFile
    {
        return null;
    }

    public function setNewLogo(?UploadedFile $file): static
    {
        $this->newLogo = $file;

        return $this;
    }

    /** Liefert ein im Formular hochgeladenes Logo genau einmal. */
    public function takeNewLogo(): ?UploadedFile
    {
        $file = $this->newLogo;
        $this->newLogo = null;

        return $file;
    }

    public function getOwnerName(): ?string
    {
        return $this->ownerName;
    }

    public function setOwnerName(?string $ownerName): static
    {
        $this->ownerName = $ownerName;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = strtoupper((string) ($currency ?: 'EUR'));

        return $this;
    }

    public function isAutoPublish(): bool
    {
        return $this->autoPublish;
    }

    public function setAutoPublish(?bool $autoPublish): static
    {
        $this->autoPublish = (bool) $autoPublish;

        return $this;
    }

    public function getAutoPublishThreshold(): float
    {
        return $this->autoPublishThreshold;
    }

    public function setAutoPublishThreshold(?float $autoPublishThreshold): static
    {
        $this->autoPublishThreshold = $autoPublishThreshold ?? 0.9;

        return $this;
    }

    public function getDefaultScanArea(): ?CollectionArea
    {
        return $this->defaultScanArea;
    }

    public function setDefaultScanArea(?CollectionArea $defaultScanArea): static
    {
        $this->defaultScanArea = $defaultScanArea;

        return $this;
    }

    public function getPrivacyPage(): ?Page
    {
        return $this->privacyPage;
    }

    public function setPrivacyPage(?Page $privacyPage): static
    {
        $this->privacyPage = $privacyPage;

        return $this;
    }

    public function getItemsPerPage(): int
    {
        return $this->itemsPerPage;
    }

    public function setItemsPerPage(?int $itemsPerPage): static
    {
        $this->itemsPerPage = $itemsPerPage ?? 24;

        return $this;
    }

    /**
     * Zeilen „Modelle für die Erkennung“ als Arrays (für das Formular, App\Form\Admin\AiRecognizerType).
     *
     * @return list<array{provider: string, model: string|null, active: bool, priceInput: float|null, priceOutput: float|null}>
     */
    public function getAiRecognizers(): array
    {
        return array_map(static fn (RecognizerConfig $row): array => $row->toArray(), $this->recognizerConfigs());
    }

    /**
     * Ungültige Zeilen (unbekannter Anbieter) fallen weg, die Reihenfolge bleibt.
     *
     * @param iterable<mixed>|null $rows
     */
    public function setAiRecognizers(?iterable $rows): static
    {
        $clean = [];
        foreach ($rows ?? [] as $row) {
            $config = \is_array($row) ? RecognizerConfig::fromArray($row) : ($row instanceof RecognizerConfig ? $row : null);
            if (null !== $config) {
                $clean[] = $config->toArray();
            }
        }
        $this->aiRecognizers = [] === $clean ? null : $clean;

        return $this;
    }

    /** @return list<RecognizerConfig> alle Zeilen, auch inaktive */
    public function recognizerConfigs(): array
    {
        $rows = [];
        foreach ($this->aiRecognizers ?? [] as $row) {
            $config = RecognizerConfig::fromArray($row);
            if (null !== $config) {
                $rows[] = $config;
            }
        }

        return $rows;
    }

    /** Oberste aktive Zeile (Hauptzeile), null = keine Zeile eingetragen */
    public function primaryRecognizer(): ?RecognizerConfig
    {
        foreach ($this->recognizerConfigs() as $row) {
            if ($row->active) {
                return $row;
            }
        }

        return null;
    }

    public function getAiTranslationModel(): ?string
    {
        return $this->aiTranslationModel;
    }

    public function setAiTranslationModel(?string $aiTranslationModel): static
    {
        $this->aiTranslationModel = null === $aiTranslationModel || '' === trim($aiTranslationModel) ? null : trim($aiTranslationModel);

        return $this;
    }

    public function getAiRecognitionPrompt(): ?string
    {
        return $this->aiRecognitionPrompt;
    }

    public function setAiRecognitionPrompt(?string $prompt): static
    {
        $this->aiRecognitionPrompt = null === $prompt || '' === trim($prompt) ? null : $prompt;

        return $this;
    }

    public function getAiTranslationPrompt(): ?string
    {
        return $this->aiTranslationPrompt;
    }

    public function setAiTranslationPrompt(?string $prompt): static
    {
        $this->aiTranslationPrompt = null === $prompt || '' === trim($prompt) ? null : $prompt;

        return $this;
    }

    public function getAiDuplicatePrompt(): ?string
    {
        return $this->aiDuplicatePrompt;
    }

    public function setAiDuplicatePrompt(?string $prompt): static
    {
        $this->aiDuplicatePrompt = null === $prompt || '' === trim($prompt) ? null : $prompt;

        return $this;
    }

    public function isDuplicateCheck(): bool
    {
        return $this->duplicateCheck;
    }

    public function setDuplicateCheck(?bool $duplicateCheck): static
    {
        $this->duplicateCheck = (bool) $duplicateCheck;

        return $this;
    }

    public function getDuplicateThreshold(): float
    {
        return $this->duplicateThreshold;
    }

    public function setDuplicateThreshold(?float $threshold): static
    {
        $this->duplicateThreshold = $threshold ?? 0.85;

        return $this;
    }

    public function getDuplicateHintThreshold(): float
    {
        return $this->duplicateHintThreshold;
    }

    public function setDuplicateHintThreshold(?float $threshold): static
    {
        $this->duplicateHintThreshold = $threshold ?? 0.5;

        return $this;
    }

    public function isDuplicateAutoReplacePublished(): bool
    {
        return $this->duplicateAutoReplacePublished;
    }

    public function setDuplicateAutoReplacePublished(?bool $autoReplace): static
    {
        $this->duplicateAutoReplacePublished = (bool) $autoReplace;

        return $this;
    }

    #[Assert\Callback]
    public function validateDuplicateThresholds(ExecutionContextInterface $context): void
    {
        if ($this->duplicateHintThreshold > $this->duplicateThreshold) {
            $context->buildViolation('Die Schwelle für den Hinweis darf nicht über der Schwelle für das automatische Zuordnen liegen.')
                ->atPath('duplicateHintThreshold')
                ->addViolation();
        }
    }

    /** Sind Zeilen eingetragen, muss mindestens eine aktiv sein – sonst gäbe es keine Hauptzeile. */
    #[Assert\Callback]
    public function validateRecognizers(ExecutionContextInterface $context): void
    {
        $rows = $this->recognizerConfigs();
        if (\count($rows) > self::MAX_RECOGNIZERS) {
            $context->buildViolation(sprintf('Höchstens %d Modelle für die Erkennung.', self::MAX_RECOGNIZERS))
                ->atPath('aiRecognizers')
                ->addViolation();
        }
        if ([] !== $rows && null === $this->primaryRecognizer()) {
            $context->buildViolation('Mindestens ein Modell muss aktiv sein. Ohne Zeilen gilt der Standard aus .env.')
                ->atPath('aiRecognizers')
                ->addViolation();
        }
    }

    /** Verschlüsselter Key des Anbieters – zum Entschlüsseln App\Ai\AiCredentials verwenden */
    public function getApiKey(string $provider): ?string
    {
        return match ($provider) {
            'anthropic' => $this->anthropicApiKey,
            'mistral' => $this->mistralApiKey,
            'openai' => $this->openaiApiKey,
            default => $this->geminiApiKey,
        };
    }

    public function setApiKey(string $provider, ?string $encrypted): static
    {
        match ($provider) {
            'anthropic' => $this->anthropicApiKey = $encrypted,
            'mistral' => $this->mistralApiKey = $encrypted,
            'openai' => $this->openaiApiKey = $encrypted,
            default => $this->geminiApiKey = $encrypted,
        };

        return $this;
    }

    /**
     * Formularfeld „API-Keys“ – bleibt immer leer.
     *
     * @return array<string, mixed>
     */
    public function getNewApiKeys(): array
    {
        return [];
    }

    /** @param array<string, mixed>|null $keys */
    public function setNewApiKeys(?array $keys): static
    {
        $this->newApiKeys = $keys ?? [];

        return $this;
    }

    /**
     * Liefert die im Formular eingegebenen Änderungen genau einmal: Anbieter => neuer Key, oder null = entfernen.
     *
     * @return array<string, string|null>
     */
    public function takeNewApiKeys(): array
    {
        $changes = [];
        foreach ($this->newApiKeys as $provider => $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $key = \is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            if ('' !== $key) {
                $changes[(string) $provider] = $key;
            } elseif (true === ($entry['remove'] ?? false)) {
                $changes[(string) $provider] = null;
            }
        }
        $this->newApiKeys = [];

        return $changes;
    }

    /** Für das Formular: gespeicherter Prompt oder der Standardtext */
    public function getRecognitionPromptText(): string
    {
        return $this->aiRecognitionPrompt ?? DefaultPrompts::RECOGNITION;
    }

    /** Unveränderter Standardtext wird nicht gespeichert – so wirken spätere Verbesserungen automatisch. */
    public function setRecognitionPromptText(?string $text): static
    {
        return $this->setAiRecognitionPrompt(self::differsFrom($text, DefaultPrompts::RECOGNITION));
    }

    public function getTranslationPromptText(): string
    {
        return $this->aiTranslationPrompt ?? DefaultPrompts::TRANSLATION;
    }

    public function setTranslationPromptText(?string $text): static
    {
        return $this->setAiTranslationPrompt(self::differsFrom($text, DefaultPrompts::TRANSLATION));
    }

    public function getDuplicatePromptText(): string
    {
        return $this->aiDuplicatePrompt ?? DefaultPrompts::DUPLICATE;
    }

    public function setDuplicatePromptText(?string $text): static
    {
        return $this->setAiDuplicatePrompt(self::differsFrom($text, DefaultPrompts::DUPLICATE));
    }

    private static function differsFrom(?string $text, string $default): ?string
    {
        $text = trim(str_replace("\r\n", "\n", (string) $text));

        return '' === $text || $text === trim($default) ? null : $text;
    }

    public function isAllowAiCrawlers(): bool
    {
        return $this->allowAiCrawlers;
    }

    public function setAllowAiCrawlers(?bool $allow): static
    {
        $this->allowAiCrawlers = (bool) $allow;

        return $this;
    }

    public function getMessageRetentionMonths(): int
    {
        return $this->messageRetentionMonths;
    }

    public function setMessageRetentionMonths(?int $months): static
    {
        $this->messageRetentionMonths = $months ?? 6;

        return $this;
    }
}
