<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-acp

[Agentic Commerce Protocol](https://agenticcommerce.dev) (ACP) for Sylius: the
ACP cart service with a buyer handoff, a discovery document at
`/.well-known/acp.json` and a read-only, SKU-level product feed agents can
browse.

- **Carts** (ACP 2026-04-17, `carts` service) — an agent builds a cart with
  estimated totals; `continue_url` hands it to the buyer, who checks out in the
  shop. Off by default.
- **Discovery document** — protocol version, API base URL, transports, the
  services on offer and the channel's currency and locales, per channel. Served
  only when the shop offers an ACP service; otherwise `404`, see below.
- **Product feed** — one entry per enabled variant, with price in minor units,
  currency, availability and an optional age restriction.
- **No purchase through the API** — checkout is not declared unless the
  installation serves it itself.
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
curl -s 'https://shop.example.com/api/agent/feed?limit=2'
curl -si https://shop.example.com/.well-known/acp.json   # 404 unless carts or checkout are on
```

## Configure

Everything has a default; the file is only needed to change one:

```yaml
# config/packages/koh_acp.yaml
koh_acp:
    version: '2026-04-17'     # ACP version announced in the discovery document
    api_base: '/api/agent'    # advertised as api_base_url
    checkout: false           # declare ACP checkout (served by the installation)
    age_restriction: ~        # minimum age carried on every feed item and cart
    carts:
        enabled: false
        bearer_token_hashes: '%env(ACP_CART_TOKEN_HASHES)%'
        max_line_items: 50
        max_quantity: 99
        rate_limit: 120             # requests per API key and interval
        rate_limit_interval: '1 minute'
        handoff_route: sylius_shop_cart_summary
        fallback_route: sylius_shop_homepage
    feed:
        default_limit: 100
        max_limit: 500
        max_page: 1000        # beyond it the feed answers an empty list
```

- `api_base` is announced, not routed. The feed route is fixed at
  `/api/agent/feed`; if you change `api_base`, add a route for
  `{api_base}/feed` pointing at `Koh\SyliusAcp\Controller\ProductFeedController`.
- `checkout` stays off unless the installation really serves ACP checkout
  sessions (`{api_base}/checkout_sessions`); this bundle does not implement
  them. An agent that finds `checkout` in the capabilities will try to buy.
- ACP's `capabilities.services` is a closed list of ACP operations (checkout,
  orders, delegate_payment, carts). The product feed is not one of them, so
  with neither carts nor checkout on the shop offers no ACP service and
  `/.well-known/acp.json` answers `404` — the discovery RFC's signal for "this
  seller does not support ACP". llms.txt and the api-catalog then do not
  announce it either; the feed stays announced.
- `age_restriction` belongs on every age-restricted catalogue. A feed that omits
  it invites an agent to treat restricted items as ordinary stock.

## Carts

Why carts and not checkout: an ACP checkout session is completed with
`payment_data` for a payment handler and MUST create an order, and every handler
ACP 2026-04-17 defines goes through `delegate_payment`. A shop without a payment
provider for agents cannot offer that honestly. Carts carry no payment and are
never completed through the API; `continue_url` hands them to a person.

Routes (fixed, like the feed):

| Call | Route |
|---|---|
| `POST /api/agent/carts` | create |
| `GET /api/agent/carts/{id}` | retrieve |
| `PUT /api/agent/carts/{id}` | replace the lines |
| `POST /api/agent/carts/{id}/cancel` | cancel, answers the last state |
| `GET /acp-cart/{id}` | `continue_url`, opened by the buyer |

- **Access.** Every call needs `Authorization: Bearer <key>` and
  `API-Version: 2026-04-17`. Issue each agent platform a random key and put only
  its SHA-256 digest into `bearer_token_hashes` (comma- or space-separated):
  `printf %s "$KEY" | sha256sum`. A cart is visible only to the key that
  created it. Requests are rate-limited per key.
- **Idempotency.** Every POST needs an `Idempotency-Key`; PUT honours one. The
  same key and body replays the stored answer (`Idempotent-Replayed: true`),
  another body is `422 idempotency_conflict`, a request still running is
  `409 idempotency_in_flight`. Answers are kept for 24 hours in `cache.app`.
- **Pricing.** An ACP cart is an ordinary Sylius cart (order in state `cart`)
  without customer or session, priced by the shop's own order processors. The
  totals leave shipping out and say so; an included VAT is reported, not added.
  Sylius' expired-carts cleanup removes it; `expires_at` says when.
- **Items.** `line_items[].id` is the feed's `id` (the variant code). Unknown,
  disabled or foreign-channel items are dropped with a `not_found` message;
  short stock stays in the cart with an `out_of_stock` message; nothing
  available is a `422`. Buyer details are not stored.
- **Age restriction.** With `age_restriction` set, every cart carries an
  `age_verification_required` message: only the buyer can buy, in the shop.
- **Handoff.** `continue_url` adds the lines to the buyer's own cart through
  `sylius.cart_item_add`, the event the shop's add-to-cart button fires. Every
  gate hung on that event (an age confirmation, for example) applies; if one
  refuses, nothing is added and the buyer lands on `fallback_route` with a
  notice, and the link works again once the gate is satisfied. Checkout —
  address, age declaration, shipping, payment — is the shop's own. Opening the
  link twice does not add the items twice.
- **Firewall.** `/api/agent/` must not start a session (see the agent-public
  firewall in the installation); `/acp-cart/` must run in the shop firewall so
  the buyer's session and login apply.

## Output

`GET /.well-known/acp.json` with carts on (validates against
`DiscoveryResponse` of ACP 2026-04-17):

```json
{
  "protocol": {
    "name": "acp",
    "version": "2026-04-17",
    "supported_versions": ["2026-04-17"],
    "documentation_url": "https://agenticcommerce.dev"
  },
  "api_base_url": "https://shop.example.com/api/agent",
  "transports": ["rest"],
  "capabilities": {
    "services": ["carts"],
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

`limit` is clamped to `1…max_limit` and `page` to at least 1; a `page` above
`max_page` answers an empty list without a database query. The page is cut in
the query (offset and row limit), so a crafted query string can neither pull
the whole catalogue in one request nor make the database read it. `limit` counts
products; `count` is the number of variants returned. Descriptions are plain
text, at most 500 characters.

`q` narrows the feed to products whose name contains the term (in the
channel's locale, enabled and in this channel only; at most 100 characters).
Without it the feed lists the channel's latest products.

`POST /api/agent/carts` with `{"line_items": [{"id": "T_SHIRT_M", "quantity": 2}]}`
(validates against `Cart` of ACP 2026-04-17, schema.cart.json):

```json
{
  "id": "acp_3f9c…_8a1b…",
  "line_items": [
    {
      "id": "li_41",
      "item": {"id": "T_SHIRT_M", "name": "T-shirt, size M", "unit_amount": 1990},
      "quantity": 2,
      "name": "T-shirt, size M",
      "unit_amount": 1990,
      "variant_id": "T_SHIRT_M",
      "availability_status": "in_stock",
      "totals": [
        {"type": "subtotal", "display_text": "Subtotal", "amount": 3980},
        {"type": "total", "display_text": "Total", "amount": 3980}
      ],
      "product_id": "T_SHIRT"
    }
  ],
  "currency": "eur",
  "totals": [
    {"type": "items_base_amount", "display_text": "Items", "amount": 3980},
    {"type": "tax", "display_text": "VAT (included)", "amount": 635},
    {"type": "total", "display_text": "Estimated total (excluding shipping)", "amount": 3980}
  ],
  "messages": [
    {
      "type": "info",
      "content_type": "plain",
      "content": "Totals are estimates without shipping. The buyer completes the purchase in the shop, where shipping, payment and the final total are settled: hand them continue_url."
    }
  ],
  "continue_url": "https://shop.example.com/acp-cart/acp_3f9c…_8a1b…",
  "expires_at": "2026-11-07T10:00:00Z"
}
```

The discovery document is cached for an hour, the feed for 15 minutes; cart
answers are `no-store`.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and `config/packages/koh_acp.yaml`. No database table and no cache pool of its
own; agent carts are Sylius carts and expire with them.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

The family threat model, covering this package, is at
<https://github.com/ohartwig/sylius-agent-ready/blob/main/docs/threat-model.md>.

## Licence

MIT — see [LICENSE](LICENSE).
