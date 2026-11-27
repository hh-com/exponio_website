<?php

namespace App\Ai;

/**
 * Ein KI-Anbieter: schickt Text- und Bildteile (siehe AiInput) und liefert die Antwort als Array nach dem JSON-Schema.
 */
interface AiClientInterface
{
    /**
     * usage: verbrauchte Tokens laut Antwort, Ausgabe einschließlich Denk-Tokens (null = nicht gemeldet).
     *
     * @param list<array<string, string>> $input
     * @param array<string, mixed>        $schema
     *
     * @return array{data: array<string, mixed>, model: string, usage: array{input: int|null, output: int|null}}
     *
     * @throws AiException
     */
    public function generateJson(string $model, array $input, array $schema): array;

    /** Kleine Testanfrage: prüft Key, Modell und strukturierte Ausgabe. Liefert den Modellnamen. */
    public function ping(string $model): string;
}
