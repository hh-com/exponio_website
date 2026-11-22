<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Foto zeigt. Die KI setzt den Wert, im Backend lässt er sich korrigieren.
 */
enum ImageSide: string implements TranslatableInterface
{
    case Front = 'front';
    case Back = 'back';
    case Detail = 'detail';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.image_side.'.$this->value, [], 'messages', $locale);
    }

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Vorderseite',
            self::Back => 'Rückseite',
            self::Detail => 'Detail',
        };
    }
}
