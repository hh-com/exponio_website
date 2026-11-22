<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum InquiryStatus: string implements TranslatableInterface
{
    case New = 'new';
    case Answered = 'answered';
    case Closed = 'closed';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.inquiry_status.'.$this->value, [], 'messages', $locale);
    }
}
