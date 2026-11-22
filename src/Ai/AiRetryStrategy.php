<?php

namespace App\Ai;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Wiederholungen für die KI-Clients (config/packages/framework.yaml, retry_failed.retry_strategy).
 *
 * Wiederholt bei Überlastung (429, 503, Claude: 529) und Serverfehlern – auch für POST, denn die Anfragen
 * haben keine Nebenwirkungen – und meldet jeden neuen Versuch als Zwischenstand im Backend (AiProgress).
 */
final class AiRetryStrategy implements RetryStrategyInterface
{
    public const STATUS_CODES = [423, 425, 429, 500, 502, 503, 504, 529];

    private readonly GenericRetryStrategy $inner;

    public function __construct(private readonly AiProgress $progress)
    {
        $this->inner = new GenericRetryStrategy(self::STATUS_CODES, 2000, 2.0);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $retry = $this->inner->shouldRetry($context, $responseContent, $exception);
        if (true === $retry) {
            $retryCount = $context->getInfo('retry_count');
            $attempt = (\is_int($retryCount) ? $retryCount : 0) + 2;
            $this->progress->step(null !== $exception
                ? sprintf('%s ist nicht erreichbar – Versuch %d folgt gleich …', $this->progress->aiName(), $attempt)
                : sprintf('%s ist überlastet (Fehler %d) – Versuch %d folgt gleich …', $this->progress->aiName(), $context->getStatusCode(), $attempt));
        }

        return $retry;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->inner->getDelay($context, $responseContent, $exception);
    }
}
