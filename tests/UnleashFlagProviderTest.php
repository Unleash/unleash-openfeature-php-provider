<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider\Tests;

use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use PHPUnit\Framework\TestCase;
use Unleash\Client\DTO\DefaultVariant;
use Unleash\Client\DTO\DefaultVariantPayload;
use Unleash\Client\Enum\VariantPayloadType;
use Unleash\OpenFeature\Provider\UnleashFlagProvider;

final class UnleashFlagProviderTest extends TestCase
{
    private FakeUnleash $client;
    private UnleashFlagProvider $provider;

    protected function setUp(): void
    {
        $this->client = new FakeUnleash();
        $this->provider = new UnleashFlagProvider($this->client);
    }

    public function testResolvesBooleanFlagsThroughUnleash(): void
    {
        $this->client->setFlag('enabled', true);

        $details = $this->provider->resolveBooleanValue('enabled', false);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
    }

    public function testResolvesStringVariantPayloads(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('blue', true, payload: new DefaultVariantPayload(VariantPayloadType::STRING, 'hello')),
        );

        $details = $this->provider->resolveStringValue('variant', 'default');

        self::assertSame('hello', $details->getValue());
        self::assertSame('blue', $details->getVariant());
    }

    public function testResolvesCsvVariantPayloadsAsStrings(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('csv', true, payload: new DefaultVariantPayload(VariantPayloadType::CSV, 'a,b,c')),
        );

        $details = $this->provider->resolveStringValue('variant', 'default');

        self::assertSame('a,b,c', $details->getValue());
    }

    public function testResolvesNumberVariantPayloads(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('number', true, payload: new DefaultVariantPayload('number', '12.5')),
        );

        $details = $this->provider->resolveFloatValue('variant', 0.0);

        self::assertSame(12.5, $details->getValue());
    }

    public function testReportsParseErrorsForEmptyNumberPayloads(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('number', true, payload: new DefaultVariantPayload('number', '')),
        );

        $details = $this->provider->resolveFloatValue('variant', 0.0);

        self::assertSame(0.0, $details->getValue());
        self::assertEquals(ErrorCode::PARSE_ERROR(), $details->getError()?->getResolutionErrorCode());
    }

    public function testResolvesJsonObjectPayloads(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('json', true, payload: new DefaultVariantPayload(VariantPayloadType::JSON, '{"thing":"test"}')),
        );

        $details = $this->provider->resolveObjectValue('variant', []);

        self::assertSame(['thing' => 'test'], $details->getValue());
    }

    public function testResolvesJsonArrayPayloads(): void
    {
        $this->client->setVariant(
            'variant',
            new DefaultVariant('json', true, payload: new DefaultVariantPayload(VariantPayloadType::JSON, '[1,2,3]')),
        );

        $details = $this->provider->resolveObjectValue('variant', []);

        self::assertSame([1, 2, 3], $details->getValue());
    }

    public function testReturnsDefaultWhenVariantIsDisabled(): void
    {
        $this->client->setVariant('variant', new DefaultVariant('disabled', false));

        $details = $this->provider->resolveStringValue('variant', 'default');

        self::assertSame('default', $details->getValue());
        self::assertSame(Reason::UNKNOWN, $details->getReason());
        self::assertSame('disabled', $details->getVariant());
    }
}
