// A buyer agent in one file: GET a URL, pay its 402 on Arc testnet, print
// what it bought. What any x402 client does, with nothing but viem.
// Run: node --env-file=.env buy.mjs <url> [max USDC, default 0.05]
// For DDEV's certificate: NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"
import { toHex } from "viem";
import { privateKeyToAccount } from "viem/accounts";

const [url, max = "0.05"] = process.argv.slice(2);
if (!url) throw new Error("Usage: buy.mjs <url> [max USDC]");
const buyer = privateKeyToAccount(process.env.BUYER_PRIVATE_KEY);
const decode = (h) => JSON.parse(Buffer.from(h, "base64").toString());
const ms = (t0) => Math.round(performance.now() - t0);
const t = {};

// 1. Ask: the 402 says what to pay, to whom, on which chain.
let t0 = performance.now();
const first = await fetch(url, { headers: { Accept: "*/*" } });
t.ask = ms(t0);
if (first.status !== 402) throw new Error(`Expected 402, got ${first.status}: ${await first.text()}`);
const required = decode(first.headers.get("PAYMENT-REQUIRED"));
const req = required.accepts[0];
const chainId = Number(req.network.split(":")[1]);
console.log(`402 → ${req.amount} atomic units to ${req.payTo} on ${req.network} for "${required.resource.description}"`);
// The budget lives here, in the buyer, not on the seller's word.
if (BigInt(req.amount) > BigInt(Math.round(Number(max) * 1e6))) throw new Error(`Over budget: ${req.amount} > ${max} USDC`);

// 2. Sign an EIP-3009 transfer for exactly that: no gas, no allowance.
t0 = performance.now();
const authorization = {
  from: buyer.address, to: req.payTo, value: BigInt(req.amount),
  validAfter: 0n, validBefore: BigInt(Math.floor(Date.now() / 1000) + req.maxTimeoutSeconds),
  nonce: toHex(crypto.getRandomValues(new Uint8Array(32))),
};
const signature = await buyer.signTypedData({
  domain: { name: req.extra.name, version: req.extra.version, chainId, verifyingContract: req.asset },
  types: { TransferWithAuthorization: [
    { name: "from", type: "address" }, { name: "to", type: "address" }, { name: "value", type: "uint256" },
    { name: "validAfter", type: "uint256" }, { name: "validBefore", type: "uint256" }, { name: "nonce", type: "bytes32" },
  ] },
  primaryType: "TransferWithAuthorization",
  message: authorization,
});
t.sign = ms(t0);
const payment = {
  x402Version: 2,
  resource: required.resource,
  accepted: { ...req, extra: { ...req.extra, assetTransferMethod: "eip3009" } },
  payload: { signature, authorization: Object.fromEntries(Object.entries(authorization).map(([k, v]) => [k, String(v)])) },
  extensions: { "payment-identifier": { info: { required: true, id: `buy_${authorization.nonce.slice(2, 26)}` } } },
};

// 3. Pay: the same request, with the signed payment.
t0 = performance.now();
const paid = await fetch(url, { headers: { Accept: "*/*", "PAYMENT-SIGNATURE": Buffer.from(JSON.stringify(payment)).toString("base64") } });
const body = await paid.text();
t.pay = ms(t0);
const receipt = paid.headers.get("PAYMENT-RESPONSE");
console.log(`paid → HTTP ${paid.status} ${paid.headers.get("Content-Type")}`);
if (receipt) {
  const r = decode(receipt);
  console.log(r.success ? `tx https://testnet.arcscan.app/tx/${r.transaction}` : `not settled: ${JSON.stringify(r)}`);
}
// Where the time went: ours (ask, sign, pay) and the seller's Server-Timing.
const server = Object.fromEntries((paid.headers.get("Server-Timing") ?? "").split(",").filter(Boolean).map((m) => {
  const [name, dur] = m.trim().split(";dur=");
  return [name.replace("x402-", ""), Number(dur)];
}));
console.log(`timing ms → total ${t.ask + t.sign + t.pay} = ask ${t.ask} + sign ${t.sign} + pay ${t.pay}; pay = ${Object.entries(server).map(([k, v]) => `${k} ${v}`).join(" + ")} + rest ${Math.round(t.pay - Object.values(server).reduce((a, b) => a + b, 0))}`);
console.log("---\n" + body);
if (!paid.ok) process.exit(1);
