<?php

namespace App\Enum;

/**
 * Herkunft eines übersetzten Texts.
 */
enum TranslationOrigin: string
{
    /** Ausgangstext auf Deutsch */
    case Source = 'source';
    /** von der KI übersetzt */
    case Ai = 'ai';
    /** von Hand geschrieben oder korrigiert */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Source => 'Ausgangstext',
            self::Ai => 'KI',
            self::Manual => 'von Hand',
        };
    }
}
