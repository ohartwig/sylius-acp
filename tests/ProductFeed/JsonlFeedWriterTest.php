<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\FeedConfigurationException;
use Koh\SyliusAcp\ProductFeed\JsonlFeedWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonlFeedWriter::class)]
final class JsonlFeedWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/koh-acp-feed-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (['metadata.json', 'products.jsonl', 'metadata.json.tmp', 'products.jsonl.tmp'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        rmdir($this->directory);
    }

    public function testOneProductPerLineAndTheMetadataFile(): void
    {
        $products = [
            ['id' => 'P1', 'title' => "Zwei\nZeilen \u{2028} und Ümlaute", 'url' => 'https://shop.example.com/de_DE/products/p1', 'variants' => [['id' => 'V1', 'title' => 'V1']]],
            ['id' => 'P2', 'variants' => []],
        ];
        $metadata = ['id' => 'feed_1', 'target_country' => 'DE', 'updated_at' => '2026-11-05T09:30:00Z'];

        $count = (new JsonlFeedWriter())->write($this->directory.'/', $metadata, (static fn () => yield from $products)());

        self::assertSame(2, $count);
        $lines = file($this->directory.'/products.jsonl', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertSame($products[0], json_decode($lines[0], true));
        self::assertSame('{"id":"P2","variants":[]}', $lines[1]);
        self::assertStringContainsString('https://shop.example.com/de_DE', $lines[0], 'slashes unescaped');
        self::assertStringContainsString('Ümlaute', $lines[0], 'unicode unescaped');
        self::assertStringContainsString('\\u2028', $lines[0], 'line separators stay escaped');
        foreach ($lines as $line) {
            AcpFeedSchema::assertValid(json_decode($line, false), 'Product');
        }

        $written = file_get_contents($this->directory.'/metadata.json');
        self::assertIsString($written);
        self::assertSame($metadata, json_decode($written, true));
        self::assertStringEndsWith("}\n", $written);
        AcpFeedSchema::assertValid(json_decode($written, false), 'FeedMetadata');
        self::assertSame(['metadata.json', 'products.jsonl'], $this->files());
    }

    public function testAnEmptyCatalogueWritesAnEmptyFile(): void
    {
        self::assertSame(0, (new JsonlFeedWriter())->write($this->directory, ['id' => 'f'], []));
        self::assertSame('', file_get_contents($this->directory.'/products.jsonl'));
    }

    public function testAFailureMidwayLeavesThePreviousSnapshotInPlace(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/products.jsonl', "old\n");
        $products = (static function (): \Generator {
            yield ['id' => 'P1', 'variants' => []];

            throw new \RuntimeException('database gone');
        })();

        try {
            (new JsonlFeedWriter())->write($this->directory, ['id' => 'f'], $products);
            self::fail('expected the exception to propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('database gone', $exception->getMessage());
        }

        self::assertSame("old\n", file_get_contents($this->directory.'/products.jsonl'));
        self::assertSame(['products.jsonl'], $this->files());
    }

    public function testAnUnwritableTargetIsReported(): void
    {
        file_put_contents($this->directory, 'a file, not a directory');

        try {
            $this->expectException(FeedConfigurationException::class);
            $this->expectExceptionMessage('Cannot create directory');
            (new JsonlFeedWriter())->write($this->directory.'/sub', ['id' => 'f'], []);
        } finally {
            unlink($this->directory);
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }
}
