<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ScanStatus: string implements TranslatableInterface
{
    case Imported = 'imported';
    case Failed = 'failed';
    /** Ordner wurde als Duplikat einem vorhandenen Objekt zugeordnet */
    case Merged = 'merged';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.scan_status.'.$this->value, [], 'messages', $locale);
    }
}
