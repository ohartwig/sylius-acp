<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

/**
 * Writes an ACP offline full-replacement snapshot: `metadata.json`
 * (`FeedMetadata`) and `products.jsonl` (one `Product` per line).
 *
 * Products are written as they arrive, so memory does not grow with the
 * catalogue. Both files are written under a temporary name and renamed into
 * place, products first: an uploader that picks up the directory never sees a
 * half-written product list, and never a metadata file whose product list is
 * still the previous one.
 */
final class JsonlFeedWriter
{
    public const METADATA_FILE = 'metadata.json';
    public const PRODUCTS_FILE = 'products.jsonl';

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed>          $metadata
     * @param iterable<array<string, mixed>> $products
     *
     * @return int the number of products written
     */
    public function write(string $directory, array $metadata, iterable $products): int
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new FeedConfigurationException(\sprintf('Cannot create directory "%s".', $directory));
        }

        $productsFile = $directory.'/'.self::PRODUCTS_FILE;
        $productsTemp = $productsFile.'.tmp';
        $handle = @fopen($productsTemp, 'w');
        if (false === $handle) {
            throw new FeedConfigurationException(\sprintf('Cannot write to "%s".', $productsTemp));
        }

        $count = 0;
        try {
            foreach ($products as $product) {
                // JSON without pretty-printing has no raw line breaks: a line
                // break inside a value is escaped, so one line is one product.
                fwrite($handle, json_encode($product, self::JSON_FLAGS)."\n");
                ++$count;
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($productsTemp);

            throw $exception;
        }
        fclose($handle);

        $metadataFile = $directory.'/'.self::METADATA_FILE;
        file_put_contents($metadataFile.'.tmp', json_encode($metadata, self::JSON_FLAGS | \JSON_PRETTY_PRINT)."\n");

        rename($productsTemp, $productsFile);
        rename($metadataFile.'.tmp', $metadataFile);

        return $count;
    }
}
