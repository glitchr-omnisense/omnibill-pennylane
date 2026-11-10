<?php

namespace Omnibill\Pennylane\Tests;

use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\Pennylane\PennylaneGatewayFactory;
use Omnibill\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Pennylane's usage policy: declared in policy.json, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAndRead(): void
    {
        $declared = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame(['policy' => 'policy.json'], $extra['omnisense'] ?? null);
        self::assertSame('pennylane', $declared->provider);
        self::assertSame('2026-11-10', $declared->provenance->verifiedAt);
        self::assertSame($declared->toArray(), PennylaneGatewayFactory::usagePolicy()->toArray());
        self::assertNotNull($declared->rateLimits);
        self::assertSame(['25 every 5 seconds per key'], array_map(static fn ($limit) => $limit->describe(), $declared->rateLimits));
        self::assertSame(Permission::Forbidden, $declared->rights->redistribution);
        self::assertSame(Permission::Contract, $declared->rights->storage);

        $registry = new Registry([new PennylaneGatewayFactory(new MockHttpClient())], ['pennylane' => ['factory' => 'pennylane', 'options' => ['token' => 't']]]);
        self::assertSame($declared->toArray(), $registry->policy('pennylane')->toArray());
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30', 'content-type' => 'application/json']]);
        });
        $registry = new Registry([new PennylaneGatewayFactory($http)], ['pennylane' => ['factory' => 'pennylane', 'options' => ['token' => 't']]]);

        try {
            $registry->journalEntries('pennylane')->entries();
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $registry->journalEntries('pennylane')->entries();
            self::fail('A call went out while Pennylane asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertSame(1, $requests, 'the wait held the next request back');
    }

    public function testItsRatelimitResetIsReadAsAUnixTime(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['ratelimit-limit' => '25', 'ratelimit-remaining' => '0', 'ratelimit-reset' => (string) (time() + 40)]]));
        $registry = new Registry([new PennylaneGatewayFactory($http)], ['pennylane' => ['factory' => 'pennylane', 'options' => ['token' => 't']]]);

        try {
            $registry->journalEntries('pennylane')->entries();
            self::fail('A 429 was read as entries.');
        } catch (UsageLimitReachedException $e) {
            self::assertNotNull($e->retryAfter);
            self::assertGreaterThanOrEqual(38, $e->retryAfter);
            self::assertLessThanOrEqual(40, $e->retryAfter);
        }
    }
}
