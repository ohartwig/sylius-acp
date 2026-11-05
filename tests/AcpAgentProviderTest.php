<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Agent\AcpAgentProvider;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;

/**
 * This class is how ACP becomes visible to packages that have never heard of
 * it. It implements three provider interfaces, and the registries wire by
 * interface rather than by name — so dropping one is not an error anywhere, it
 * is a capability that quietly stops being advertised. The A2A card, the
 * agent-skills index and the MCP server card would each keep working and each
 * be missing the catalogue.
 *
 * The three interfaces are therefore asserted explicitly, and so are the two
 * paths: `/.well-known/acp.json` is where an agent looks unprompted, and a
 * typo there produces no failing request, just an endpoint nobody finds.
 */
final class AcpAgentProviderTest extends TestCase
{
    public function testItAnnouncesDiscoveryAndFeedWhenCheckoutIsOn(): void
    {
        $endpoints = iterator_to_array((new AcpAgentProvider(checkoutEnabled: true))->getAgentEndpoints($this->context()));

        self::assertCount(2, $endpoints);
        self::assertSame('/.well-known/acp.json', $endpoints[0]->path);
        self::assertSame('/api/agent/feed', $endpoints[1]->path);
        self::assertContainsOnlyInstancesOf(AgentEndpoint::class, $endpoints);
        self::assertSame('Agentic Commerce Protocol: checkout for agents.', $endpoints[0]->description);
    }

    /** Without checkout the discovery document answers 404, so nothing may point at it. */
    public function testWithoutCheckoutOnlyTheFeedIsAnnounced(): void
    {
        $endpoints = iterator_to_array((new AcpAgentProvider())->getAgentEndpoints($this->context()));

        self::assertCount(1, $endpoints);
        self::assertSame('/api/agent/feed', $endpoints[0]->path);
    }

    public function testOnlyTheDiscoveryEndpointClaimsTheAcpProtocol(): void
    {
        // The feed is a plain JSON endpoint. Tagging it `acp` too would make a
        // client filtering by protocol treat it as a second discovery document.
        $endpoints = iterator_to_array((new AcpAgentProvider(checkoutEnabled: true))->getAgentEndpoints($this->context()));

        self::assertSame('acp', $endpoints[0]->protocol);
        self::assertNotSame('acp', $endpoints[1]->protocol);
    }

    public function testTheFeedPathFollowsAConfiguredApiBase(): void
    {
        // api_base is configurable and the route has to be changed with it.
        // If this ever stopped being honoured, the shop would advertise a feed
        // at a path it does not serve.
        $endpoints = iterator_to_array((new AcpAgentProvider('/custom/base', true))->getAgentEndpoints($this->context()));

        self::assertSame('/custom/base/feed', $endpoints[1]->path);
        self::assertSame('/.well-known/acp.json', $endpoints[0]->path, 'well-known is fixed by the spec');
    }

    public function testTheCatalogueEntryPointsAtAbsoluteUrls(): void
    {
        $entries = iterator_to_array((new AcpAgentProvider())->getApiCatalogEntries($this->context()));

        self::assertCount(1, $entries);
        self::assertInstanceOf(ApiCatalogEntry::class, $entries[0]);
        // Relative anchors are useless to a client that fetched the catalogue
        // from somewhere else, which is the normal case for a catalogue.
        self::assertSame('https://shop.example.com/api/agent', $entries[0]->anchor);
    }

    public function testWithoutCheckoutTheCatalogueEntryDoesNotPointAtTheMissingDocument(): void
    {
        $entry = iterator_to_array((new AcpAgentProvider())->getApiCatalogEntries($this->context()))[0];

        self::assertSame([], $entry->describedBy);
    }

    public function testTheCatalogueEntryDescribesItselfWithTheDiscoveryDocument(): void
    {
        $entry = iterator_to_array((new AcpAgentProvider(checkoutEnabled: true))->getApiCatalogEntries($this->context()))[0];

        self::assertCount(1, $entry->describedBy);
        self::assertSame('https://shop.example.com/.well-known/acp.json', $entry->describedBy[0]->href);
        self::assertSame('application/json', $entry->describedBy[0]->type);
    }

    public function testTheCatalogueIsRegisteredAsASkill(): void
    {
        // This is what makes "browse this shop's catalogue" appear in the A2A
        // card without that package knowing ACP exists.
        $skills = iterator_to_array((new AcpAgentProvider())->getAgentSkills($this->context()));

        self::assertCount(1, $skills);
        self::assertInstanceOf(AgentSkill::class, $skills[0]);
        self::assertSame('catalogue-search', $skills[0]->id);
    }

    public function testTheCatalogueEndpointIsNotPresentedAsTheAcpProductFeed(): void
    {
        // ACP's Feed API is hosted by the agent; a merchant endpoint presented
        // as "the ACP feed" would be a non-conformant ACP surface.
        $provider = new AcpAgentProvider(checkoutEnabled: true);
        $endpoint = iterator_to_array($provider->getAgentEndpoints($this->context()))[1];
        $skill = iterator_to_array($provider->getAgentSkills($this->context()))[0];

        self::assertSame('Catalogue search', $endpoint->title);
        self::assertStringContainsString('not an ACP product feed', (string) $endpoint->description);
        self::assertSame('Catalogue search', $skill->name);
        self::assertStringContainsString('GET /api/agent/feed?q=', $skill->description);
        self::assertStringNotContainsStringIgnoringCase('acp', $skill->description);
    }

    public function testTheSkillCarriesTagsAndOutputModes(): void
    {
        $skill = iterator_to_array((new AcpAgentProvider())->getAgentSkills($this->context()))[0];

        self::assertSame(['commerce', 'catalog', 'search'], $skill->tags);
        self::assertSame(['application/json'], $skill->outputModes);
        self::assertNotSame('', $skill->description);
    }

    public function testWithCartsTheDiscoveryDocumentAndTheCartSkillAreAnnounced(): void
    {
        $provider = new AcpAgentProvider(cartsEnabled: true);
        $endpoints = iterator_to_array($provider->getAgentEndpoints($this->context()));

        self::assertCount(2, $endpoints);
        self::assertSame('/.well-known/acp.json', $endpoints[0]->path);
        self::assertSame('acp', $endpoints[0]->protocol);
        self::assertSame('Agentic Commerce Protocol: carts an agent builds and hands to the buyer to check out.', $endpoints[0]->description);

        $entry = iterator_to_array($provider->getApiCatalogEntries($this->context()))[0];
        self::assertSame('https://shop.example.com/.well-known/acp.json', $entry->describedBy[0]->href);

        $skills = iterator_to_array($provider->getAgentSkills($this->context()));
        self::assertCount(2, $skills);
        self::assertSame('acp-carts', $skills[1]->id);
        self::assertSame('Cart handoff', $skills[1]->name);
        self::assertSame(['commerce', 'cart', 'acp'], $skills[1]->tags);
        self::assertSame(['application/json'], $skills[1]->inputModes);
        self::assertSame(['application/json'], $skills[1]->outputModes);
        self::assertStringContainsString('continue_url', $skills[1]->description);
    }

    public function testItImplementsAllThreeProviderInterfaces(): void
    {
        $provider = new AcpAgentProvider();

        self::assertInstanceOf(AgentEndpointProviderInterface::class, $provider);
        self::assertInstanceOf(ApiCatalogEntryProviderInterface::class, $provider);
        self::assertInstanceOf(AgentSkillProviderInterface::class, $provider);
    }

    private function context(): AgentContext
    {
        $currency = $this->createStub(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('EUR');

        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Example Shop');
        $channel->method('getBaseCurrency')->willReturn($currency);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
