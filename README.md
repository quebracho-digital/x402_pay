# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **The gate** (`x402_pay.gate`): the caller sets price and `payTo` per request; unpaid requests get a 402 with the payment
  requirements; verify and settle **only on a 2xx response**; a payment log
  (route, amount, payer, tx hash).
- **Facilitators** (`/admin/config/services/x402`), per network:
  - **Circle's**: the keyless trial (each sale signed by the seller key) or a
    Circle API key (sellers can be bare addresses).
  - **Our own** (`ChainFacilitator`): the site sends the buyer's signed
    `transferWithAuthorization` from a gas wallet that pays only the gas
    (~87k gas: about 0.002 USDC on Arc mainnet). The token checks the
    signature, balance, time window and nonce; the site checks seller and
    price. No third party, no permission.
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
  - **One gate, by entity:** any route that shows an entity it sells (its
    page, a `.md` path, `?_format=`, JSON:API, REST) answers agents with the
    sale, so another module cannot hand out for free what is sold. It runs
    after routing's access check: what a visitor cannot see is never
    offered, and paying grants nothing beyond view access. Forms and admin
    pages are left alone.
- **Markdown for free pages too:** `Accept: text/markdown` on the own URL
  of an entity that sells nothing there (and whose `full` view mode no field
  sells) returns that view mode as markdown, announced with a `Link`
  header. No other module needed. Every entity page says `Vary: Accept`,
  and the page cache never answers a request for markdown or one that pays.
- **`/x402/discovery`**: everything the site sells, one item per product,
  in the shape of Circle's Discovery API, so a client written for Circle's
  Agent Marketplace reads it unchanged. Every entity type with the field;
  `limit` and `offset`; cached until an entity or a field changes. What
  cannot be sold (no usable seller key) is left out, not an error. List
  prices: the price event may charge otherwise at the time of sale. For
  people, build a View: the field gives Views a *Charge for this* filter
  and the price formatter.
- **The price event** (`Drupal\x402_pay\Event\PriceEvent`): before every
  sale, with the entity in the request's language, the view mode, the
  request and the account. A subscriber can change the price, make it free
  or deny it, choose the seller (one wallet per entity, e.g. per listing of
  a market) and say what the buyer gets (`mimeType`) and how long the payment stays
  valid (`setTimeout()`: a slow delivery needs more than the default 60 s,
  since settling comes after it). It starts at the
  field's price, free for those with *Access x402 content free*. The
  catalogue asks the same question, so it announces what the sale will
  charge, and to whom. ECA support (`x402_pay_eca`) comes later.
- **The delivery event** (`Drupal\x402_pay\Event\DeliveryEvent`): once the
  payment verified, a subscriber can answer instead of the markdown, e.g.
  call a service with the request's input (any method, POST included). A
  response other than 2xx is never charged.
- **The payment event** (`Drupal\x402_pay\Event\PaymentEvent`): every
  outcome the log records (`settled`, `not_settled`, `failed`), with the
  same entry and the request: for accounting, reputation or a notice to the
  seller.
- **Coming:** `x402_pay_commerce`, a Drupal Commerce payment gateway on top
  of it, so a person can check out in USDC.

`scripts/buy.mjs <url> [max USDC] [JSON input]` is a buyer agent in one
file: it reads the 402, signs the payment and prints what it bought, where
the time went (with the seller's `Server-Timing`) and the seller's balance
on chain before and after. With a JSON input it POSTs it, for services.

Arc mainnet (`eip155:5042`) and testnet (`eip155:5042002`). Wallet keys and
API keys go through the `key` module from an environment variable, never in
exportable config.

Built for [Ombú](https://github.com/quebracho-digital/ombu), the agent mall;
plan and status in its [ROADMAP](https://github.com/quebracho-digital/ombu/blob/main/ROADMAP.md).

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
