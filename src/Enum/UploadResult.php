<?php

namespace App\Enum;

/**
 * Ergebnis eines Uploads: neues Objekt oder Duplikat eines vorhandenen Objekts.
 */
enum UploadResult: string
{
    case New = 'new';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neues Objekt',
            self::Duplicate => 'Duplikat',
        };
    }
}
