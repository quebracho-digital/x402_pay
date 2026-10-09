// Day-1 check: a buyer pays a seller 0.01 USDC on Arc testnet through
// Circle's Facilitator Service (keyless trial), and we read the tx back.
// Run: node --env-file-if-exists=.env arc-pay-check.mjs
// First run writes .env with two fresh testnet keys; fund the buyer at
// https://faucet.circle.com (Arc Testnet, USDC) and run again.
import { appendFileSync } from "node:fs";
import { createPublicClient, defineChain, http, keccak256, toBytes, toHex, formatUnits, parseAbi } from "viem";
import { generatePrivateKey, privateKeyToAccount } from "viem/accounts";

const CHAIN_ID = 5042002;
const NETWORK = `eip155:${CHAIN_ID}`;
const USDC = "0x3600000000000000000000000000000000000000"; // ERC-20 interface, 6 decimals
const FACILITATOR = "https://api.circle.com/v1/facilitator/x402";
const AMOUNT = 10000n; // 0.01 USDC

if (!process.env.BUYER_PRIVATE_KEY || !process.env.SELLER_PRIVATE_KEY) {
  const b = generatePrivateKey(), s = generatePrivateKey();
  appendFileSync(".env", `BUYER_PRIVATE_KEY=${b}\nSELLER_PRIVATE_KEY=${s}\n`);
  console.log(`Wrote .env. Fund the buyer with Arc testnet USDC, then run again:\n  buyer  ${privateKeyToAccount(b).address}\n  seller ${privateKeyToAccount(s).address}`);
  process.exit(0);
}

const buyer = privateKeyToAccount(process.env.BUYER_PRIVATE_KEY);
const seller = privateKeyToAccount(process.env.SELLER_PRIVATE_KEY);
const payTo = seller.address;

const arc = defineChain({
  id: CHAIN_ID, name: "Arc Testnet",
  nativeCurrency: { name: "USDC", symbol: "USDC", decimals: 18 },
  rpcUrls: { default: { http: ["https://rpc.testnet.arc.network"] } },
});
const rpc = createPublicClient({ chain: arc, transport: http() });
const balanceOf = (a) => rpc.readContract({ address: USDC, abi: parseAbi(["function balanceOf(address) view returns (uint256)"]), functionName: "balanceOf", args: [a] });

const before = { buyer: await balanceOf(buyer.address), seller: await balanceOf(payTo) };
console.log(`buyer ${buyer.address} ${formatUnits(before.buyer, 6)} USDC · seller ${payTo} ${formatUnits(before.seller, 6)} USDC`);
if (before.buyer < AMOUNT) throw new Error("Buyer has no USDC: fund it at https://faucet.circle.com");

const nonce32 = () => toHex(crypto.getRandomValues(new Uint8Array(32)));

// 1. Buyer signs the EIP-3009 authorization (what an x402 client sends).
const authorization = {
  from: buyer.address, to: payTo, value: AMOUNT,
  validAfter: 0n, validBefore: BigInt(Math.floor(Date.now() / 1000) + 3600), nonce: nonce32(),
};
const signature = await buyer.signTypedData({
  domain: { name: "USDC", version: "2", chainId: CHAIN_ID, verifyingContract: USDC },
  types: { TransferWithAuthorization: [
    { name: "from", type: "address" }, { name: "to", type: "address" }, { name: "value", type: "uint256" },
    { name: "validAfter", type: "uint256" }, { name: "validBefore", type: "uint256" }, { name: "nonce", type: "bytes32" },
  ] },
  primaryType: "TransferWithAuthorization",
  message: authorization,
});

// 2. Seller proof: proves we control payTo and binds purpose + body.
async function proof(purpose, method, body) {
  const nonce = nonce32(), issuedAt = Math.floor(Date.now() / 1000), expiresAt = issuedAt + 300;
  const sig = await seller.signTypedData({
    domain: { name: "Circle Facilitator Seller Request", version: "1", chainId: CHAIN_ID },
    types: { SellerRequest: [
      { name: "purpose", type: "string" }, { name: "method", type: "string" }, { name: "bodyHash", type: "bytes32" },
      { name: "network", type: "string" }, { name: "payTo", type: "address" }, { name: "nonce", type: "bytes32" },
      { name: "issuedAt", type: "uint64" }, { name: "expiresAt", type: "uint64" },
    ] },
    primaryType: "SellerRequest",
    message: { purpose, method, bodyHash: keccak256(toBytes(body)), network: NETWORK, payTo, nonce, issuedAt: BigInt(issuedAt), expiresAt: BigInt(expiresAt) },
  });
  return Buffer.from(JSON.stringify({ version: 1, signature: sig, network: NETWORK, payTo, nonce, issuedAt, expiresAt })).toString("base64url");
}

// 3. Settle.
const requirements = {
  scheme: "exact", network: NETWORK, amount: String(AMOUNT), asset: USDC, payTo,
  maxTimeoutSeconds: 12, extra: { name: "USDC", version: "2" },
};
const body = JSON.stringify({
  x402Version: 2,
  paymentPayload: {
    x402Version: 2,
    resource: { url: "https://example.invalid/day1-check", description: "x402_pay day-1 check", mimeType: "application/json" },
    accepted: { ...requirements, extra: { ...requirements.extra, assetTransferMethod: "eip3009" } },
    payload: { signature, authorization: Object.fromEntries(Object.entries(authorization).map(([k, v]) => [k, String(v)])) },
    extensions: { "payment-identifier": { info: { required: true, id: `day1_${authorization.nonce.slice(2, 26)}` } } },
  },
  paymentRequirements: requirements,
});
const res = await fetch(`${FACILITATOR}/settle`, {
  method: "POST", body,
  headers: { "Content-Type": "application/json", "Facilitator-Seller-Proof": await proof("settle", "POST", body) },
});
let out = await res.json().catch(() => ({}));
console.log(`settle → HTTP ${res.status}`, JSON.stringify(out));
if (!res.ok) process.exit(1);

// 4. Pending → poll /status until terminal.
const paymentId = out.extensions?.["settlement-status"]?.paymentId;
for (let i = 0; !out.success && paymentId && i < 20; i++) {
  await new Promise((r) => setTimeout(r, 3000));
  const s = await fetch(`${FACILITATOR}/status/${paymentId}`, { headers: { "Facilitator-Seller-Proof": await proof("status", "GET", "") } });
  const st = await s.json();
  console.log(`status → ${st.status}`);
  if (st.status === "completed") out = { success: true, transaction: st.transaction };
  if (st.status === "failed") process.exit(1);
}
if (!out.success) process.exit(1);

const after = { buyer: await balanceOf(buyer.address), seller: await balanceOf(payTo) };
console.log(`tx https://testnet.arcscan.app/tx/${out.transaction}`);
console.log(`buyer ${formatUnits(after.buyer - before.buyer, 6)} · seller +${formatUnits(after.seller - before.seller, 6)} USDC`);
