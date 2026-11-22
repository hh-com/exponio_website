<?php

namespace App\Twig;

use Symfony\Component\Asset\Packages;

/**
 * Bindet Vite-Einträge ein: im Build über public/build/.vite/manifest.json,
 * in der Entwicklung (VITE_DEV_SERVER gesetzt) direkt vom Dev-Server.
 */
final class Vite
{
    private const PRELOAD_FONTS = [
        'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2',
        'node_modules/@fontsource-variable/source-serif-4/files/source-serif-4-latin-wght-normal.woff2',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $publicDir,
        private readonly string $viteDevServer,
        private readonly Packages $packages,
    ) {
    }

    public function tags(string $entry): string
    {
        $dev = rtrim(trim($this->viteDevServer), '/');
        if ('' !== $dev) {
            return sprintf(
                '<script type="module" src="%1$s/build/@vite/client"></script><script type="module" src="%1$s/build/%2$s"></script>',
                htmlspecialchars($dev),
                htmlspecialchars($entry),
            );
        }

        $manifest = $this->manifest();
        if (!isset($manifest[$entry])) {
            return '<!-- Vite: "'.htmlspecialchars($entry).'" nicht im Manifest – "npm run build" ausführen -->';
        }

        $html = '';
        // Lateinische Grundschriften vorab laden (kürzere Wartezeit bis zum ersten Text) – nur, wenn der Eintrag
        // sie benutzt: Das Backend hat eigene Schriften und würde sonst zwei unnötige Dateien laden
        $assets = $manifest[$entry]['assets'] ?? [];
        foreach (self::PRELOAD_FONTS as $font) {
            $file = $manifest[$font]['file'] ?? null;
            if (\is_string($file) && \is_array($assets) && \in_array($file, $assets, true)) {
                $html .= '<link rel="preload" as="font" type="font/woff2" crossorigin href="'.$this->url($file).'">';
            }
        }
        foreach ($this->collectCss($entry, $manifest) as $css) {
            $html .= '<link rel="stylesheet" href="'.$this->url($css).'">';
        }
        $html .= '<script type="module" src="'.$this->url($manifest[$entry]['file']).'"></script>';
        foreach ($manifest[$entry]['imports'] ?? [] as $import) {
            if (isset($manifest[$import]['file'])) {
                $html .= '<link rel="modulepreload" href="'.$this->url($manifest[$import]['file']).'">';
            }
        }

        return $html;
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @param array<string, true>                 $seen
     *
     * @return list<string>
     */
    private function collectCss(string $chunk, array $manifest, array &$seen = []): array
    {
        if (isset($seen[$chunk])) {
            return [];
        }
        $seen[$chunk] = true;
        $css = $manifest[$chunk]['css'] ?? [];
        foreach ($manifest[$chunk]['imports'] ?? [] as $import) {
            array_push($css, ...$this->collectCss($import, $manifest, $seen));
        }

        return array_values(array_unique($css));
    }

    private function url(string $file): string
    {
        return htmlspecialchars($this->packages->getUrl('build/'.$file));
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        if (null !== $this->manifest) {
            return $this->manifest;
        }
        $file = $this->publicDir.'/build/.vite/manifest.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->manifest = \is_array($data) ? $data : [];
    }
}
