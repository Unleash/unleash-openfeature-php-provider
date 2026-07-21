<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider\Tests;

use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use PHPUnit\Framework\TestCase;
use Unleash\Client\Enum\VariantPayloadType;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Unleash\Client\UnleashBuilder;
use Unleash\OpenFeature\Provider\UnleashFlagProvider;

final class UnleashFlagProviderTest extends TestCase
{
    public function testResolvesBooleanFlagsThroughUnleash(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('enabled', enabled: true),
        ]);

        $details = $provider->resolveBooleanValue('enabled', false);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
    }

    public function testResolvesStringVariantPayloads(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('blue', VariantPayloadType::STRING, 'hello'),
            ]),
        ]);

        $details = $provider->resolveStringValue('variant', 'default');

        self::assertSame('hello', $details->getValue());
        self::assertSame('blue', $details->getVariant());
    }

    public function testResolvesCsvVariantPayloadsAsStrings(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('csv', VariantPayloadType::CSV, 'a,b,c'),
            ]),
        ]);

        $details = $provider->resolveStringValue('variant', 'default');

        self::assertSame('a,b,c', $details->getValue());
    }

    public function testResolvesNumberVariantPayloads(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('number', 'number', '12.5'),
            ]),
        ]);

        $details = $provider->resolveFloatValue('variant', 0.0);

        self::assertSame(12.5, $details->getValue());
    }

    public function testReportsParseErrorsForEmptyNumberPayloads(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('number', 'number', ''),
            ]),
        ]);

        $details = $provider->resolveFloatValue('variant', 0.0);

        self::assertSame(0.0, $details->getValue());
        self::assertEquals(ErrorCode::PARSE_ERROR(), $details->getError()?->getResolutionErrorCode());
    }

    public function testResolvesJsonObjectPayloads(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('json', VariantPayloadType::JSON, '{"thing":"test"}'),
            ]),
        ]);

        $details = $provider->resolveObjectValue('variant', []);

        self::assertSame(['thing' => 'test'], $details->getValue());
    }

    public function testResolvesJsonArrayPayloads(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', variants: [
                $this->variant('json', VariantPayloadType::JSON, '[1,2,3]'),
            ]),
        ]);

        $details = $provider->resolveObjectValue('variant', []);

        self::assertSame([1, 2, 3], $details->getValue());
    }

    public function testReturnsDefaultWhenVariantIsDisabled(): void
    {
        $provider = $this->providerWithFeatures([
            $this->feature('variant', enabled: false, variants: [
                $this->variant('disabled', VariantPayloadType::STRING, 'unused'),
            ]),
        ]);

        $details = $provider->resolveStringValue('variant', 'default');

        self::assertSame('default', $details->getValue());
        self::assertSame(Reason::UNKNOWN, $details->getReason());
        self::assertSame('disabled', $details->getVariant());
    }

    /**
     * @param array<array<string, mixed>> $features
     */
    private function providerWithFeatures(array $features): UnleashFlagProvider
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $builder = UnleashBuilder::create()
            ->withAppUrl('http://unleash-bootstrap.invalid/api')
            ->withInstanceId('test-token')
            ->withAppName('openfeature-php-test')
            ->withCacheHandler($cache)
            ->withStaleCacheHandler($cache)
            ->withMetricsCacheHandler($cache)
            ->withAutomaticRegistrationEnabled(false)
            ->withMetricsEnabled(false)
            ->withFetchingEnabled(false)
            ->withBootstrap([
                'features' => $features,
            ]);

        return new UnleashFlagProvider($builder);
    }

    /**
     * @param array<array<string, mixed>> $variants
     *
     * @return array<string, mixed>
     */
    private function feature(string $name, bool $enabled = true, array $variants = []): array
    {
        return [
            'name' => $name,
            'type' => 'release',
            'enabled' => $enabled,
            'strategies' => [
                [
                    'name' => 'default',
                    'parameters' => [],
                    'constraints' => [],
                ],
            ],
            'variants' => $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(string $name, string $payloadType, string $payloadValue): array
    {
        return [
            'name' => $name,
            'weight' => 1000,
            'stickiness' => 'default',
            'payload' => [
                'type' => $payloadType,
                'value' => $payloadValue,
            ],
        ];
    }
}
