// Test vectors for the PHP side, produced by viem (the same code that
// settled the day-1 payment against Circle). PHP must match them byte for
// byte. Run: node make-vectors.mjs > ../tests/fixtures/vectors.json
import { encodeFunctionData, hashTypedData, keccak256, parseAbi, toBytes } from "viem";
import { privateKeyToAccount } from "viem/accounts";

// Anvil's first dev key: public, never holds funds.
const key = "0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80";
const seller = privateKeyToAccount(key);

const body = '{"x402Version":2,"hello":"ombú"}';
const domain = { name: "Circle Facilitator Seller Request", version: "1", chainId: 5042002 };
const types = {
  SellerRequest: [
    { name: "purpose", type: "string" }, { name: "method", type: "string" }, { name: "bodyHash", type: "bytes32" },
    { name: "network", type: "string" }, { name: "payTo", type: "address" }, { name: "nonce", type: "bytes32" },
    { name: "issuedAt", type: "uint64" }, { name: "expiresAt", type: "uint64" },
  ],
};
const message = {
  purpose: "settle", method: "POST", bodyHash: keccak256(toBytes(body)),
  network: "eip155:5042002", payTo: seller.address,
  nonce: "0x" + "ab".repeat(32), issuedAt: 1760000000n, expiresAt: 1760000300n,
};
const digest = hashTypedData({ domain, types, primaryType: "SellerRequest", message });
const signature = await seller.signTypedData({ domain, types, primaryType: "SellerRequest", message });

const envelope = {
  version: 1, signature, network: message.network, payTo: message.payTo,
  nonce: message.nonce, issuedAt: Number(message.issuedAt), expiresAt: Number(message.expiresAt),
};

// A sweep: an EIP-1559 USDC transfer on Arc testnet, signed by the seller.
const tx = {
  type: "eip1559", chainId: 5042002, nonce: 7, maxPriorityFeePerGas: 1000000000n, maxFeePerGas: 50000000000n, gas: 65000n,
  to: "0x3600000000000000000000000000000000000000", value: 0n,
  data: encodeFunctionData({ abi: parseAbi(["function transfer(address,uint256) returns (bool)"]), functionName: "transfer", args: ["0x70997970C51812dc3A010C7d01b50e0d17dc79C8", 1234567n] }),
};
const raw = await seller.signTransaction(tx);

console.log(JSON.stringify({
  privateKey: key,
  address: seller.address,
  body,
  bodyHash: message.bodyHash,
  sellerRequest: {
    domain, types,
    message: { ...message, issuedAt: Number(message.issuedAt), expiresAt: Number(message.expiresAt) },
    digest, signature,
    header: Buffer.from(JSON.stringify(envelope)).toString("base64url"),
  },
  transfer: {
    chainId: tx.chainId, nonce: tx.nonce, maxPriorityFeePerGas: tx.maxPriorityFeePerGas.toString(), maxFeePerGas: tx.maxFeePerGas.toString(), gas: tx.gas.toString(),
    token: tx.to, to: "0x70997970C51812dc3A010C7d01b50e0d17dc79C8", amount: "1234567", raw,
  },
}, null, 2));
