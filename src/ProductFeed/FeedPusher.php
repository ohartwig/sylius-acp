<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;

/**
 * Publishes the catalogue to an agent-hosted Feed API by upsert
 * (`PATCH /feeds/{id}/products`), in batches.
 *
 * Each product is sent whole, as the RFC recommends for interoperability. An
 * upsert never removes anything, so a product the shop stopped selling stays
 * on the agent as it was; with $retireMissing the agent's product set is read
 * back and every product it holds that the shop no longer publishes is upserted
 * again with all variants `available: false, status: discontinued` — the RFC's
 * way to withdraw an item without a full replacement.
 */
final class FeedPusher
{
    /** The schema's Product and Variant properties; readback is trimmed to them before it is sent back. */
    private const PRODUCT_FIELDS = ['id', 'title', 'description', 'url', 'media', 'variants'];
    private const VARIANT_FIELDS = [
        'id', 'title', 'description', 'url', 'barcodes', 'price', 'list_price', 'unit_price', 'availability',
        'categories', 'condition', 'variant_options', 'media', 'seller', 'marketplace',
    ];

    public function __construct(
        private readonly AcpFeedBuilder $builder,
        private readonly FeedApiClient $client,
        private readonly int $batchSize = 100,
    ) {
    }

    /**
     * @param ?\Closure(int): void $progress called with the running product count after each request
     */
    public function push(FeedScope $scope, string $feedId, bool $retireMissing = false, ?\Closure $progress = null): PushReport
    {
        // Fails with the agent's feed_not_found before any product is built.
        $this->client->getFeed($feedId);

        // One random run id per push: a retry inside the client reuses the key
        // of its request, the next scheduled run gets new ones.
        $run = bin2hex(random_bytes(8));
        $sent = [];
        $batch = [];
        $count = 0;
        $requests = 0;

        foreach ($this->builder->products($scope) as $product) {
            $batch[] = $product;
            $sent[(string) $product['id']] = true;
            if (\count($batch) >= max(1, $this->batchSize)) {
                $count += $this->send($feedId, $batch, $run, $requests);
                $batch = [];
                $progress?->__invoke($count);
            }
        }
        if ([] !== $batch) {
            $count += $this->send($feedId, $batch, $run, $requests);
            $progress?->__invoke($count);
        }

        $retired = 0;
        if ($retireMissing) {
            $batch = [];
            foreach ($this->client->getProducts($feedId) as $held) {
                $id = $held['id'] ?? null;
                if (!\is_string($id) || isset($sent[$id])) {
                    continue;
                }
                $withdrawn = $this->withdrawn($held);
                if (null === $withdrawn) {
                    continue;
                }
                $batch[] = $withdrawn;
                if (\count($batch) >= max(1, $this->batchSize)) {
                    $retired += $this->send($feedId, $batch, $run, $requests);
                    $batch = [];
                }
            }
            if ([] !== $batch) {
                $retired += $this->send($feedId, $batch, $run, $requests);
            }
        }

        return new PushReport($count, $requests, $retired);
    }

    /** @param list<array<string, mixed>> $products */
    private function send(string $feedId, array $products, string $run, int &$requests): int
    {
        $key = 'koh-acp-'.substr(hash('sha256', $run.'|'.json_encode($products, \JSON_THROW_ON_ERROR)), 0, 40);
        $this->client->upsertProducts($feedId, $products, $key);
        ++$requests;

        return \count($products);
    }

    /**
     * The agent's copy of a product the shop no longer publishes, trimmed to
     * schema fields and with every variant withdrawn; null when there is
     * nothing left to withdraw.
     *
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function withdrawn(array $held): ?array
    {
        $product = array_intersect_key($held, array_flip(self::PRODUCT_FIELDS));
        $variants = [];
        $changed = false;
        foreach (\is_array($held['variants'] ?? null) ? $held['variants'] : [] as $variant) {
            if (!\is_array($variant) || !\is_string($variant['id'] ?? null) || !\is_string($variant['title'] ?? null)) {
                continue;
            }
            $variant = array_intersect_key($variant, array_flip(self::VARIANT_FIELDS));
            $withdrawn = ['available' => false, 'status' => 'discontinued'];
            $changed = $changed || ($variant['availability'] ?? null) !== $withdrawn;
            $variant['availability'] = $withdrawn;
            $variants[] = $variant;
        }

        if (!$changed) {
            return null;
        }
        $product['variants'] = $variants;

        return $product;
    }
}
