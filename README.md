# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **`x402_pay`**: the caller sets price and `payTo` per request; unpaid requests get a 402 with the payment
  requirements; verify and settle through Circle's facilitator **only on a
  2xx response**; a payment log (route, amount, payer, tx hash).
- **The `x402_price` field**: add it to any fieldable entity and agents pay
  to read it. Set the price, the network and the seller key on the field;
  `payTo` is the key's own address, never typed. Each entity can switch
  charging off (permission *Set x402 prices*) and, if the field allows it,
  set its own price. Agents are charged on the entity's own URL (a
  `PAYMENT-SIGNATURE`, an `Accept` without `text/html`, or a known
  User-Agent in `x402_pay.settings`) and on `/x402/{entity_type}/{id}`,
  announced with a `Link` header; browsers still see the page. A paid
  request gets the full display as markdown.
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
