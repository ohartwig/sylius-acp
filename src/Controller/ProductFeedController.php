<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the read-only catalogue endpoint at {api_base}/feed.
 *
 * Not the ACP product feed, despite the path: ACP's Feed API is hosted by the
 * agent and fed by the merchant (see koh:acp:feed:export and
 * koh:acp:feed:push). This is the shop's own listing and name search, kept
 * because the in-page search tool of koh/sylius-webmcp calls it.
 *
 * A narrow, purpose-built surface rather than the full Sylius Shop API. The
 * shop needs to expose a catalogue to agents; it does not need to expose cart
 * mutation and checkout to reach that goal, and every endpoint published is one
 * that has to be defended.
 */
final class ProductFeedController
{
    public function __construct(
        private readonly ProductFeedBuilder $builder,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();

        $feed = $this->builder->build(
            $context,
            (int) $request->query->get('page', '1'),
            $request->query->has('limit') ? (int) $request->query->get('limit') : null,
            $request->query->has('q') ? (string) $request->query->get('q') : null,
        );

        return $this->responder->respond($feed, maxAge: 900, request: $request);
    }
}
