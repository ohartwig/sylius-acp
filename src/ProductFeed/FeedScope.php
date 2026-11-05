<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Everything one ACP feed is built for: a channel, one locale, and the values
 * derived from the channel once, before the catalogue is streamed.
 *
 * Derived up front on purpose. The stream clears the entity manager between
 * batches, so anything read lazily off the channel afterwards would hit a
 * detached entity; the channel itself is only asked for its code.
 */
final readonly class FeedScope
{
    /**
     * @param string                                                                           $baseUrl  absolute, without trailing slash
     * @param string                                                                           $currency ISO 4217, upper case
     * @param array{name?: string, links?: list<array{type: string, url: string, title?: string}>}|null $seller
     * @param ?string                                                                          $targetCountry ISO 3166-1 alpha-2
     */
    public function __construct(
        public ChannelInterface $channel,
        public string $localeCode,
        public string $baseUrl,
        public string $currency,
        public ?array $seller = null,
        public string $mediaPathPrefix = '/media/image/',
        public ?string $targetCountry = null,
    ) {
    }

    public function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    public function mediaUrl(string $path): string
    {
        return $this->url(rtrim($this->mediaPathPrefix, '/').'/'.ltrim($path, '/'));
    }
}
