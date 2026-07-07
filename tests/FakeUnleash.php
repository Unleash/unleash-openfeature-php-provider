<?php

declare(strict_types=1);

namespace Unleash\OpenFeature\Provider\Tests;

use Unleash\Client\Configuration\Context;
use Unleash\Client\DTO\DefaultVariant;
use Unleash\Client\DTO\Variant;
use Unleash\Client\Unleash;

final class FakeUnleash implements Unleash
{
    /**
     * @var array<string, bool>
     */
    private array $flags = [];

    /**
     * @var array<string, Variant>
     */
    private array $variants = [];

    public ?Context $lastContext = null;
    public int $registerCalls = 0;

    public function setFlag(string $featureName, bool $enabled): void
    {
        $this->flags[$featureName] = $enabled;
    }

    public function setVariant(string $featureName, Variant $variant): void
    {
        $this->variants[$featureName] = $variant;
    }

    public function isEnabled(string $featureName, ?Context $context = null, bool $default = false): bool
    {
        $this->lastContext = $context;

        return $this->flags[$featureName] ?? $default;
    }

    public function getVariant(string $featureName, ?Context $context = null, ?Variant $fallbackVariant = null): Variant
    {
        $this->lastContext = $context;

        return $this->variants[$featureName] ?? $fallbackVariant ?? new DefaultVariant('disabled', false);
    }

    public function register(): bool
    {
        ++$this->registerCalls;

        return true;
    }
}
