<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Typ eines frei definierbaren Merkmals einer Objektart.
 * Nur "select" und "boolean" sind als Filter im Katalog nutzbar.
 */
enum AttributeType: string implements TranslatableInterface
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Boolean = 'boolean';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.attribute_type.'.$this->value, [], 'messages', $locale);
    }

    public function isFilterable(): bool
    {
        return self::Select === $this || self::Boolean === $this;
    }
}
