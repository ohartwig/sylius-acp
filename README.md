<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-acp

[Agentic Commerce Protocol](https://agenticcommerce.dev) (ACP) discovery for
Sylius: a discovery document at `/.well-known/acp.json` and a read-only,
SKU-level product feed agents can browse.

- **Discovery document** — protocol version, API base URL, transports and the
  channel's currency and locales, per channel.
- **Product feed** — one entry per enabled variant, with price in minor units,
  currency, availability and an optional age restriction.
- **Read-only by default** — checkout is not declared unless you switch it on.
- **Registered as a skill** — the catalogue shows up in the A2A card, the
  agent-skills index and the MCP server card of the other `koh` bundles without
  extra configuration.

Requires PHP 8.3 or later and Sylius 2.2 or 2.3.

## Install

```bash
composer require koh/sylius-acp
```

Register the bundle and the shared contracts bundle it builds on:

```php
// config/bundles.php
return [
    // …
    Koh\SyliusAgentContracts\KohSyliusAgentContractsBundle::class => ['all' => true],
    Koh\SyliusAcp\KohSyliusAcpBundle::class => ['all' => true],
];
```

Import the routes:

```yaml
# config/routes/koh_agent.yaml
koh_acp:
    resource: '@KohSyliusAcpBundle/config/routes.php'
```

Clear the cache and check that both endpoints answer:

```bash
bin/console cache:clear
curl -s https://shop.example.com/.well-known/acp.json
curl -s 'https://shop.example.com/api/agent/feed?limit=2'
```

## Configure

Everything has a default; the file is only needed to change one:

```yaml
# config/packages/koh_acp.yaml
koh_acp:
    version: '2026-01-30'     # ACP version announced in the discovery document
    api_base: '/api/agent'    # advertised as api_base_url
    checkout: false           # declare the checkout service
    age_restriction: ~        # minimum age carried on every feed item
    feed:
        default_limit: 100
        max_limit: 500
```

- `api_base` is announced, not routed. The feed route is fixed at
  `/api/agent/feed`; if you change `api_base`, add a route for
  `{api_base}/feed` pointing at `Koh\SyliusAcp\Controller\ProductFeedController`.
- `checkout` stays off unless the shop really completes purchases through the
  API. An agent that finds `checkout` in the capabilities will try to buy.
- `age_restriction` belongs on every age-restricted catalogue. A feed that omits
  it invites an agent to treat restricted items as ordinary stock.

## Output

`GET /.well-known/acp.json`:

```json
{
  "protocol": {
    "name": "acp",
    "version": "2026-01-30",
    "supported_versions": ["2026-01-30"],
    "documentation_url": "https://agenticcommerce.dev"
  },
  "api_base_url": "https://shop.example.com/api/agent",
  "transports": ["rest"],
  "capabilities": {
    "services": ["catalog"],
    "supported_currencies": ["eur"],
    "supported_locales": ["en-US", "de-DE"]
  }
}
```

`GET /api/agent/feed?page=1&limit=2`:

```json
{
  "items": [
    {
      "id": "T_SHIRT_M",
      "item_group_id": "T_SHIRT",
      "title": "T-shirt, size M",
      "description": "Organic cotton, regular fit.",
      "link": "https://shop.example.com/en_US/products/t-shirt",
      "availability": "in_stock",
      "condition": "new",
      "price": "1990",
      "price_currency": "EUR"
    }
  ],
  "page": 1,
  "limit": 2,
  "count": 1
}
```

`limit` is clamped to `1…max_limit` and `page` to at least 1, so a crafted
query string cannot pull the whole catalogue in one request. `limit` counts
products; `count` is the number of variants returned. Descriptions are plain
text, at most 500 characters.

`q` narrows the feed to products whose name contains the term (in the
channel's locale, enabled and in this channel only; at most 100 characters).
Without it the feed lists the channel's latest products.

The discovery document is cached for an hour, the feed for 15 minutes.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and `config/packages/koh_acp.yaml`. No database table, no cache pool.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

The family threat model, covering this package, is at
<https://github.com/ohartwig/sylius-agent-ready/blob/main/docs/threat-model.md>.

## Licence

MIT — see [LICENSE](LICENSE).
