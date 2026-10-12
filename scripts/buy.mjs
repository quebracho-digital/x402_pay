// A buyer agent in one file: call a URL, pay its 402 on Arc testnet, print
// what it bought and the seller's balance on chain before and after.
// Run: node --env-file=.env buy.mjs <url> [max USDC, default 0.05] [JSON input]
// With a JSON input the call is a POST with it as the body (a service).
// For DDEV's certificate: NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"
import { formatUnits } from "viem";
import { balanceOf, pay } from "./x402.mjs";

const [url, max = "0.05", input] = process.argv.slice(2);
if (!url) throw new Error("Usage: buy.mjs <url> [max USDC] [JSON input]");

let sellerBefore;
const paid = await pay(url, {
  input,
  approve: async (req, required) => {
    console.log(`402 → ${req.amount} atomic units to ${req.payTo} on ${req.network} for "${required.resource.description}"`);
    if (BigInt(req.amount) > BigInt(Math.round(Number(max) * 1e6))) throw new Error(`Over budget: ${req.amount} > ${max} USDC`);
    sellerBefore = await balanceOf(req.network, req.asset, req.payTo);
  },
});
const { req, receipt, server, t } = paid;
console.log(`paid → HTTP ${paid.status} ${paid.contentType}`);
if (receipt) {
  console.log(receipt.success ? `tx https://testnet.arcscan.app/tx/${receipt.transaction}` : `not settled: ${JSON.stringify(receipt)}`);
}
// Where the time went: ours (ask, sign, pay) and the seller's Server-Timing.
console.log(`timing ms → total ${t.ask + t.sign + t.pay} = ask ${t.ask} + sign ${t.sign} + pay ${t.pay}; pay = ${Object.entries(server).map(([k, v]) => `${k} ${v}`).join(" + ")} + rest ${Math.round(t.pay - Object.values(server).reduce((a, b) => a + b, 0))}`);
const sellerAfter = await balanceOf(req.network, req.asset, req.payTo);
console.log(`seller ${req.payTo} on chain: +${formatUnits(sellerAfter - sellerBefore, 6)} USDC`);
console.log("---\n" + paid.body);
if (!paid.ok) process.exit(1);
