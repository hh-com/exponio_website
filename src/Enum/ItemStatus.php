<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lebenszyklus eines Sammlungsobjekts:
 * draft (importiert, noch nicht erkannt) → review (KI-Vorschlag wartet auf Prüfung) → published | archived.
 */
enum ItemStatus: string implements TranslatableInterface
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.item_status.'.$this->value, [], 'messages', $locale);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Review => 'warning',
            self::Published => 'success',
            self::Archived => 'dark',
        };
    }
}
