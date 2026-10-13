// Paying an x402 URL with nothing but viem: what any x402 client does.
// buy.mjs and Ombú's buyer agent both pay through here.
import { createPublicClient, http, parseAbi, toHex } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { Agent } from "undici";

const RPC = { "eip155:5042": "https://rpc.mainnet.arc.io", "eip155:5042002": "https://rpc.testnet.arc.network" };
/** Where people see a transaction, by network. */
export const EXPLORER = { "eip155:5042": "https://explorer.arc.io/tx/", "eip155:5042002": "https://testnet.arcscan.app/tx/" };
const decode = (h) => JSON.parse(Buffer.from(h, "base64").toString());
const ms = (t0) => Math.round(performance.now() - t0);

/** A USDC balance on chain, in atomic units. */
export const balanceOf = (network, asset, who) => createPublicClient({ transport: http(RPC[network]) })
  .readContract({ address: asset, abi: parseAbi(["function balanceOf(address) view returns (uint256)"]), functionName: "balanceOf", args: [who] });

/** The buyer's account, from BUYER_PRIVATE_KEY. */
export const buyer = () => privateKeyToAccount(process.env.BUYER_PRIVATE_KEY);

/**
 * Calls url (a POST with input as JSON body when given) and pays its 402.
 *
 * approve(req, required) runs after the 402 and before signing: it throws to
 * refuse (over budget), and anything it awaits happens before the payment.
 * Returns the paid response: status, contentType, body, receipt (the
 * decoded PAYMENT-RESPONSE, or null), req, the seller's Server-Timing and
 * our own timings in ms.
 */
export async function pay(url, { input, account = buyer(), approve = () => {} } = {}) {
  const body = input === undefined ? undefined : (typeof input === "string" ? input : JSON.stringify(input));
  const call = (headers, dispatcher) => fetch(url, body === undefined
    ? { dispatcher, headers: { Accept: "*/*", ...headers } }
    : { dispatcher, method: "POST", body, headers: { Accept: "*/*", "Content-Type": "application/json", ...headers } });
  const t = {};

  // 1. Ask: the 402 says what to pay, to whom, on which chain.
  let t0 = performance.now();
  const first = await call({});
  t.ask = ms(t0);
  if (first.status !== 402) throw new Error(`Expected 402, got ${first.status}: ${(await first.text()).slice(0, 500)}`);
  const required = decode(first.headers.get("PAYMENT-REQUIRED"));
  const req = required.accepts[0];
  // The budget lives in the buyer, not on the seller's word.
  await approve(req, required);

  // 2. Sign an EIP-3009 transfer for exactly that: no gas, no allowance.
  t0 = performance.now();
  const authorization = {
    from: account.address, to: req.payTo, value: BigInt(req.amount),
    validAfter: 0n, validBefore: BigInt(Math.floor(Date.now() / 1000) + req.maxTimeoutSeconds),
    nonce: toHex(crypto.getRandomValues(new Uint8Array(32))),
  };
  const signature = await account.signTypedData({
    domain: { name: req.extra.name, version: req.extra.version, chainId: Number(req.network.split(":")[1]), verifyingContract: req.asset },
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

  // 3. Pay: the same request, with the signed payment. Wait as long as the
  // payment can still settle: a buyer that hangs up earlier (fetch gives up
  // after 300 s) may be charged for an answer it never reads.
  t0 = performance.now();
  const wait = (req.maxTimeoutSeconds + 30) * 1000;
  const paid = await call({ "PAYMENT-SIGNATURE": Buffer.from(JSON.stringify(payment)).toString("base64") }, new Agent({ headersTimeout: wait, bodyTimeout: wait }));
  const text = await paid.text();
  t.pay = ms(t0);
  const receipt = paid.headers.get("PAYMENT-RESPONSE");
  const server = Object.fromEntries((paid.headers.get("Server-Timing") ?? "").split(",").filter(Boolean).map((m) => {
    const [name, dur] = m.trim().split(";dur=");
    return [name.replace("x402-", ""), Number(dur)];
  }));
  return { status: paid.status, ok: paid.ok, contentType: paid.headers.get("Content-Type"), body: text, receipt: receipt ? decode(receipt) : null, req, required, server, t };
}
