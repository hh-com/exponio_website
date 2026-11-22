<?php

namespace App\Localization;

use App\Cache\ContentCache;
use App\Entity\Language;
use App\Repository\LanguageRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Öffentlich sichtbare Sprachen der Website (gecacht).
 * Öffentlich = im Backend freigeschaltet und Oberfläche vollständig übersetzt.
 */
final class Locales implements ResetInterface
{
    /** Gilt, solange die Sprachtabelle noch leer ist (vor "app:setup") */
    private const FALLBACK = [
        ['code' => 'de', 'nativeName' => 'Deutsch', 'nameDe' => 'Deutsch', 'dir' => 'ltr'],
        ['code' => 'en', 'nativeName' => 'English', 'nameDe' => 'Englisch', 'dir' => 'ltr'],
    ];

    /** @var list<array{code: string, nativeName: string, nameDe: string, dir: string}>|null */
    private ?array $public = null;

    public function __construct(
        private readonly LanguageRepository $languages,
        private readonly ContentCache $cache,
    ) {
    }

    /** @return list<array{code: string, nativeName: string, nameDe: string, dir: string}> */
    public function public(): array
    {
        return $this->public ??= $this->cache->get('locales.public', function (): array {
            $list = [];
            foreach ($this->languages->findActive() as $language) {
                if ($language->isPublic()) {
                    $list[] = [
                        'code' => $language->getCode(),
                        'nativeName' => $language->getNativeName(),
                        'nameDe' => $language->getNameDe(),
                        'dir' => $language->getDirection(),
                    ];
                }
            }

            return [] === $list ? self::FALLBACK : $list;
        });
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->public(), 'code');
    }

    public function isPublic(string $code): bool
    {
        return Language::SOURCE === $code || \in_array($code, $this->codes(), true);
    }

    public function direction(string $code): string
    {
        return \in_array($code, Language::RTL, true) ? 'rtl' : 'ltr';
    }

    public function nativeName(string $code): string
    {
        foreach ($this->public() as $language) {
            if ($language['code'] === $code) {
                return $language['nativeName'];
            }
        }

        return $code;
    }

    /** Format für og:locale, z. B. de_DE */
    public static function ogLocale(string $code): string
    {
        return [
            'en' => 'en_GB', 'ja' => 'ja_JP', 'zh' => 'zh_CN', 'ko' => 'ko_KR', 'uk' => 'uk_UA', 'cs' => 'cs_CZ',
            'da' => 'da_DK', 'el' => 'el_GR', 'sv' => 'sv_SE', 'nb' => 'nb_NO', 'he' => 'he_IL', 'ar' => 'ar_AR',
            'fa' => 'fa_IR', 'hi' => 'hi_IN', 'vi' => 'vi_VN', 'sl' => 'sl_SI', 'et' => 'et_EE', 'ga' => 'ga_IE',
        ][$code] ?? $code.'_'.strtoupper($code);
    }

    public function reset(): void
    {
        $this->public = null;
    }
}
