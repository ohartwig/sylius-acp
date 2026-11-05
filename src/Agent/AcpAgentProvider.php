<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Announces the product feed, the ACP discovery document where one exists, and
 * registers the catalogue as a skill.
 *
 * The discovery document answers 404 unless carts or checkout are on, so it is
 * announced only then: llms.txt and the api-catalog must not point at a 404.
 *
 * Registering the skill is what makes "browse this shop's catalogue" show up in
 * the A2A card, the agent-skills index and the MCP server card without any of
 * those packages knowing ACP exists.
 */
final class AcpAgentProvider implements
    AgentEndpointProviderInterface,
    ApiCatalogEntryProviderInterface,
    AgentSkillProviderInterface
{
    public function __construct(
        private readonly string $apiBase = '/api/agent',
        private readonly bool $checkoutEnabled = false,
        private readonly bool $cartsEnabled = false,
    ) {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        if ($this->checkoutEnabled || $this->cartsEnabled) {
            yield new AgentEndpoint(
                path: '/.well-known/acp.json',
                title: 'ACP discovery',
                protocol: 'acp',
                description: $this->checkoutEnabled
                    ? 'Agentic Commerce Protocol: checkout for agents.'
                    : 'Agentic Commerce Protocol: carts an agent builds and hands to the buyer to check out.',
            );
        }

        yield new AgentEndpoint(
            path: $this->apiBase.'/feed',
            title: 'Product feed',
            description: 'Paginated, SKU-level product feed.',
        );
    }

    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        yield new ApiCatalogEntry(
            anchor: $context->url($this->apiBase),
            describedBy: $this->checkoutEnabled || $this->cartsEnabled
                ? [new LinkTarget($context->url('/.well-known/acp.json'), 'application/json')]
                : [],
            title: 'Agent commerce API',
        );
    }

    public function getAgentSkills(AgentContext $context): iterable
    {
        yield new AgentSkill(
            id: 'product-feed',
            name: 'Product feed',
            description: 'List the shop catalogue at SKU level with prices and availability.',
            tags: ['commerce', 'catalog'],
            outputModes: ['application/json'],
        );

        if ($this->cartsEnabled) {
            yield new AgentSkill(
                id: 'acp-carts',
                name: 'Cart handoff',
                description: 'Build a cart with estimated totals and hand it to the buyer, who checks out in the shop (ACP carts, continue_url). Needs an API key from the shop.',
                tags: ['commerce', 'cart', 'acp'],
                inputModes: ['application/json'],
                outputModes: ['application/json'],
            );
        }
    }
}
