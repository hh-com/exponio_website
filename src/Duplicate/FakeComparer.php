<?php

namespace App\Duplicate;

use App\Entity\Item;
use App\Enum\ImageSide;
use App\Enum\Specimen;

/**
 * Deterministischer Vergleich ohne API-Aufruf (AI_PROVIDER=fake), für Entwicklung und Tests.
 *
 * - Nahezu gleiches Foto (Hash-Abstand ≤ HASH_STRONG): dasselbe Stück, Sicherheit 0,95.
 * - Gleiche Katalognummer oder ähnliches Foto: dasselbe Objekt, aber unsicher (0,7).
 * - Sonst kein Duplikat (0,15).
 * - Fotos: mehr Seiten (Vorder- und Rückseite) sind besser, bei gleicher Zahl entscheidet die Auflösung.
 */
final class FakeComparer implements ComparerInterface
{
    public function compare(ComparisonRequest $request): array
    {
        $matches = [];
        foreach ($request->candidates as $candidate) {
            $distance = $candidate->hashDistance;
            $samePhoto = $candidate->hasSamePhoto();
            $similar = $samePhoto || $candidate->catalogMatch || (null !== $distance && $distance <= CandidateFinder::HASH_MEDIUM);
            $matches[] = [
                'candidate_id' => (int) $candidate->item->getId(),
                'same_object' => $similar,
                'confidence' => $samePhoto ? 0.95 : ($similar ? 0.7 : 0.15),
                'specimen' => ($samePhoto ? Specimen::Same : Specimen::Unknown)->value,
                'condition' => $samePhoto ? 'same' : self::condition($request->item, $candidate->item),
                'photos' => self::photos($request->item, $candidate->item),
                'reason' => sprintf('Fake-Vergleich (AI_PROVIDER=fake): %s.', [] === $candidate->reasons ? 'ähnliche Angaben' : implode(', ', $candidate->reasons)),
            ];
        }

        return [
            'model' => 'fake',
            'data' => ['matches' => $matches, 'notes' => 'Fake-Vergleich – keine echte Bewertung.'],
        ];
    }

    private static function condition(Item $new, Item $old): string
    {
        $newClean = null === $new->getDefects();
        $oldClean = null === $old->getDefects();

        return match (true) {
            $newClean && !$oldClean => 'better',
            !$newClean && $oldClean => 'worse',
            default => 'same',
        };
    }

    private static function photos(Item $new, Item $old): string
    {
        [$newSides, $newPixels] = self::photoScore($new);
        [$oldSides, $oldPixels] = self::photoScore($old);
        if ($newSides !== $oldSides) {
            return $newSides > $oldSides ? 'better' : 'worse';
        }

        return match (true) {
            $newPixels > 1.2 * $oldPixels => 'better',
            $newPixels < 0.8 * $oldPixels => 'worse',
            default => 'same',
        };
    }

    /** @return array{int, int} Anzahl der Seiten (Vorder-/Rückseite) und Pixel des größten Fotos */
    private static function photoScore(Item $item): array
    {
        $sides = [];
        $pixels = 0;
        foreach ($item->getImages() as $image) {
            $side = $image->getSide();
            if (ImageSide::Front === $side || ImageSide::Back === $side) {
                $sides[$side->value] = true;
            }
            $pixels = max($pixels, $image->getWidth() * $image->getHeight());
        }

        return [\count($sides), $pixels];
    }
}
