<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

use Koh\SyliusAcp\Http\AcpError;

/**
 * Reads the body of `POST /carts` and `PUT /carts/{id}`.
 *
 * `line_items` holds `Item` objects. The 2026-04-17 schema gives `Item` no
 * `quantity`, while the RFC and every example send one; it is read when
 * present and defaults to 1. Fields this shop does not use are ignored rather
 * than rejected, so an agent written against a newer version still gets a cart.
 */
final class CartRequestParser
{
    public function __construct(
        private readonly int $maxLineItems = 50,
        private readonly int $maxQuantity = 99,
    ) {
    }

    public function parse(string $content): CartRequest
    {
        if ('' === trim($content)) {
            throw AcpError::invalid('missing_line_items', 'A JSON body with line_items is required.', '$.line_items');
        }

        try {
            $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AcpError::invalid('invalid_json', 'The request body is not valid JSON.');
        }

        // `{}` and `[]` decode alike; only an object is a request.
        if (!\is_array($body) || !str_starts_with(ltrim($content), '{')) {
            throw AcpError::invalid('invalid_json', 'The request body must be a JSON object.');
        }

        $lines = $body['line_items'] ?? null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw AcpError::invalid('missing_line_items', 'line_items is required and must contain at least one item.', '$.line_items');
        }
        if (\count($lines) > $this->maxLineItems) {
            throw AcpError::invalid('invalid', \sprintf('At most %d line items per cart.', $this->maxLineItems), '$.line_items');
        }

        $parsed = [];
        foreach ($lines as $index => $line) {
            $parsed[] = $this->line($line, $index);
        }

        $locale = $body['locale'] ?? null;
        if (null !== $locale && (!\is_string($locale) || 1 !== preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $locale))) {
            throw AcpError::invalid('invalid', 'locale must be a BCP 47 language tag.', '$.locale');
        }

        $buyer = $body['buyer'] ?? null;
        if (null !== $buyer && !\is_array($buyer)) {
            throw AcpError::invalid('invalid', 'buyer must be an object.', '$.buyer');
        }

        return new CartRequest($parsed, $locale, null !== $buyer);
    }

    private function line(mixed $line, int $index): CartLine
    {
        $param = \sprintf('$.line_items[%d]', $index);
        $id = \is_array($line) ? ($line['id'] ?? null) : null;
        if (!\is_string($id) || '' === $id || \strlen($id) > 255) {
            throw AcpError::invalid('invalid', 'Each line item needs an id (string, at most 255 characters).', $param.'.id');
        }

        $quantity = $line['quantity'] ?? 1;
        if (!\is_int($quantity) || $quantity < 1 || $quantity > $this->maxQuantity) {
            throw AcpError::invalid('invalid', \sprintf('quantity must be an integer between 1 and %d.', $this->maxQuantity), $param.'.quantity');
        }

        return new CartLine($id, $quantity);
    }
}
