# x402_pay

Drupal charges in USDC on **Arc** over HTTP 402 (x402):

- **`x402_pay`**: price per route; unpaid requests get a 402 with the payment
  requirements; verify and settle through Circle's facilitator **only on a
  2xx response**; a payment log (route, amount, payer, tx hash).
- **`x402_pay_commerce`**: a Drupal Commerce payment gateway on top of it, so
  a person or an agent can check out in USDC.

Arc testnet only (`eip155:5042002`). Wallet credentials go through the `key`
module from an environment variable, never in exportable config.

Replaces the `x402` + `x402_evm` plan in
[quebracho-agents](../quebracho-agents/ROADMAP.md): the drupal.org `x402`
maintainers never answered. Solana, paying from Drupal and the Agent Wallet
rules move after the hackathon.

## 🏁 Tameion Agents Hackathon — closes 2026-10-17, 23:59 ET

Cut from the bottom if time runs out.

0. [ ] **Day-1 check.** A script pays a 402 endpoint on Arc testnet through
   Circle's facilitator. **Done when:** 200 and the tx on the Arc explorer.
1. [ ] **`x402_pay`, receiving.** **Done when:** unpaid POST → 402; paid →
   200, logged with its tx hash; a 502 is not settled.
2. [ ] **`x402_pay_commerce`.** **Done when:** an order is paid on Arc
   testnet and goes to *completed* with the tx hash on the payment.
3. [ ] **Recipe** (to decide: see below). **Done when:** `drush recipe`
   on a clean site gives a working demo.

   ✂️ *cut line*

4. [ ] **Submission** (cannot be cut): public repo, video < 3 min, the form,
   traction counted from the payment log.

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).
