<?php

namespace App\Enum;

/**
 * Stand der Duplikatprüfung eines Objekts. Null bedeutet: nie geprüft (Bestand vor der Duplikat-Erkennung,
 * im Backend angelegt). Nur frisch importierte Objekte (pending) werden automatisch zusammengeführt.
 */
enum DuplicateStatus: string
{
    case Pending = 'pending';
    case Unique = 'unique';
    case Possible = 'possible';
    case Separate = 'separate';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'wird nach der Erkennung geprüft',
            self::Unique => 'kein Duplikat gefunden',
            self::Possible => 'mögliches Duplikat',
            self::Separate => 'eigenes Objekt (bestätigt)',
        };
    }
}
