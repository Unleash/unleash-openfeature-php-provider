<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider\Tests;

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Unleash\Client\UnleashBuilder;
use Unleash\OpenFeature\Provider\UnleashFlagProvider;

/**
 * @phpstan-type Expected array{value: mixed, variant?: string, errorCode?: string}
 * @phpstan-type Scenario array{
 *     id: string,
 *     flagKey: string,
 *     type: string,
 *     default: mixed,
 *     expect: Expected,
 *     context?: array<string, mixed>,
 *     requires?: list<string>
 * }
 */
final class ContractTest extends TestCase
{
    private const CAPABILITIES = ['localEval', 'perCallContext'];

    /**
     * @var array<string, string>
     */
    private const KNOWN_GAPS = [
        // 'example-scenario-id' => 'Reason this scenario is temporarily excluded',
    ];

    private static UnleashFlagProvider $provider;

    public static function setUpBeforeClass(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $features = file_get_contents(__DIR__ . '/../verifier/spec/fixtures/unleash-features.json');
        self::assertIsString($features);

        $builder = UnleashBuilder::create()
            ->withAppUrl('http://unleash-bootstrap.invalid/api')
            ->withInstanceId('verifier-not-a-real-token')
            ->withAppName('openfeature-php-verifier')
            ->withHeader('Authorization', 'verifier-not-a-real-token')
            ->withCacheHandler($cache)
            ->withStaleCacheHandler($cache)
            ->withMetricsCacheHandler($cache)
            ->withAutomaticRegistrationEnabled(false)
            ->withMetricsEnabled(false)
            ->withFetchingEnabled(false)
            ->withBootstrap($features);

        self::$provider = new UnleashFlagProvider($builder, new NullLogger());
        OpenFeatureAPI::getInstance()->setProvider(self::$provider);
    }

    /**
     * @return iterable<string, array{Scenario}>
     */
    public static function scenarios(): iterable
    {
        foreach (self::loadScenarios() as $scenario) {
            if (!self::supportsScenario($scenario)) {
                continue;
            }

            yield $scenario['id'] => [$scenario];
        }
    }

    /**
     * @param Scenario $scenario
     */
    #[DataProvider('scenarios')]
    public function testContractScenario(array $scenario): void
    {
        $knownGaps = self::knownGaps();
        if (isset($knownGaps[$scenario['id']])) {
            self::markTestSkipped($knownGaps[$scenario['id']]);
        }

        $details = $this->evaluate($scenario);
        $expected = $scenario['expect'];

        self::assertEquals($expected['value'], $details['value']);
        if (array_key_exists('variant', $expected)) {
            self::assertSame($expected['variant'], $details['variant']);
        }

        if (array_key_exists('errorCode', $expected)) {
            self::assertSame($expected['errorCode'], $details['errorCode']);
        } else {
            self::assertNull($details['errorCode']);
        }
    }

