<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider;

use JsonException;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetails;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Unleash\Client\DTO\Variant;
use Unleash\Client\Enum\VariantPayloadType;
use Unleash\Client\Unleash;
use Unleash\Client\UnleashBuilder;

final class UnleashFlagProvider extends AbstractProvider
{
    protected static string $NAME = 'UnleashFlagProvider';

    private readonly Unleash $client;

    public function __construct(
        UnleashBuilder $builder,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $builder->build();
        $this->setLogger($logger ?? new NullLogger());
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->success(
            $this->client->isEnabled($flagKey, ContextMapper::toUnleashContext($context, $this->logger()), $defaultValue),
        );
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveVariantValue(
            $flagKey,
            $defaultValue,
            $context,
            [VariantPayloadType::STRING, VariantPayloadType::CSV],
            static fn (string $value): string => $value,
        );
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveVariantValue(
            $flagKey,
            $defaultValue,
            $context,
            ['number'],
            fn (string $value): int => $this->parseInteger($value),
        );
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveVariantValue(
            $flagKey,
            $defaultValue,
            $context,
            ['number'],
            fn (string $value): float => $this->parseFloat($value),
        );
    }

    /**
     * @param mixed[] $defaultValue
     */
    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveVariantValue(
            $flagKey,
            $defaultValue,
            $context,
            [VariantPayloadType::JSON],
            fn (string $value): mixed => $this->parseJson($value),
        );
    }

    /**
     * @param array<string> $acceptedPayloadTypes
     * @param bool|string|int|float|mixed[]|null $defaultValue
     * @param callable(string): (bool|string|int|float|mixed[]|null) $parse
     */
    private function resolveVariantValue(
        string $flagKey,
        bool|string|int|float|array|null $defaultValue,
        ?EvaluationContext $context,
        array $acceptedPayloadTypes,
        callable $parse,
    ): ResolutionDetailsInterface {
        $variant = $this->client->getVariant($flagKey, ContextMapper::toUnleashContext($context, $this->logger()));
        if (!$variant->isEnabled()) {
            return $this->details($defaultValue, Reason::UNKNOWN, null, null, $variant->getName());
        }

        $payload = $variant->getPayload();
        if ($payload === null) {
            return $this->error(
                $defaultValue,
                ErrorCode::TYPE_MISMATCH(),
                'Variant payload is missing.',
                $variant->getName(),
            );
        }

        if (!in_array($payload->getType(), $acceptedPayloadTypes, true)) {
            return $this->error(
                $defaultValue,
                ErrorCode::TYPE_MISMATCH(),
                sprintf("Variant payload type '%s' is not supported for this resolver.", $payload->getType()),
                $variant->getName(),
            );
        }

        try {
            return $this->details($parse($payload->getValue()), Reason::TARGETING_MATCH, null, null, $variant->getName());
        } catch (JsonException) {
            return $this->error($defaultValue, ErrorCode::PARSE_ERROR(), 'Variant JSON payload could not be parsed.', $variant->getName());
        } catch (\InvalidArgumentException $exception) {
            return $this->error($defaultValue, ErrorCode::PARSE_ERROR(), $exception->getMessage(), $variant->getName());
        }
    }

    private function parseInteger(string $value): int
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Variant payload is not an integer.');
        }

        return (int) $value;
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    private function parseFloat(string $value): float
    {
        if ($value === '' || !is_numeric($value)) {
            throw new \InvalidArgumentException('Variant payload is not a number.');
        }

        return (float) $value;
    }

    /**
     * @return bool|string|int|float|mixed[]|null
     *
     * @throws JsonException
     */
    private function parseJson(string $value): bool|string|int|float|array|null
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) || is_bool($decoded) || is_string($decoded) || is_int($decoded) || is_float($decoded) || $decoded === null) {
            return $decoded;
        }

        return null;
    }

    /**
     * @param bool|string|int|float|mixed[]|null $value
     */
    private function success(bool|string|int|float|array|null $value, ?string $variant = null): ResolutionDetailsInterface
    {
        return $this->details($value, Reason::TARGETING_MATCH, null, null, $variant);
    }

    /**
     * @param bool|string|int|float|mixed[]|null $value
     */
    private function error(bool|string|int|float|array|null $value, ErrorCode $errorCode, string $message, ?string $variant = null): ResolutionDetailsInterface
    {
        return $this->details($value, Reason::ERROR, $errorCode, $message, $variant);
    }

    /**
     * @param bool|string|int|float|mixed[]|null $value
     */
    private function details(
        bool|string|int|float|array|null $value,
        ?string $reason = null,
        ?ErrorCode $errorCode = null,
        ?string $errorMessage = null,
        ?string $variant = null,
    ): ResolutionDetailsInterface {
        $details = new ResolutionDetails();
        $details->setValue($value);
        $details->setReason($reason);
        if ($variant !== null) {
            $details->setVariant($variant);
        }
        if ($errorCode !== null) {
            $details->setError(new ResolutionError($errorCode, $errorMessage));
        }

        return $details;
    }
}
