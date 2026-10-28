# Feedback for `quebracho/x402`, from refactoring `x402_pay` onto it

Date: 2026-10-28. Branch `refactor/use-quebracho-x402`. Library read at
`packages/x402` (src/, README, ROADMAP, docs/near.md), not changed.

The module was rewritten as if each gap below were already closed, so the
code says what it wants. Each code comment `LIBRARY-FEEDBACK Gn` points
here. Nothing was run: no composer, no network, no facilitator.

Severity: **Blocker** (the module cannot work without it), **High** (works
but loses a feature or safety the module had), **Medium** (workaround in
the module, fragile), **Low** (comfort).

## What the library already covers, as used

`PaymentHeaders`, `Eip712`, `Signer` (address, sign, `checksum`),
`Transfer::sign` (sweeper), `Networks::requirements/atomic/get/add/TIMEOUT`,
`EvmExact` (offline verify, with the right reason codes),
`X402Middleware` (402, verify, claim, settle only on 2xx, receipt header,
failed receipt on a 402), `Settlement::key`, `AddressCheck` through the
Drupal event dispatcher (Symfony's implements PSR-14, so site code can
screen payers for free), `PayingHttpClient` and `Budget`. That is the
whole of `Eip712`, `Signer`, `Transfer`, `PaymentHeaders`, `Networks` and
`InvalidPaymentException` deleted from the module, plus the entire
`PaymentGate` protocol flow and `matches()`.

## Gaps

### G7. Facilitator clients are missing (Blocker)

The library only has a `$settle` closure. The module had three clients, all
protocol, none Drupal: Circle's (with the EIP-712 seller proof and
`/status` polling), any x402 facilitator by URL (`/verify`, `/settle`,
`/supported`), and our own on chain (`eth_call` to verify, a signed
EIP-1559 `transferWithAuthorization` to settle). They were deleted and
`Drupal\x402_pay\Facilitators` builds them from the library. Needed, in
`Quebracho\X402\Facilitator`, all taking PSR-18 + PSR-17:

```php
interface Facilitator {
    /** @return array{isValid: bool, payer?: string, invalidReason?: string} */
    public function verify(array $payment, array $req): array;
    /** @return array{success: bool, transaction: string, network: string, payer?: string, errorReason?: string} */
    public function settle(array $payment, array $req): array;
}
// All throw Quebracho\X402\FacilitatorException when unreachable or not an x402 answer.

final class HttpFacilitator implements Facilitator {
    public function __construct(ClientInterface $http, RequestFactoryInterface $requests,
        StreamFactoryInterface $streams, string $url, ?string $bearer = null);
    public function supported(): array;                  // "kinds" of /supported
    public function feePayer(string $network): ?string;  // Solana: extra.feePayer
}
final class CircleFacilitator implements Facilitator {
    public const BASE = 'https://api.circle.com/v1/facilitator/x402';
    public function __construct(ClientInterface $http, RequestFactoryInterface $requests,
        StreamFactoryInterface $streams, ?string $apiKey = null, ?Signer $seller = null,
        string $base = self::BASE, int $pollDelaySeconds = 1, int $pollAttempts = 5);
    // keyless without $seller and without $apiKey -> FacilitatorException on first call
}
final class ChainFacilitator implements Facilitator {
    public function __construct(Rpc $rpc, Signer $gasWallet, ?\Closure $serialize = null,
        int $pollDelayMs = 500, int $pollAttempts = 40);
    // $serialize(string $name, callable $run): mixed -- one sender, one nonce at a time
}
final class Rpc {  // JSON-RPC, PSR-18
    public function __construct(ClientInterface $http, RequestFactoryInterface $requests,
        StreamFactoryInterface $streams, string $url);
    public function __invoke(string $method, array $params): mixed; // \RuntimeException on node error
}
```

