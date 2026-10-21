# x402_pay

Drupal charges in USDC on **Arc** (and **Solana**) over HTTP 402 (x402):

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
    price. No third party, no permission. EVM only.
  - **Any other x402 facilitator**, by URL, for a network: `/verify`,
    `/settle`, and `/supported` for the fee payer that sponsors a Solana
    payment, which the 402 names (`extra.feePayer`); a bearer token from
    the Key module if it asks for one. Solana is settled this way (e.g.
    `https://x402.org/facilitator` on devnet). Ours first, then this one,
    then Circle's.
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

- **The buyer** (`Drupal\x402_pay\Buyer`): the other side, for a site or
  a script that buys from agents. It reads the 402, lets a callback refuse
  the price (a budget), signs one EIP-3009 transfer and asks again, paid.
  With a Solana key (`SolanaKey`) it pays the 402's Solana offer instead:
  one USDC transfer to the seller's token account, the fees left to the
  facilitator's fee payer (`Solana`, the layout the `exact` scheme's
  facilitators check). From the command line: `BUYER_PRIVATE_KEY=0x… drush
  x402:buy <url> [--input='{"q": 1}'] [--max=0.05]`; a Solana key (base58,
  or solana-keygen's JSON array) in the same variable pays on Solana.

**No Node.js, no npm.** Everything is signed in PHP (`Signer`, `Eip712`, on
`kornrunner/keccak` and `kornrunner/secp256k1`; Solana's ed25519 with
sodium), and the tests check it
against vectors that viem produced once (`tests/fixtures/`), byte for byte.
The Node scripts this module started with (a viem buyer and the vector
generator) are gone, by rule: no dependency tree we did not read gets near
a wallet key. See [Ombú's README](https://github.com/quebracho-digital/ombu#no-nodejs-on-purpose).

Arc mainnet (`eip155:5042`) and testnet (`eip155:5042002`). Wallet keys and
API keys go through the `key` module from an environment variable, never in
exportable config.

Built for [Ombú](https://github.com/quebracho-digital/ombu), the agent mall;
plan and status in its [ROADMAP](https://github.com/quebracho-digital/ombu/blob/main/ROADMAP.md).

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
