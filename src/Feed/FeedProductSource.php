<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Feed;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * One page of the products the feed lists: enabled, in the channel, with a
 * translation in the locale, optionally narrowed by name.
 *
 * The page is cut in the query. Asking for page × limit rows and slicing in
 * PHP made the work of one request grow with the page number (AUD-010-F03).
 */
interface FeedProductSource
{
    /**
     * @return list<ProductInterface>
     */
    public function findPage(ChannelInterface $channel, string $localeCode, ?string $nameQuery, int $offset, int $limit): array;
}
