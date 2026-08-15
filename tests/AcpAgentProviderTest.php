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
    public function testItAnnouncesDiscoveryAndFeed(): void
    {
        $endpoints = iterator_to_array((new AcpAgentProvider())->getAgentEndpoints($this->context()));

        self::assertCount(2, $endpoints);
        self::assertSame('/.well-known/acp.json', $endpoints[0]->path);
        self::assertSame('/api/agent/feed', $endpoints[1]->path);
        self::assertContainsOnlyInstancesOf(AgentEndpoint::class, $endpoints);
    }

    public function testOnlyTheDiscoveryEndpointClaimsTheAcpProtocol(): void
    {
        // The feed is a plain JSON endpoint. Tagging it `acp` too would make a
        // client filtering by protocol treat it as a second discovery document.
        $endpoints = iterator_to_array((new AcpAgentProvider())->getAgentEndpoints($this->context()));

        self::assertSame('acp', $endpoints[0]->protocol);
        self::assertNotSame('acp', $endpoints[1]->protocol);
    }

    public function testTheFeedPathFollowsAConfiguredApiBase(): void
    {
        // api_base is configurable and the route has to be changed with it.
        // If this ever stopped being honoured, the shop would advertise a feed
        // at a path it does not serve.
        $endpoints = iterator_to_array((new AcpAgentProvider('/custom/base'))->getAgentEndpoints($this->context()));

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

    public function testTheCatalogueEntryDescribesItselfWithTheDiscoveryDocument(): void
    {
        $entry = iterator_to_array((new AcpAgentProvider())->getApiCatalogEntries($this->context()))[0];

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
        self::assertSame('product-feed', $skills[0]->id);
    }

    public function testTheSkillCarriesTagsAndOutputModes(): void
    {
        $skill = iterator_to_array((new AcpAgentProvider())->getAgentSkills($this->context()))[0];

        self::assertSame(['commerce', 'catalog'], $skill->tags);
        self::assertSame(['application/json'], $skill->outputModes);
        self::assertNotSame('', $skill->description);
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
        $channel->method('getName')->willReturn('Tabak Budde');
        $channel->method('getBaseCurrency')->willReturn($currency);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
