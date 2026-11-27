<?php

namespace App\Recognition;

use App\Ai\RecognizerConfig;

interface RecognizerInterface
{
    /**
     * Bestimmt das Objekt mit dem Anbieter und Modell einer Zeile „Modelle für die Erkennung“ (Modell aufgelöst).
     *
     * @return array{data: array<string, mixed>, model: string, usage: array{input: int|null, output: int|null}}
     *
     * @throws RecognitionException
     * @throws \App\Ai\AiException
     */
    public function recognize(RecognitionRequest $request, RecognizerConfig $config): array;
}