Where used: `Facilitators::for()`, `Facilitators::feePayer()`,
`PaymentGate`, `Sweeper`. Notes for whoever writes them: Circle's `/settle`
can answer pending with a `paymentId` to poll; some facilitators answer a
refusal as a 4xx with an x402 body (`isValid`/`success` false), which is an
answer, not an exception; `ChainFacilitator` returns `errorReason:
'settlement_pending'` with the transaction when it sent but saw no receipt
in time (the module keeps the claim in that case); the lock is a closure
because the library must not know Drupal's lock service.

### G6. Solana (`SolanaExact`, `SolanaKey`) (Blocker for Solana sellers; High overall)

ROADMAP lists `SolanaExact`. The module needs, to keep what it had:

```php
final class SolanaKey { __construct(string $key); address(): string; sign(string $message): string; }
final class SolanaExact implements Scheme {
    public function __construct(?SolanaKey $buyer = null, ?Rpc $rpc = null, ?\Closure $clock = null);
    // supports('solana:*'); pay() builds the v0 TransferChecked + memo + compute budget message
    // verify(): offline checks of the transaction against $req (amount, mint, payTo ATA, fee payer not in instructions)
    // payer(): the transaction's authority
    public function unsignedTransaction(array $req, string $buyerAddress): string; // base64, for a browser wallet
}
```

Where used: `PaymentGate` (`schemes: [new EvmExact(), new SolanaExact()]`),
`SolanaTransactionController` (`unsignedTransaction`), `Drush x402:buy`.
Related, same cause:

- `Budget::allows()` is EVM only: it requires `payTo` to be `0x…`, and the
  token's `extra.name/version` to match. A Solana offer has neither, so
  `PayingHttpClient` never pays Solana. Make `allows()` ask the `Scheme`
  (`Scheme::allows(array $req): bool` or a per-family address check).
- `Settlement::key()` for non-EVM hashes "the whole payload". For a Solana
  transaction that includes the buyer's signature and the fee payer's empty
  slot, which the facilitator later fills; the module previously keyed on the
  buyer's signed transaction. Say in the docs whether the key must be stable
  across the facilitator adding its signature (it is computed before).
- `Networks` should carry the Solana devnet USDC (the ROADMAP item) and a
  way to add a non-EVM network, see G1.

### G1. `Networks` catalogue (High)

Used as `Networks::all()` (form options, defaults, views fields, sweeper)
and `isset(Networks::all()[$id])`. Missing:

```php
public static function all(): array;        // built in + added, id => settings
public static function has(string $network): bool;
public static function find(string $network): ?array;  // get() throws; callers want NULL
public static function addSolana(string $genesis, string $mint, string $label = '', int $decimals = 6,
    string $rpc = '', string $explorer = ''): string;     // returns "solana:<genesis>"
```

Also: `$added` is process-global static state. A module that registers its
mainnets once is fine; tests cannot reset it. Offer `Networks::reset()` (or
an instance `NetworkCatalog` passed to the middleware and `Budget`).
`Networks::requirements()` does not checksum or validate `payTo` (the module
does `Signer::checksum` itself): consider validating by family.

Policy question, not a bug: the README says "testnet only; no mainnet
built in" and `Networks::add` leaves mainnet to the caller. The module
registers Arc (5042) and Solana mainnet in `ModuleNetworks`. If that is the
intended pattern, document it as the supported way.

### G3. No online verify step in the middleware (High)

`Scheme::verify()` is offline and the middleware never asks a facilitator
before running the handler. The module did (`/verify`, or `eth_call`), so
an unfunded or already-spent payment cost nothing: no handler run, no
side effects. Without it, the handler runs for a payment that settle then
rejects (the body is withheld, but the work was done, and a handler may
have side effects). Proposed, on both middlewares:

```php
?\Closure $verify = null  // fn(array $payment, array $req): ?string -- a reason, or NULL when good
```

