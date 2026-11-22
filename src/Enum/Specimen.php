<?php

namespace App\Enum;

/**
 * Einschätzung der KI bei einem Duplikat: dasselbe physische Stück oder ein weiteres Exemplar derselben Ausgabe.
 */
enum Specimen: string
{
    case Same = 'same';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Same => 'gleiches Stück, neu fotografiert',
            self::Other => 'anderes Exemplar',
            self::Unknown => 'Exemplar unklar',
        };
    }
}
