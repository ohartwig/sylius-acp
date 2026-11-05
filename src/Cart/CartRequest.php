<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

/**
 * A validated `CartCreateRequest` or `CartUpdateRequest`.
 */
final readonly class CartRequest
{
    /**
     * @param list<CartLine> $lines
     * @param string|null    $locale BCP 47 tag as sent, e.g. `de-DE`
     * @param bool           $buyerGiven whether the request carried a `buyer` object
     */
    public function __construct(
        public array $lines,
        public ?string $locale,
        public bool $buyerGiven,
    ) {
    }
}
