<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use PHPUnit\Framework\TestCase;

/**
 * The validator the other tests trust has to reject what the schema rejects;
 * a validator that accepts everything would make every other assertion here
 * vacuous.
 */
final class AcpFeedSchemaTest extends TestCase
{
    public function testTheSpecsOwnExampleValidates(): void
    {
        AcpFeedSchema::assertValid([
            'id' => 'prod_classic_tee',
            'title' => 'Classic Tee',
            'variants' => [['id' => 'sku123-red-s', 'title' => 'Classic Tee - Red / Small', 'price' => ['amount' => 1999, 'currency' => 'USD']]],
        ], 'Product');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function brokenDocuments(): iterable
    {
        $variant = ['id' => 'V', 'title' => 'T'];

        yield 'price as string' => [['id' => 'P', 'variants' => [[...$variant, 'price' => ['amount' => '1999', 'currency' => 'EUR']]]], 'Product'];
        yield 'lower-case currency' => [['id' => 'P', 'variants' => [[...$variant, 'price' => ['amount' => 1999, 'currency' => 'eur']]]], 'Product'];
        yield 'negative amount' => [['id' => 'P', 'variants' => [[...$variant, 'price' => ['amount' => -1, 'currency' => 'EUR']]]], 'Product'];
        yield 'unknown variant field' => [['id' => 'P', 'variants' => [[...$variant, 'age_restriction' => 18]]], 'Product'];
        yield 'flat feed item' => [['id' => 'V', 'item_group_id' => 'P', 'title' => 'T', 'price' => '1999', 'price_currency' => 'EUR'], 'Product'];
        yield 'variant without title' => [['id' => 'P', 'variants' => [['id' => 'V']]], 'Product'];
        yield 'product without variants' => [['id' => 'P', 'title' => 'T'], 'Product'];
        yield 'relative url' => [['id' => 'P', 'url' => '/de_DE/products/x', 'variants' => [$variant]], 'Product'];
        yield 'empty description' => [['id' => 'P', 'description' => [], 'variants' => [$variant]], 'Product'];
        yield 'availability as string' => [['id' => 'P', 'variants' => [[...$variant, 'availability' => 'in_stock']]], 'Product'];
        yield 'condition as string' => [['id' => 'P', 'variants' => [[...$variant, 'condition' => 'new']]], 'Product'];
        yield 'metadata without id' => [['target_country' => 'DE'], 'FeedMetadata'];
        yield 'metadata lower-case country' => [['id' => 'f', 'target_country' => 'de'], 'FeedMetadata'];
        yield 'metadata bad timestamp' => [['id' => 'f', 'updated_at' => 'yesterday'], 'FeedMetadata'];
        yield 'upsert without products' => [['items' => []], 'UpsertProductsRequest'];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenDocuments')]
    public function testABrokenDocumentIsRejected(array $document, string $definition): void
    {
        self::assertNotSame([], AcpFeedSchema::errors($document, $definition));
    }
}
