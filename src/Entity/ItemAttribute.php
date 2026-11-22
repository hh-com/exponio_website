<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Wert eines Merkmals für ein Objekt (z. B. Erhaltung = "mnh").
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_item_definition', columns: ['item_id', 'definition_id'])]
#[ORM\Index(name: 'idx_attribute_value', columns: ['definition_id', 'value'])]
class ItemAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attributeValues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column(length: 191)]
    private string $value;

    public function __construct(Item $item, AttributeDefinition $definition, string $value)
    {
        $this->item = $item;
        $this->definition = $definition;
        $this->value = mb_substr($value, 0, 191);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getDefinition(): AttributeDefinition
    {
        return $this->definition;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = mb_substr($value, 0, 191);

        return $this;
    }
}
