<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Resolves channel, locale, base URL, currency, seller and target market of a
 * feed, from command options first and the bundle configuration second.
 *
 * Runs on the command line, where there is no request: the base URL comes from
 * the channel's hostname, and a channel without one cannot be published —
 * every `url` in the feed has to be absolute.
 */
final class FeedScopeFactory
{
    /**
     * @param ChannelRepositoryInterface<ChannelInterface>              $channels
     * @param list<array{type: string, url: string, title?: ?string}> $sellerLinks
     */
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly AgentContextFactoryInterface $contexts,
        private readonly ?string $defaultChannel = null,
        private readonly ?string $defaultLocale = null,
        private readonly ?string $defaultTargetCountry = null,
        private readonly ?string $sellerName = null,
        private readonly array $sellerLinks = [],
        private readonly string $mediaPathPrefix = '/media/image/',
    ) {
    }

    public function create(?string $channelCode = null, ?string $localeCode = null, ?string $targetCountry = null): FeedScope
    {
        $channel = $this->channel($this->blankToNull($channelCode) ?? $this->blankToNull($this->defaultChannel));
        $localeCode = $this->locale($channel, $this->blankToNull($localeCode) ?? $this->blankToNull($this->defaultLocale));

        $baseUrl = $this->contexts->create($channel)->baseUrl;
        if (1 !== preg_match('#^https?://[^/]+#', $baseUrl)) {
            throw new FeedConfigurationException(\sprintf('Channel "%s" has no hostname; the feed needs absolute URLs. Set the channel hostname.', (string) $channel->getCode()));
        }

        $currency = strtoupper((string) $channel->getBaseCurrency()?->getCode());
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new FeedConfigurationException(\sprintf('Channel "%s" has no ISO 4217 base currency.', (string) $channel->getCode()));
        }

        return new FeedScope(
            channel: $channel,
            localeCode: $localeCode,
            baseUrl: rtrim($baseUrl, '/'),
            currency: $currency,
            seller: $this->seller($channel),
            mediaPathPrefix: $this->mediaPathPrefix,
            targetCountry: $this->targetCountry($channel, $this->blankToNull($targetCountry) ?? $this->blankToNull($this->defaultTargetCountry)),
        );
    }

    private function channel(?string $code): ChannelInterface
    {
        if (null !== $code) {
            $channel = $this->channels->findOneByCode($code);
            if (!$channel instanceof ChannelInterface) {
                throw new FeedConfigurationException(\sprintf('Channel "%s" does not exist.', $code));
            }

            return $channel;
        }

        $enabled = [];
        foreach ($this->channels->findEnabled() as $channel) {
            if ($channel instanceof ChannelInterface) {
                $enabled[] = $channel;
            }
        }
        if (1 !== \count($enabled)) {
            throw new FeedConfigurationException(\sprintf('%d enabled channels: name one with --channel or koh_acp.acp_feed.channel.', \count($enabled)));
        }

        return $enabled[0];
    }

    private function locale(ChannelInterface $channel, ?string $localeCode): string
    {
        $available = [];
        foreach ($channel->getLocales() as $locale) {
            $available[] = (string) $locale->getCode();
        }

        $localeCode ??= $channel->getDefaultLocale()?->getCode();
        if (null === $localeCode || !\in_array($localeCode, $available, true)) {
            throw new FeedConfigurationException(\sprintf('Locale "%s" is not enabled on channel "%s" (enabled: %s).', (string) $localeCode, (string) $channel->getCode(), implode(', ', $available)));
        }

        return $localeCode;
    }

    /**
     * An explicit value must be valid. A derived one — the channel's only
     * country, else the shop's billing country — is used only where it is.
     */
    private function targetCountry(ChannelInterface $channel, ?string $explicit): ?string
    {
        if (null !== $explicit) {
            $explicit = strtoupper($explicit);
            if (1 !== preg_match('/^[A-Z]{2}$/', $explicit)) {
                throw new FeedConfigurationException(\sprintf('Target country "%s" is not an ISO 3166-1 alpha-2 code.', $explicit));
            }

            return $explicit;
        }

        $countries = $channel->getCountries();
        $only = 1 === $countries->count() ? $countries->first() : false;
        $derived = false !== $only
            ? $only->getCode()
            : $channel->getShopBillingData()?->getCountryCode();
        $derived = strtoupper((string) $derived);

        return 1 === preg_match('/^[A-Z]{2}$/', $derived) ? $derived : null;
    }

    /**
     * @return array{name?: string, links?: list<array{type: string, url: string, title?: string}>}|null
     */
    private function seller(ChannelInterface $channel): ?array
    {
        $seller = [];
        $name = $this->blankToNull($this->sellerName)
            ?? $this->blankToNull($channel->getShopBillingData()?->getCompany())
            ?? $this->blankToNull($channel->getName());
        if (null !== $name) {
            $seller['name'] = $name;
        }

        $links = [];
        foreach ($this->sellerLinks as $link) {
            $item = ['type' => $link['type'], 'url' => $link['url']];
            $title = $this->blankToNull($link['title'] ?? null);
            if (null !== $title) {
                $item['title'] = $title;
            }
            $links[] = $item;
        }
        if ([] !== $links) {
            $seller['links'] = $links;
        }

        return [] === $seller ? null : $seller;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
