<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_topic_translation', columns: ['topic_id', 'locale'])]
class TopicTranslation extends NameDescriptionTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Topic $topic;

    public function __construct(Topic $topic, string $locale)
    {
        parent::__construct($locale);
        $this->topic = $topic;
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->topic;
    }
}
