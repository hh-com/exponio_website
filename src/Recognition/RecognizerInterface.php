<?php

namespace App\Recognition;

interface RecognizerInterface
{
    /**
     * @return array{data: array<string, mixed>, model: string}
     *
     * @throws RecognitionException
     */
    public function recognize(RecognitionRequest $request): array;
}
