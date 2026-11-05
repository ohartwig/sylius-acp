<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Validates against schema.feed.json of ACP 2026-04-17, copied unchanged from
 * the spec repository (draft 2020-12, format checks on).
 */
final class AcpFeedSchema
{
    private const SCHEMA_ID = 'https://example.com/schemas/feed/bundle.schema.json';

    private static ?Validator $validator = null;

    /** @return list<string> the errors, empty when valid */
    public static function errors(mixed $document, string $definition): array
    {
        $data = json_decode(json_encode($document, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), false, 512, \JSON_THROW_ON_ERROR);
        $result = self::validator()->validate($data, self::SCHEMA_ID.'#/$defs/'.$definition);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = $path.': '.$message;
            }
        }

        return $errors;
    }

    public static function assertValid(mixed $document, string $definition): void
    {
        $errors = self::errors($document, $definition);
        Assert::assertSame([], $errors, $definition.' does not validate against ACP 2026-04-17 schema.feed.json');
    }

    private static function validator(): Validator
    {
        if (null === self::$validator) {
            $validator = new Validator();
            $validator->setMaxErrors(20);
            $validator->resolver()?->registerFile(self::SCHEMA_ID, __DIR__.'/../Fixtures/acp/2026-04-17/schema.feed.json');
            self::$validator = $validator;
        }

        return self::$validator;
    }
}
