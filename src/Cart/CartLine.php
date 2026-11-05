<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

/**
 * One requested line: the feed item id (the variant code) and a quantity.
 */
final readonly class CartLine
{
    public function __construct(
        public string $id,
        public int $quantity,
    ) {
    }
}
