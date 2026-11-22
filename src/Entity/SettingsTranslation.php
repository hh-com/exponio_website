<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_settings_translation', columns: ['settings_id', 'locale'])]
class SettingsTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Settings $settings;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $tagline = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $intro = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $footerText = null;

    public function __construct(Settings $settings, string $locale)
    {
        parent::__construct($locale);
        $this->settings = $settings;
    }

    public static function fields(): array
    {
        return ['tagline', 'intro', 'footerText'];
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->settings;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        return $this->set('tagline', $tagline);
    }

    public function getIntro(): ?string
    {
        return $this->intro;
    }

    public function setIntro(?string $intro): static
    {
        return $this->set('intro', $intro);
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): static
    {
        return $this->set('footerText', $footerText);
    }
}
