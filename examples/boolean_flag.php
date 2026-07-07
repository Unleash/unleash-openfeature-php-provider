<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;
use Unleash\Client\UnleashBuilder;
use Unleash\OpenFeature\Provider\UnleashFlagProvider;

$options = getopt('', ['url:', 'api-key:', 'flag-key:', 'targeting-key::']);

foreach (['url', 'api-key', 'flag-key'] as $requiredOption) {
    if (!isset($options[$requiredOption]) || !is_string($options[$requiredOption])) {
        fwrite(STDERR, sprintf("Missing required option --%s\n", $requiredOption));
        exit(1);
    }
}

$unleash = UnleashBuilder::create()
    ->withAppUrl($options['url'])
    ->withInstanceId($options['api-key'])
    ->withAppName('openfeature-php-example')
    ->withHeader('Authorization', $options['api-key'])
    ->withMetricsEnabled(false)
    ->build();

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new UnleashFlagProvider($unleash));

$client = $api->getClient('example');
$context = new EvaluationContext(
    isset($options['targeting-key']) && is_string($options['targeting-key']) ? $options['targeting-key'] : null,
    new Attributes(),
);

$enabled = $client->getBooleanValue($options['flag-key'], false, $context);

echo $enabled ? "true\n" : "false\n";
