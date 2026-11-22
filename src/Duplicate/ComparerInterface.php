<?php

namespace App\Duplicate;

interface ComparerInterface
{
    /**
     * @return array{data: array<string, mixed>, model: string}
     *
     * @throws \App\Ai\AiException
     */
    public function compare(ComparisonRequest $request): array;
}
