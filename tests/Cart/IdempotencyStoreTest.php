<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Koh\SyliusAcp\Http\AcpError;
use Koh\SyliusAcp\Http\IdempotencyStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class IdempotencyStoreTest extends TestCase
{
    public function testANewKeyIsMarkedInFlightAndASecondCallWaits(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());

        self::assertNull($store->begin('key_a /carts', 'k1', '{"a":1}'));

        $error = $this->error(fn () => $store->begin('key_a /carts', 'k1', '{"a":1}'));
        self::assertSame(409, $error->status);
        self::assertSame('idempotency_in_flight', $error->errorCode);
        self::assertSame(['Retry-After' => '1'], $error->headers);
    }

    public function testACompletedRequestIsReplayedWithItsStatus(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{"a":1,"b":[1,2]}');
        $store->complete('s', 'k', '{"a":1,"b":[1,2]}', 201, '{"id":"x"}');

        self::assertSame(['status' => 201, 'body' => '{"id":"x"}'], $store->begin('s', 'k', '{"b":[1.0,2],"a":1}'));
    }

    public function testTheSameKeyWithAnotherBodyIsAConflict(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{"a":1}');
        $store->complete('s', 'k', '{"a":1}', 200, '{}');

        $error = $this->error(fn () => $store->begin('s', 'k', '{"a":2}'));
        self::assertSame(422, $error->status);
        self::assertSame('idempotency_conflict', $error->errorCode);
        self::assertSame('invalid_request', $error->type);
    }

    public function testAConflictIsReportedEvenWhileTheFirstRequestRuns(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{"a":1}');

        self::assertSame(422, $this->error(fn () => $store->begin('s', 'k', '{"a":2}'))->status);
    }

    public function testKeysAreScopedToCallerAndPath(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('key_a /carts', 'k', '{}');

        self::assertNull($store->begin('key_b /carts', 'k', '{}'));
        self::assertNull($store->begin('key_a /carts/x/cancel', 'k', '{}'));
        self::assertNull($store->begin('key_a /carts', 'k2', '{}'));

        // Scope and key are separated, not just glued together.
        $store->begin('ab', 'c', '{}');
        self::assertNull($store->begin('a', 'bc', '{}'));
    }

    public function testServerErrorsAreNotStored(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{}');
        $store->complete('s', 'k', '{}', 500, '{}');

        self::assertNull($store->begin('s', 'k', '{}'));
    }

    public function testA499IsStillAResponseWorthReplaying(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{}');
        $store->complete('s', 'k', '{}', 499, 'x');

        self::assertSame(['status' => 499, 'body' => 'x'], $store->begin('s', 'k', '{}'));
    }

    public function testReleaseForgetsTheKey(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $store->begin('s', 'k', '{}');
        $store->release('s', 'k');

        self::assertNull($store->begin('s', 'k', '{}'));
    }

    public function testRetentionIsTwentyFourHoursAndInFlightMarkersAMinute(): void
    {
        $lifetimes = [];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('expiresAfter')->willReturnCallback(function (int $seconds) use (&$lifetimes, $item) {
            $lifetimes[] = $seconds;

            return $item;
        });
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->with(self::matchesRegularExpression('/^koh_acp_idem_[0-9a-f]{64}$/'))->willReturn($item);
        $pool->expects(self::exactly(2))->method('save')->willReturn(true);

        $store = new IdempotencyStore($pool);
        $store->begin('s', 'k', '{}');
        $store->complete('s', 'k', '{}', 200, '{}');

        self::assertSame([60, 86400], $lifetimes);
    }

    public function testBodiesAreComparedSemantically(): void
    {
        self::assertSame(IdempotencyStore::fingerprint('{"a":1,"b":2}'), IdempotencyStore::fingerprint('{ "b": 2, "a": 1 }'));
        self::assertSame(IdempotencyStore::fingerprint('{"a":1}'), IdempotencyStore::fingerprint('{"a":1.0}'));
        self::assertSame(IdempotencyStore::fingerprint('{"a":{"y":1,"x":2}}'), IdempotencyStore::fingerprint('{"a":{"x":2,"y":1}}'));
        self::assertSame(IdempotencyStore::fingerprint(''), IdempotencyStore::fingerprint('{}'));
        self::assertSame(IdempotencyStore::fingerprint(" \n"), IdempotencyStore::fingerprint('{}'));
        self::assertSame(hash('sha256', '{"a":1}'), IdempotencyStore::fingerprint('{ "a" : 1.0 }'));
        self::assertSame(hash('sha256', 'not json'), IdempotencyStore::fingerprint('not json'));

        self::assertNotSame(IdempotencyStore::fingerprint('{"a":null}'), IdempotencyStore::fingerprint('{}'), 'null clears, absent keeps');
        self::assertNotSame(IdempotencyStore::fingerprint('{"a":[1,2]}'), IdempotencyStore::fingerprint('{"a":[2,1]}'), 'arrays are ordered');
        self::assertNotSame(IdempotencyStore::fingerprint('{"a":1.5}'), IdempotencyStore::fingerprint('{"a":1}'));
        self::assertNotSame(IdempotencyStore::fingerprint('{}'), IdempotencyStore::fingerprint('[]'));
        self::assertNotSame(IdempotencyStore::fingerprint('{"a":"1"}'), IdempotencyStore::fingerprint('{"a":1}'));
        self::assertNotSame(IdempotencyStore::fingerprint('{'), IdempotencyStore::fingerprint('{}'));
        self::assertSame(IdempotencyStore::fingerprint('{'), IdempotencyStore::fingerprint('{'));
        self::assertNotSame(IdempotencyStore::fingerprint('{"a":"é/"}'), IdempotencyStore::fingerprint('{"a":"e/"}'));
    }

    public function testVeryLargeFloatsAreNotTurnedIntoIntegers(): void
    {
        self::assertNotSame(IdempotencyStore::fingerprint('{"a":1e300}'), IdempotencyStore::fingerprint('{"a":1}'));
    }

    private function error(callable $call): AcpError
    {
        try {
            $call();
        } catch (AcpError $error) {
            return $error;
        }
        self::fail('Expected an AcpError');
    }
}
