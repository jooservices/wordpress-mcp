<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;

abstract class LiveTestCase extends TestCase
{
    protected PluginRestClient $client;

    protected Generator $faker;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_PLUGIN_E2E') !== '1') {
            self::markTestSkipped('Set RUN_PLUGIN_E2E=1 against a live WordPress stack.');
        }

        $this->client = PluginRestClient::fromEnvironment();
        $this->faker = Factory::create();
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{status: int, body: mixed}
     */
    protected function api(string $method, string $path, ?array $json = null, bool $auth = true): array
    {
        return $this->client->request($method, $path, $json, $auth);
    }
}
