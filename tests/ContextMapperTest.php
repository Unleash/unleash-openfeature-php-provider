<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider\Tests;

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Unleash\OpenFeature\Provider\ContextMapper;

final class ContextMapperTest extends TestCase
{
    public function testMapsBaseFieldsAndMovesCustomFieldsIntoProperties(): void
    {
        $context = ContextMapper::toUnleashContext(
            new EvaluationContext(
                'target-user',
                new Attributes([
                    'userId' => 'attribute-user',
                    'sessionId' => 'session-1',
                    'remoteAddress' => '127.0.0.1',
                    'environment' => 'test',
                    'currentTime' => '2026-07-06T12:00:00+00:00',
                    'appName' => 'php-app',
                    'thing' => true,
                ]),
            ),
            new NullLogger(),
        );

        self::assertNotNull($context);
        self::assertSame('target-user', $context->getCurrentUserId());
        self::assertSame('session-1', $context->getSessionId());
        self::assertSame('127.0.0.1', $context->getIpAddress());
        self::assertSame('test', $context->getEnvironment());
        self::assertSame('php-app', $context->getCustomProperty('appName'));
        self::assertSame('true', $context->getCustomProperty('thing'));
    }

    public function testDiscardsUnrepresentableCustomProperties(): void
    {
        $context = ContextMapper::toUnleashContext(
            new EvaluationContext(
                null,
                new Attributes([
                    'nested' => ['thing' => 'test'],
                    'nullValue' => null,
                ]),
            ),
            new NullLogger(),
        );

        self::assertNotNull($context);
        self::assertFalse($context->hasCustomProperty('nested'));
        self::assertFalse($context->hasCustomProperty('nullValue'));
    }

    public function testDiscardsCurrentTimeWhenItIsNotADateOrString(): void
    {
        $context = ContextMapper::toUnleashContext(
            new EvaluationContext(null, new Attributes(['currentTime' => 123])),
            new NullLogger(),
        );

        self::assertNotNull($context);
        self::assertFalse($context->hasCustomProperty('currentTime'));
    }

    public function testReturnsNullForMissingContext(): void
    {
        self::assertNull(ContextMapper::toUnleashContext(null, new NullLogger()));
    }
}
