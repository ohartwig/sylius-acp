<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

/**
 * The ACP product feed of one channel and locale: `FeedMetadata` and a stream
 * of `Product` objects (schema.feed.json, ACP 2026-04-17).
 *
 * The feed is published to an agent, never served by the shop: in ACP the Feed
 * API is hosted by the agent, and agents must not call feed endpoints on
 * merchants. See ExportFeedCommand and FeedPusher for the two publication
 * models.
 */
final class AcpFeedBuilder
{
    public function __construct(
        private readonly CatalogueStream $catalogue,
        private readonly AcpProductMapper $mapper,
        private readonly int $batchSize = 100,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function products(FeedScope $scope): \Generator
    {
        foreach ($this->catalogue->products($scope->channel, $scope->localeCode, $this->batchSize) as $product) {
            $mapped = $this->mapper->map($product, $scope);
            if (null !== $mapped) {
                yield $mapped;
            }
        }
    }

    /**
     * @return array{id: string, target_country?: string, updated_at: string}
     */
    public function metadata(string $feedId, ?string $targetCountry, \DateTimeImmutable $now): array
    {
        $metadata = ['id' => $feedId];
        if (null !== $targetCountry) {
            $metadata['target_country'] = $targetCountry;
        }
        $metadata['updated_at'] = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return $metadata;
    }
}
