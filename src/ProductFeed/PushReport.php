<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

/** What one push sent to the agent. */
final readonly class PushReport
{
    public function __construct(
        public int $products,
        public int $requests,
        public int $retired = 0,
    ) {
    }
}
