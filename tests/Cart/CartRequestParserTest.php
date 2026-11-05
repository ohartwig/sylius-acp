<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Koh\SyliusAcp\Cart\CartRequestParser;
use Koh\SyliusAcp\Http\AcpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartRequestParserTest extends TestCase
{
    public function testItReadsLinesLocaleAndWhetherABuyerWasSent(): void
    {
        $request = (new CartRequestParser())->parse(json_encode([
            'line_items' => [['id' => 'A', 'quantity' => 2], ['id' => 'B']],
            'buyer' => ['email' => 'buyer@example.com'],
            'locale' => 'de-DE',
            'unknown' => 'ignored',
        ], \JSON_THROW_ON_ERROR));

        self::assertCount(2, $request->lines);
        self::assertSame('A', $request->lines[0]->id);
        self::assertSame(2, $request->lines[0]->quantity);
        self::assertSame('B', $request->lines[1]->id);
        self::assertSame(1, $request->lines[1]->quantity, 'Item has no quantity in the schema; one is the default');
        self::assertSame('de-DE', $request->locale);
        self::assertTrue($request->buyerGiven);
    }

    public function testWithoutBuyerAndLocaleBothAreAbsent(): void
    {
        $request = (new CartRequestParser())->parse('{"line_items":[{"id":"A","quantity":1}]}');

        self::assertNull($request->locale);
        self::assertFalse($request->buyerGiven);
    }

    public function testTheLimitsAreInclusive(): void
    {
        $lines = [['id' => str_repeat('x', 255), 'quantity' => 3], ['id' => 'B', 'quantity' => 1]];
        $request = (new CartRequestParser(2, 3))->parse(json_encode(['line_items' => $lines], \JSON_THROW_ON_ERROR));

        self::assertCount(2, $request->lines);
        self::assertSame(3, $request->lines[0]->quantity);
    }

    public function testLeadingWhitespaceBeforeTheObjectIsFine(): void
    {
        self::assertCount(1, (new CartRequestParser())->parse(" \n{\"line_items\":[{\"id\":\"A\"}]}")->lines);
    }

    public function testUnderscoreLocalesAreAccepted(): void
    {
        self::assertSame('de_DE', (new CartRequestParser())->parse('{"line_items":[{"id":"A"}],"locale":"de_DE"}')->locale);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => ['  ', 'missing_line_items', '$.line_items'];
        yield 'not json' => ['{', 'invalid_json', null];
        yield 'a list' => ['[]', 'invalid_json', null];
        yield 'a scalar' => ['1', 'invalid_json', null];
        yield 'no line items' => ['{}', 'missing_line_items', '$.line_items'];
        yield 'empty line items' => ['{"line_items":[]}', 'missing_line_items', '$.line_items'];
        yield 'line items as object' => ['{"line_items":{"a":{"id":"A"}}}', 'missing_line_items', '$.line_items'];
        yield 'too many' => ['{"line_items":[{"id":"A"},{"id":"B"},{"id":"C"}]}', 'invalid', '$.line_items'];
        yield 'line not an object' => ['{"line_items":["A"]}', 'invalid', '$.line_items[0].id'];
        yield 'no id' => ['{"line_items":[{"quantity":1}]}', 'invalid', '$.line_items[0].id'];
        yield 'empty id' => ['{"line_items":[{"id":""}]}', 'invalid', '$.line_items[0].id'];
        yield 'numeric id' => ['{"line_items":[{"id":5}]}', 'invalid', '$.line_items[0].id'];
        yield 'long id' => ['{"line_items":[{"id":"'.str_repeat('x', 256).'"}]}', 'invalid', '$.line_items[0].id'];
        yield 'zero quantity' => ['{"line_items":[{"id":"A","quantity":0}]}', 'invalid', '$.line_items[0].quantity'];
        yield 'quantity too high' => ['{"line_items":[{"id":"A","quantity":4}]}', 'invalid', '$.line_items[0].quantity'];
        yield 'string quantity' => ['{"line_items":[{"id":"A","quantity":"2"}]}', 'invalid', '$.line_items[0].quantity'];
        yield 'second line' => ['{"line_items":[{"id":"A"},{"id":"B","quantity":-1}]}', 'invalid', '$.line_items[1].quantity'];
        yield 'bad locale' => ['{"line_items":[{"id":"A"}],"locale":"de DE"}', 'invalid', '$.locale'];
        yield 'numeric locale' => ['{"line_items":[{"id":"A"}],"locale":5}', 'invalid', '$.locale'];
        yield 'buyer not an object' => ['{"line_items":[{"id":"A"}],"buyer":"me"}', 'invalid', '$.buyer'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreProtocolErrors(string $body, string $code, ?string $param): void
    {
        try {
            (new CartRequestParser(2, 3))->parse($body);
            self::fail('Expected an AcpError');
        } catch (AcpError $error) {
            self::assertSame(400, $error->status);
            self::assertSame('invalid_request', $error->type);
            self::assertSame($code, $error->errorCode);
            self::assertSame($param, $error->param);
        }
    }

    public function testDefaultsAllowFiftyLinesOfNinetyNine(): void
    {
        $lines = array_map(static fn (int $i): array => ['id' => 'V'.$i, 'quantity' => 99], range(1, 50));
        self::assertCount(50, (new CartRequestParser())->parse(json_encode(['line_items' => $lines], \JSON_THROW_ON_ERROR))->lines);

        $lines[] = ['id' => 'V51', 'quantity' => 1];
        $this->expectException(AcpError::class);
        (new CartRequestParser())->parse(json_encode(['line_items' => $lines], \JSON_THROW_ON_ERROR));
    }

    public function testTheDefaultQuantityCapIsNinetyNine(): void
    {
        $this->expectException(AcpError::class);
        (new CartRequestParser())->parse('{"line_items":[{"id":"A","quantity":100}]}');
    }
}
