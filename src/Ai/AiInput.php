<?php

namespace App\Ai;

/**
 * Anbieterneutrale Eingabeteile; jeder AiClientInterface übersetzt sie in sein eigenes Format.
 */
final class AiInput
{
    /** @return array{type: 'text', text: string} */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array{type: 'image', data: string, mime_type: string} */
    public static function image(string $base64, string $mimeType): array
    {
        return ['type' => 'image', 'data' => $base64, 'mime_type' => $mimeType];
    }
}
