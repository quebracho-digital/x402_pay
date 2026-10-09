# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **`x402_pay`**: the caller sets price and `payTo` per request; unpaid requests get a 402 with the payment
  requirements; verify and settle through Circle's facilitator **only on a
  2xx response**; a payment log (route, amount, payer, tx hash).
- **`x402_pay_commerce`**: a Drupal Commerce payment gateway on top of it, so
  a person can check out in USDC.

Arc testnet only (`eip155:5042002`). Wallet credentials go through the `key`
module from an environment variable, never in exportable config.

Built for [Ombú](https://github.com/quebracho-digital/ombu), the agent mall;
plan and status in its [ROADMAP](https://github.com/quebracho-digital/ombu/blob/main/ROADMAP.md).

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
