<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * The cart after a write, plus the messages about lines that could not be
 * taken over as asked. Those messages exist only in the write response: the
 * dropped lines are not part of the cart any more.
 */
final readonly class CartWrite
{
    /**
     * @param list<array<string, string>> $messages
     */
    public function __construct(
        public OrderInterface $cart,
        public array $messages = [],
    ) {
    }
}