Called after the offline verify and address screening, before the claim.
Used in `PaymentGate` (`verify:`). Note the order matters: before the
claim, so a bad payment does not occupy it.

### G2. The 402's `resource` cannot be set (High)

`X402Middleware` fills `resource` with `['url' => (string) $request->getUri()]`.
The module needs the canonical URL of the entity sold (the request may be
`?_format=`, a markdown route or an alias) plus `description` and `mimeType`
(discovery clients and Agent Marketplace read them). Proposed:

```php
?\Closure $resource = null  // fn(ServerRequestInterface): array{url: string, description?: string, mimeType?: string}
```

Used in `PaymentGate` (`resource:`). Same for `X402McpMiddleware` if it
fixes one. Also: nothing in `verify` compares the payment's own
`resource.url` with ours; for `exact` the signature does not commit to the
resource, so a payment is good for any resource of the same price and
`payTo` (single use, thanks to the claim). Worth saying in the docs and, if
wanted, an opt-in check (x402-conformance R11 family).

### G4. `upfront` flow (Medium)

The module sells some products settling first, then running the handler
(`PriceEvent::settlesFirst()`, announced as `extra.paymentFlow =
'upfront'`), for handlers that are expensive or irreversible. The library
only does handler-then-settle. Proposed: the middleware honours
`$req['extra']['paymentFlow'] === 'upfront'`: after the claim, settle; on
failure answer the 402; on success run the handler and attach the receipt
whatever the handler's status (money moved; the status is the caller's to
log). Used: `Delivery::requirements()`, `PaymentGate` (the gate does not
branch: it expects the middleware to).

### G5. A throwing `$settle` is indistinguishable from a bad payment (Medium)

`Settlement::run()` catches `\Throwable` and answers `unexpected_settle_error`
as a 402. For "the facilitator is down" the module answered 502 (not the
buyer's fault, retry later; a monitoring signal). Workaround in
`PaymentGate`: record the exception in a captured variable and replace the
402 afterwards. Proposed: let a `FacilitatorException` (or a documented
`SettleUnavailable`) pass through the middleware so the caller maps it, or
let `$settle` return `['success' => false, 'unavailable' => true, ...]` and
answer 502/503 with the failed receipt. The same for the online verify (G3).

### G8. Claim lifecycle: no release (High)

`$claim` is a one-way `fn(string $key): bool`. The claim is consumed before
the handler, so a handler that answers 5xx, a deadline, or a settle that
never broadcast leaves the buyer's signed payment unusable although nothing
was charged. The module releases it itself, which needs the payment's key
(`Settlement::key(PaymentHeaders::decode(header))`, decoded a second time)
and runs from inside the handler and settle closures. Proposed: an optional
second callback `?\Closure $release` (or an interface `Claims { claim(); release(); }`
that `FileClaims` implements) which the middleware calls itself when the
handler is not 2xx, or when settle fails with an empty `transaction`; not
when a transaction was sent (`settlement_pending`). Used: `ClaimStore`,
`PaymentGate`. Also `FileClaims` never prunes (noted in its docblock): a
`prune(int $olderThan)` would help; the module prunes its own table on cron.

### G10. No outcome hook (Medium)

The module logs every outcome (settled / not_settled / failed, handler
status, payer, tx) and dispatches its own event. The middleware exposes
none, so `PaymentGate` infers the outcome by wrapping the handler and the
settle closure and reading shared state afterwards. It works but depends on
the order the middleware calls them. Proposed: a PSR-14 event, or
`?\Closure $onOutcome`, with `{payment, req, status: 'settled'|'not_settled'|'failed'|'rejected',
handlerStatus, receipt, reason}`. Same hook would give the `Server-Timing`
steps (verify, handler, settle) without wrapping each closure.

### G9. `PayingHttpClient` cannot lengthen the paid retry (Medium)

PSR-18 has no per-request options, so the paid retry runs with the wrapped
client's timeout (Drupal's default is 30 s). A buyer that hangs up before
the seller settles may be charged for an answer it never reads (the old
`Buyer` waited `maxTimeoutSeconds + 30`). Workaround: `x402:buy` builds its
own Guzzle client with a 120 s timeout. Proposed: a note in the docs and an
optional `?\Closure $options(array $req): array` or a documented requirement
on the wrapped client; at least expose why a 402 was not paid (over budget,
no scheme, screened) for the CLI to print, since the old callback printed the
price before refusing. Today the CLI can only say "not charged".

