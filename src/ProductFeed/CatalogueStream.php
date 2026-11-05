<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * The whole publishable catalogue of a channel, product by product: enabled,
 * in the channel, with a translation in the locale.
 *
 * Streamed in batches so that a feed of a large catalogue is written in
 * bounded memory; an implementation may release each batch before loading the
 * next, so a consumer must be done with a product before asking for the next.
 */
interface CatalogueStream
{
    /**
     * @return iterable<ProductInterface>
     */
    public function products(ChannelInterface $channel, string $localeCode, int $batchSize): iterable;
}
