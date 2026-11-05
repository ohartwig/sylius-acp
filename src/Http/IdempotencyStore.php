<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Http;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Idempotency for ACP writes (rfc.agentic_checkout.md §6, which the cart RFC
 * adopts).
 *
 * Keys are scoped to the caller and the endpoint path (§6.1). The same key with
 * the same body replays the stored response, status included (§6.3); with a
 * different body it is a 422 `idempotency_conflict`; while the first request
 * still runs it is a 409 `idempotency_in_flight` (§6.4). 5xx responses are never
 * stored (§6.5); stored responses live 24 hours (§6.6).
 *
 * Bodies are compared semantically (§6.2): key order does not matter, `1.0`
 * equals `1`, `null` differs from an absent key and array order counts.
 */
final class IdempotencyStore
{
    public const RETENTION_SECONDS = 86400;

    /** How long an in-flight marker survives a request that died mid-way. */
    public const IN_FLIGHT_SECONDS = 60;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{status: int, body: string}|null the stored response to replay, or null when the request is new and now marked in flight
     *
     * @throws AcpError on a conflicting body or a request still in flight
     */
    public function begin(string $scope, string $key, string $body): ?array
    {
        $item = $this->cache->getItem($this->cacheKey($scope, $key));
        $fingerprint = self::fingerprint($body);

        if ($item->isHit()) {
            $stored = $item->get();
            if (\is_array($stored) && ($stored['fingerprint'] ?? null) !== $fingerprint) {
                throw AcpError::invalid('idempotency_conflict', 'Idempotency-Key has already been used with a different request body.', status: 422);
            }
            if (\is_array($stored) && \is_int($stored['status'] ?? null) && \is_string($stored['body'] ?? null)) {
                return ['status' => $stored['status'], 'body' => $stored['body']];
            }

            throw new AcpError(
                409,
                AcpError::TYPE_INVALID_REQUEST,
                'idempotency_in_flight',
                'A request with this Idempotency-Key is currently being processed.',
                headers: ['Retry-After' => '1'],
            );
        }

        $item->set(['fingerprint' => $fingerprint]);
        $item->expiresAfter(self::IN_FLIGHT_SECONDS);
        $this->cache->save($item);

        return null;
    }

    public function complete(string $scope, string $key, string $body, int $status, string $responseBody): void
    {
        if ($status >= 500) {
            $this->release($scope, $key);

            return;
        }

        $item = $this->cache->getItem($this->cacheKey($scope, $key));
        $item->set([
            'fingerprint' => self::fingerprint($body),
            'status' => $status,
            'body' => $responseBody,
        ]);
        $item->expiresAfter(self::RETENTION_SECONDS);
        $this->cache->save($item);
    }

    public function release(string $scope, string $key): void
    {
        $this->cache->deleteItem($this->cacheKey($scope, $key));
    }

    public static function fingerprint(string $body): string
    {
        if ('' === trim($body)) {
            $body = '{}';
        }

        try {
            // Re-encoding drops a float's zero fraction (`1.0` becomes `1`).
            return hash('sha256', json_encode(self::canonical(json_decode($body, flags: \JSON_THROW_ON_ERROR)), \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            // Not JSON: the parser refuses the request anyway; compare verbatim.
            return hash('sha256', $body);
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            ksort($members, \SORT_STRING);

            return (object) array_map(self::canonical(...), $members);
        }
        if (\is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    private function cacheKey(string $scope, string $key): string
    {
        // PSR-6 keys allow a narrow alphabet; hash what the client sent.
        return 'koh_acp_idem_'.hash('sha256', $scope."\0".$key);
    }
}
