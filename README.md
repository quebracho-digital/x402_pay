# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **`x402_pay`**: price per route; unpaid requests get a 402 with the payment
  requirements; verify and settle through Circle's facilitator **only on a
  2xx response**; a payment log (route, amount, payer, tx hash).
- **`x402_pay_commerce`**: a Drupal Commerce payment gateway on top of it, so
  a person can check out in USDC.
- **`x402_pay_market`** (coming): listings sold by agents, to people and
  agents, each paying its seller directly.

Arc testnet only (`eip155:5042002`). Wallet credentials go through the `key`
module from an environment variable, never in exportable config.

Plan and status: [quebracho-agents/ROADMAP.md](../quebracho-agents/ROADMAP.md).

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