### G11. EVM helpers for the sweeper (Low)

`Sweeper` calls `Rpc::number(string $hex): \GMP` and `Rpc::word(string $hex): string`
(32-byte ABI word) as the old `Rpc` had them, and builds `balanceOf` /
`transfer` calldata by hand. Proposed: keep those two static helpers on
`Rpc`, and consider `Erc20::balanceOf(Rpc, token, owner)` /
`Erc20::sweepCalldata`. The sweeper's Arc-specific fee maths (native USDC has
18 decimals, token interface 6) is the module's.

### G12. PSR-7 only: the Symfony bridge costs (Low)

Drupal speaks `HttpFoundation`. The gate converts both ways with
`symfony/psr-http-message-bridge` and Guzzle's `HttpFactory` (one object
that is all the PSR-17 factories). That buffers the body and loses
`StreamedResponse`/`BinaryFileResponse` laziness. Acceptable for paid
JSON/markdown; say so in the README, and a `Sellers` core independent of
PSR-15 (verify + claim + settle as plain calls) would let other frameworks
skip the bridge. The module's `composer.json` now requires
`symfony/psr-http-message-bridge` and `guzzlehttp/psr7`; whether Drupal core
already ships them in every supported version is **unverified**.

### G13. Distribution (Blocker for release, not for the code)

The library is not on Packagist. `composer.json` uses a path repository
(`../quebracho-x402-mcp/packages/x402`, symlink, `quebracho/x402: @dev`),
which drupal.org's GitLab CI cannot resolve, and Packagist is where
`drupal/x402_pay` must find it. Also: `Scheme` is "experimental until 1.0"
and the module pins `@dev`; pick a version policy before tagging a module
beta. The `NOTICE` should be mirrored in the module (the code went both
ways: GPL then MIT).

## Smaller observations

- `X402Middleware` replies to a repeated payment with a 402
  `duplicate_settlement`; the module used a 409. Fine, but write it down: a
  buyer retrying after a timeout needs to know to ask `/status`-like
  endpoints or re-sign.
- `EvmExact::verify` caps `validBefore` at `maxTimeoutSeconds + 60` and
  rejects `< now + 6`. The module's `ChainFacilitator::MARGIN` (3 s) and its
  own `mismatch()` are gone; `PriceEvent::setTimeout()` still sets
  `maxTimeoutSeconds`, so a slow delivery just needs a longer timeout.
  Worth a line in the README next to `Networks::TIMEOUT`.
- `Networks::ALL` has Base Sepolia first, so "the first network" is no longer
  Arc testnet. The module's field default is now written down.
- Facilitators that return `payer` only on settle (not on verify) are
  handled; the middleware's `Scheme::payer()` is used for screening only.
- `Budget::MAX_TIMEOUT = 600` is a good floor for the buyer; the module's
  seller accepts any `setTimeout()` the site builder writes.

## Not verified (everything above is from reading)

Nothing ran: no `composer install`, no PHPUnit, no phpcs against a real
Drupal beyond the Drupal/DrupalPractice standard on the module's own files
and `php -l`. Whether the Symfony PSR bridge is available to a given Drupal
11.x; whether `GuzzleHttp\Psr7\HttpFactory` satisfies
`PsrHttpFactory`'s four factory arguments on the installed versions
(it implements all four); the `Facilitator`/`SolanaExact` signatures above
are proposals, not the library's.