    /**
     * @param Scenario $scenario
     *
     * @return array{value: mixed, variant: ?string, errorCode: ?string}
     */
    private function evaluate(array $scenario): array
    {
        $client = OpenFeatureAPI::getInstance()->getClient('contract', 'contract');
        $context = $this->evaluationContext($scenario['context'] ?? null);

        $details = match ($scenario['type']) {
            'boolean' => $client->getBooleanDetails($scenario['flagKey'], self::boolDefault($scenario), $context),
            'string' => $client->getStringDetails($scenario['flagKey'], self::stringDefault($scenario), $context),
            'number' => $client->getFloatDetails($scenario['flagKey'], self::floatDefault($scenario), $context),
            'object' => self::$provider->resolveObjectValue($scenario['flagKey'], self::arrayDefault($scenario), $context),
            default => throw new \RuntimeException(sprintf("Unsupported scenario type '%s'", $scenario['type'])),
        };

        return [
            'value' => $details->getValue(),
            'variant' => $details->getVariant(),
            'errorCode' => $details->getError()?->getResolutionErrorCode()->getValue(),
        ];
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluationContext(?array $context): ?EvaluationContext
    {
        if ($context === null) {
            return null;
        }

        $targetingKey = $context['targetingKey'] ?? null;
        unset($context['targetingKey']);

        $attributes = [];
        foreach ($context as $key => $value) {
            $attributes[$key] = self::attributeValue($value);
        }

        return new EvaluationContext(
            is_string($targetingKey) ? $targetingKey : null,
            new Attributes($attributes),
        );
    }

    /**
     * @param Scenario $scenario
     */
    private static function supportsScenario(array $scenario): bool
    {
        foreach ($scenario['requires'] ?? [] as $capability) {
            if (!in_array($capability, self::CAPABILITIES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private static function knownGaps(): array
    {
        return self::KNOWN_GAPS;
    }

    /**
     * @return list<Scenario>
     */
    private static function loadScenarios(): array
    {
        $contract = json_decode(
            (string) file_get_contents(__DIR__ . '/../verifier/spec/contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($contract) || !isset($contract['scenarios']) || !is_array($contract['scenarios'])) {
            throw new \RuntimeException('Verifier contract does not contain a scenarios array.');
        }

        $scenarios = [];
        foreach ($contract['scenarios'] as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }

            $normalized = self::normalizeScenario($scenario);
            if ($normalized !== null) {
                $scenarios[] = $normalized;
            }
        }

        return $scenarios;
    }

    /**
     * @param array<mixed> $scenario
     *
     * @return Scenario|null
     */
    private static function normalizeScenario(array $scenario): ?array
    {
        if (
            !isset($scenario['id'], $scenario['flagKey'], $scenario['type'], $scenario['expect'])
            || !is_string($scenario['id'])
            || !is_string($scenario['flagKey'])
            || !is_string($scenario['type'])
            || !array_key_exists('default', $scenario)
            || !is_array($scenario['expect'])
            || !array_key_exists('value', $scenario['expect'])
        ) {
            return null;
        }

        $normalized = [
            'id' => $scenario['id'],
            'flagKey' => $scenario['flagKey'],
            'type' => $scenario['type'],
            'default' => $scenario['default'],
            'expect' => self::normalizeExpected($scenario['expect']),
        ];

        if (isset($scenario['context']) && is_array($scenario['context'])) {
            $normalized['context'] = self::stringKeyedArray($scenario['context']);
        }

        if (isset($scenario['requires']) && is_array($scenario['requires'])) {
            $normalized['requires'] = array_values(array_filter($scenario['requires'], is_string(...)));
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $expected
     *
     * @return Expected
     */
    private static function normalizeExpected(array $expected): array
    {
        $normalized = ['value' => $expected['value'] ?? null];
        if (isset($expected['variant']) && is_string($expected['variant'])) {
            $normalized['variant'] = $expected['variant'];
        }
        if (isset($expected['errorCode']) && is_string($expected['errorCode'])) {
            $normalized['errorCode'] = $expected['errorCode'];
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return bool|string|int|float|array<mixed>|null
     */
    private static function attributeValue(mixed $value): bool|string|int|float|array|null
    {
        if (is_array($value) || is_bool($value) || is_string($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /**
     * @param Scenario $scenario
     */
    private static function boolDefault(array $scenario): bool
    {
        return is_bool($scenario['default']) ? $scenario['default'] : false;
    }

    /**
     * @param Scenario $scenario
     */
    private static function stringDefault(array $scenario): string
    {
        return is_string($scenario['default']) ? $scenario['default'] : '';
    }

    /**
     * @param Scenario $scenario
     */
    private static function floatDefault(array $scenario): float
    {
        return is_int($scenario['default']) || is_float($scenario['default']) ? (float) $scenario['default'] : 0.0;
    }

    /**
     * @param Scenario $scenario
     *
     * @return array<mixed>
     */
    private static function arrayDefault(array $scenario): array
    {
        return is_array($scenario['default']) ? $scenario['default'] : [];
    }
}
