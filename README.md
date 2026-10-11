# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **The gate** (`x402_pay.gate`): the caller sets price and `payTo` per request; unpaid requests get a 402 with the payment
  requirements; verify and settle through Circle's facilitator **only on a
  2xx response**; a payment log (route, amount, payer, tx hash).
- **The `x402_price` field**: add it to any fieldable entity and agents pay
  to read it. Each field is one product: a view mode at a price.
  - **Settings:** price, network and seller key; `payTo` is the key's own
    address, never typed. *What is sold* (a view mode) and whether it
    charges on the entity's own URL.
  - **Per entity:** switch charging off and, if the field allows it, set its
    own price (permission *Set x402 prices*). Make the field translatable
    and each translation has its own price.
  - **Several fields** sell several view modes (the full text, a summary).
    Two fields cannot sell the same view mode, and at most one charges on
    the entity's own URL: the settings form and the API both refuse it.
  - **Where agents pay:** on the entity's own URL (a `PAYMENT-SIGNATURE`,
    an `Accept` without `text/html`, or a known User-Agent in
    `x402_pay.settings`), where browsers still see the page; and every
    product at `/x402/{entity_type}/{id}/{view_mode}`, announced with one
    `Link` header each. Without a view mode, the canonical product.
  - **What they get:** the fields of that view mode as markdown, under the
    label. Not the entity's template, nor the fields a display cannot
    configure (a node's author and date), nor the price.
- **`/x402/discovery`**: everything the site sells, one item per product,
  in the shape of Circle's Discovery API, so a client written for Circle's
  Agent Marketplace reads it unchanged. Every entity type with the field;
  `limit` and `offset`; cached until an entity or a field changes. List
  prices: the price event may charge otherwise at the time of sale. For
  people, build a View: the field gives Views a *Charge for this* filter
  and the price formatter.
- **The price event** (`Drupal\x402_pay\Event\PriceEvent`): before every
  sale, with the entity in the request's language, the view mode, the
  request and the account. A subscriber can change the price, make it free
  or deny it. It starts at the field's price, free for those with *Access
  x402 content free*. For a price per role, per kind of visitor or anything
  else; ECA support (`x402_pay_eca`) comes later.
- **`x402_pay_commerce`**: a Drupal Commerce payment gateway on top of it, so
  a person can check out in USDC.

`scripts/buy.mjs <url>` is a buyer agent in one file: it reads the 402,
signs the payment and prints what it bought.

Arc testnet only (`eip155:5042002`). Wallet credentials go through the `key`
module from an environment variable, never in exportable config.

Built for [Ombú](https://github.com/quebracho-digital/ombu), the agent mall;
plan and status in its [ROADMAP](https://github.com/quebracho-digital/ombu/blob/main/ROADMAP.md).

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
