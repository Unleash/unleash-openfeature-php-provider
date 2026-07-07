<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider;

use DateTimeImmutable;
use OpenFeature\interfaces\flags\EvaluationContext;
use Psr\Log\LoggerInterface;
use Unleash\Client\Configuration\UnleashContext;

final class ContextMapper
{
    /**
     * @var array<string, true>
     */
    private const BASE_FIELDS = [
        'currentTime' => true,
        'userId' => true,
        'sessionId' => true,
        'remoteAddress' => true,
        'environment' => true,
    ];

    public static function toUnleashContext(?EvaluationContext $evaluationContext, LoggerInterface $logger): ?UnleashContext
    {
        if ($evaluationContext === null) {
            return null;
        }

        $attributes = $evaluationContext->getAttributes()->toArray();
        $targetingKey = $evaluationContext->getTargetingKey();
        if ($targetingKey !== null) {
            $attributes['userId'] = $targetingKey;
        }

        $properties = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (isset(self::BASE_FIELDS[$key])) {
                continue;
            }

            if (is_array($value)) {
                $logger->debug('Discarding nested OpenFeature context property for Unleash.', ['key' => $key]);
                continue;
            }

            $stringValue = self::stringify($value);
            if ($stringValue === null) {
                $logger->debug('Discarding unrepresentable OpenFeature context property for Unleash.', ['key' => $key]);
                continue;
            }

            $properties[$key] = $stringValue;
        }

        return new UnleashContext(
            currentUserId: self::nullableString($attributes['userId'] ?? null),
            ipAddress: self::nullableString($attributes['remoteAddress'] ?? null),
            sessionId: self::nullableString($attributes['sessionId'] ?? null),
            customContext: $properties,
            environment: self::nullableString($attributes['environment'] ?? null),
            currentTime: self::currentTime($attributes['currentTime'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::stringify($value);
    }

    private static function stringify(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function currentTime(mixed $value): DateTimeImmutable|string|null
    {
        if ($value === null || $value instanceof DateTimeImmutable || is_string($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return null;
    }
}
