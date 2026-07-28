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
 * Announces the ACP surface and registers the catalogue as a skill.
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
    public function __construct(private readonly string $apiBase = '/api/agent')
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        yield new AgentEndpoint(
            path: '/.well-known/acp.json',
            title: 'ACP discovery',
            protocol: 'acp',
            description: 'Agentic Commerce Protocol: catalogue access for agents.',
        );

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
            describedBy: [new LinkTarget($context->url('/.well-known/acp.json'), 'application/json')],
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
    }
}
