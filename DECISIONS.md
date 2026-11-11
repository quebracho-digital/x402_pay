# Decisions

## TL;DR

- Why x402_pay does what it does, and what it chose not to do yet.
- A leak of what is sold goes before any release or feature.
- Every fix comes with a test shown to fail without it, and an audit.
- When unsure, charge or deny, never free; Drupal's access checks are never
  bypassed.
- What is not covered is written down: here, in the README, on the roadmap.

## How we decide

1. **Leaks first.** Anything that hands out for free what is sold, or shows
   what a visitor may not see, is fixed before the next release.
2. **Prove it.** A test reproduces the problem on the old code, then the
   fix makes it pass. A fix without a failing test first is not done.
3. **Audit.** A reviewer who did not write the change reads it. Critical
   and High are fixed before the commit; Medium are fixed or written down as
   a known limit; Low are judged one by one.
4. **The smallest fix at the root.** One guard where every path goes
   through, not one per route. Bigger work (a payment-aware cache) goes to
   the roadmap with its reason.
5. **Fail closed.** Unsure whether a route is for people or agents, it
   charges; unsure whether a visitor may see it, it denies.
6. **Written down.** Each decision below says what was rejected and when to
   revisit it. Scope and releases are the maintainer's call.

## 2026-11-11 (unreleased)

### A stop switch, twice: the library's flag file and the module's state

Decided by Leo (2026-11-11), translated from his Spanish: move the module to
the library's 0.5 "in all its capacity, including the global stop as a
service and a Drush command"; "it should be a double stop: first put the
library in stop mode, then the module, as a double protection?"; "create the
permissions it needs, such as the global stop one".

- **What:** `x402_pay.stop` (`Drupal\x402_pay\Stop`) holds two switches and
  is paused when either is: the library's `Quebracho\X402\Stop` (a flag
  file) and a value in Drupal's state. `engage()` sets the library's first,
  then the module's; `release()` clears both; each returns its own failure.
  `PaymentGate` asks the service before anything else and hands the
  library's switch to the library's sale, which asks it again before it
  settles. `drush x402:stop on|off|status`, a **Pause payments** tab, the
  permission *Pause and resume x402 payments*, and a status report line.
- **Why two:** they fail apart. The file needs no database and can be set
  with `touch`, but stops one host and cannot be set where another system
  user owns its directory. The state reaches every web head, but needs the
  database. A test (`StopSwitchTest`) shows the library stopping a sale with
  the module's check answering "running", so the second lock is not for show.
- **Fail closed:** a switch that cannot be read counts as engaged.
- **Logged and told:** each pause and resume writes a line to the `x402_pay`
  channel and dispatches `StopEvent`; a listener that throws is logged and
  does not undo the pause. Sales refused while paused are not logged one by
  one.
- **Its own permission**, not the settings': pausing is for whoever is on
  call, and must not come with the right to change `payTo` or the sweep.
- **The reason stays in the database.** The flag file holds a date only: it
  may sit under the public files directory.
- **Not paused:** free responses, the sweep and the reconciler. Stopping the
  sweep would leave money on hot wallets during an incident, which is when
  it should leave them.
- **Limits:** a payment already signed or a settlement already sent is not
  recalled. A flag file set by one system user cannot be cleared by another
  (the library refuses a file that is not its own): the command reports it,
  and the README says to run Drush as the web server's user. Several web
  heads share the flag only through `$settings['x402_pay_stop_file']`.
- **Rejected:** one switch in config (exported, deployed, and a config
  import would silently resume a paused site); the library's switch alone
  (one host, no database, but also no way to pause from a second web head).

### What of the library's 0.5 the module uses, and what it does not

- **Uses:** `Stop`; `Seller` and `Buyer` to build the sale (`PaymentGate`)
  and the paying client (`drush x402:buy`) by name; `Settlement` for the
  names it already read there. `quebracho/x402` is now `^0.5`.
- **Does not, and why:** `PriceList` and `Job\*` are for MCP tools, and the
  module prices entities through its price field and `PriceEvent`.
  `SensitiveRules` says what a payment ledger must not keep, and the module
  has a claims table, not a ledger that replays answers. `Facilitators::from`
  picks one facilitator from one setting; the module picks per network, with
  keys from the Key module and a lock around the gas wallet. `Screening`
  (the site's own allow and deny lists, a sanctions API) would be a new
  feature with its own settings: not built, written here so it is not
  mistaken for done.

## 2026-10-28 (unreleased)

### The protocol is a library; the module is Drupal

x402 itself (EIP-712, signatures, payment headers, networks, the
`exact` schemes, the 402 / verify / claim / settle-on-2xx middleware,
facilitator clients, the paying client) is `quebracho/x402`, MIT. The
module keeps what only Drupal has: the price and `payTo` of each request,
config entities and the settings form, permissions, the payment log and
events, the page cache rules, Key module secrets, the lock around our gas
wallet, the sweeper's cron.

- **Why:** one author wrote both and the library is a
  deliverable of Quebracho decision 0029; two copies of signing code is
  how they drift apart, and signing code is where a drift costs money.
- **Done as a test:** the module was refactored against the library's
  interface as it was, without patching the library. Every place the
  interface fell short (Solana, the facilitator clients, a few hooks in the
  middleware) was reported to the library, which closed them all in
  `quebracho/x402` 0.2. The report is in this repository's history.
- **Kept the licences apart:** the module is GPL-2.0-or-later only and
  depends on MIT code, which is allowed; the library copies nothing from
  here any more.
- **Rejected:** keeping our own `PaymentGate` flow beside the library's
  middleware "for now". It is the thing most worth sharing, and the
  tests that held it (`PaymentGateTest`) now belong to the library.

### A claim per payment, in the database

A payment buys one delivery. The library asks for a `claim(key): bool`
before it runs the handler; `ClaimStore` is a table with a unique key,
atomic across processes and hosts. It replaces the lock held while a
request ran, which only covered concurrent copies and relied on the chain
to refuse the repeat later.

- **Released** when nothing was delivered (the handler failed, the
  deadline passed) or nothing was broadcast (settling failed with no
  transaction), so a failed delivery does not burn the buyer's signature.
  **Kept** after a transaction was sent and did not confirm
  (`settlement_pending`): it may still land.
- **A repeated payment** now gets a 402 `duplicate_settlement`, not a 409.
- **Revisit** if the table grows: rows older than a day are pruned on cron.

### Mainnets are the module's choice

The library ships testnets and says whether a chain is a testnet is the
caller's business. `ModuleNetworks` adds Arc and Solana mainnet at load.
The field's default network is now written down (Arc testnet) instead of
being the first of a list that is no longer ours.

## 2026-10-25 (1.0.0-beta2)

### Only an HTML page is free for people

Any answer about a sold entity in another format (markdown, JSON, by a
route or `?_format=`) is charged, whatever `Accept` says.

- **Why:** `Accept` is the client's to set. A browser's `Accept` got a sold
  node free through JSON:API and Markdownify's routes, and the page cache
  then handed it to the next agent.
- **Rejected:** trusting `Accept` to tell people from agents.

### Nothing that shows what is sold is cached

Any page showing a priced entity is kept out of the page cache and sent
`Cache-Control: private`.

- **Why:** the page cache and CDNs key by URL. A cached sold page reaches
  an agent before it can be charged.
- **Rejected for now:** a cache that varies by payment (correct, but a
  project of its own). **Revisit** when sold pages see enough traffic to
  need caching.

### JSON:API omits what is sold beside its resource

Collections, includes and relationships omit a sold entity (listed in
`meta.omitted` by its URL and the reason). JSON:API
refuses to filter or sort by a field of a bundle that sells. A sold
entity's own resource is charged by the gate, as any route is.

- **Why omit:** these routes list entities that the route itself does not
  name, so the gate cannot charge them.
- **Why refuse filters:** a filter on an omitted entity's body still tells
  whether it matches, so its text leaks bit by bit.
- **Rejected:** blocking JSON:API collections whole, which breaks free
  content. Hiding omitted entities from `meta.omitted` is not possible in
  core.
- **Kept:** base fields (title, author, dates) stay filterable, since lists
  show them anyway. Users with *access x402 content free* read and filter
  everything.

### Paying grants nothing beyond view access

`Delivery::charge()` checks view access itself and answers 403 without it.

- **Why:** JSON:API's resource route checks none. The gate offered a
  draft, with its label in the 402, and delivered it once paid.

### Known limits, written down instead of fixed

| Limit | Why it is not fixed yet |
|---|---|
| A Views REST export lists sold entities free. | It is the site builder's own export, and has no per-row access. |
| A file's bytes are served by its URL. | Files are a separate mechanism (private files, `file_download`). |
| An old revision is judged by its own price. | It needs the permission to view revisions, which editors have. |
| A pending settlement that sent no transaction (`transaction ''`) is never closed. | It moved no money; the row is noise, not a loss. |
| The weekly check reads every settled sale of the last 8 days in one cron run. | A site with thousands of sales a week needs a queue (Queue API, not Batch API, which is for interactive runs). |
| `PaymentEvent` is not dispatched when the reconciler changes a row. | Subscribers see the sale as it ended at request time; the log has the later outcome. |
| The refused-payments limit counts settle failures that are not the buyer's fault. | Telling them apart needs the facilitator's reason codes to be stable. |

Revisit each one when a site needs it.

### The reconciler asks the chain about the signature, not the facilitator (2026-11-08)

Three reviewers read the reconciliation (2026-11-02) and a fourth audited the
fixes (2026-11-08). The first version of the fix still believed the payer and
the transaction hash the facilitator reported; the audit showed a lying
facilitator could pass it. What the code does now, each with a test that
failed first:

- **The log names whoever signed.** `payer` and `nonce` come from the signed
  authorization (the library has checked the signature), not from the
  facilitator's answer. The hash is stored in lower case, so the same
  transaction in another case counts as one.
- **A sale is paid when its own authorization was spent in the transaction it
  cites.** The weekly check wants the token's `AuthorizationUsed` event for
  that payer and nonce, plus a Transfer of the amount to payTo, both from the
  network's token, and no other sale citing the transaction. A Transfer alone
  proves nothing: any contract can emit one. Rows from before the `nonce`
  column (no nonce) keep the older check: token, payer, amount, payTo.
- **A failed row stays open while its authorization can be spent.** Anyone
  holding the signed payload can submit it first; then the site's own
  transaction reverts and the buyer did pay. Rows that may have moved money
  (`settlement_pending`, a facilitator that timed out, `transaction_reverted`,
  dropped) are asked about on every cron for 8 days; if the token says the
  authorization is spent they become `authorization_used`, for a person to
  check. Not called settled: cancelling an authorization spends it too. A
  node that does not answer is not a verdict: the row waits.
- **A settle that may have moved money is logged and stays claimed**
  (`Settlement::MAY_HAVE_MOVED`), with the nonce, in the upfront flow too. The
  buyer signs anew instead of retrying.
- **The log never turns a paid sale into a 500:** a database failure writes
  the sale's details to the log channel instead.
- **A garbage answer from a node, or one failing cron step, does not stop the
  rest** (the OFAC refresh runs first and apart).
- **The sanctions list is read back after every download** and removed if it
  does not parse (the library fails closed on a corrupt list, which would
  refuse every sale for a day). The status report shows its age and warns
  after a week.
- **`drush x402:reconcile` exits 1** when the chain disagrees, a node did not
  answer, or rows already wait for a person (a refund owed, an authorization
  spent elsewhere).
- **Rejected:** marking the row settled when the authorization is spent.

A row is not judged reverted or dropped before the authorization's
`validBefore` has passed (stored in the log): until then anyone holding the
signed payload can still submit it. The sanctions list is written readable by
every user (0644, directory 0755): cron and PHP-FPM may run as different users.

Known gaps: the sanctions list is only as fresh as the cron that downloads it
(the status report says how old it is); the library logs the payer and payTo
of every sale at info level (an `x402 intent` line), which is personal data of
a pseudonymous kind.

### Markdownify is not a dependency

Markdownify and x402_pay convert with the same library, and both can be
enabled. Markdownify is a `require-dev` so that CI asks a sold node through
each of its routes (`MarkdownifyTest`).

- **Rejected:** depending on it, which would add a module for what x402_pay
  already does.

## Before 2026-10-25

Earlier decisions are in the README (what it does and why) and in git
history. New ones go here.

### The old PHP API stays, deprecated, as a wrapper of the library

Decided by Leo (2026-11-01), translated from his Spanish: could the old
x402_pay APIs stay as deprecated ones that only wrap the PHP library?
The classes the refactor removed (`Signer`, `Networks`, `PaymentHeaders`,
`Eip712`, `Solana`, `Buyer`, `CircleFacilitator`, ...) come back in
`Drupal\x402_pay` marked `@deprecated`, delegating to `quebracho/x402`, so
code written against 1.0.0-beta2 (Ombú) keeps working.

How (2026-11-01): the classes that are the library's own under another name
are `class_alias`es; `Networks` keeps its 1.0.0-beta2 `ALL` (a constant
cannot ask the library, and Ombú reads `ALL[$network]['explorer']`); `Buyer`
and the three facilitators keep their calls and delegate to `EvmExact`,
`SolanaExact`, `HttpFacilitator` and the `x402_pay.facilitators` service.
`Solana::pay()` is not kept: nothing calls it but `Buyer`, which is kept.
The deprecation reads "in x402_pay:1.0.0-beta3, removed from
x402_pay:2.0.0", chosen by Claude Code as the next release and the next
major; Leo did not pick the numbers.

Decided by Leo (2026-11-01 14:50), answering whether the public `1.0.x`
branch on drupal.org moves to the refactor: yes, go ahead. Ombú's production
installs `1.0.x-dev` from drupal.org, so the refactor reaches it only through
that branch. `1.0.x` fast-forwards to `refactor/use-quebracho-x402`; code
written against 1.0.0-beta2 keeps working through the deprecated wrappers.

Decided by Leo (2026-11-02), translated from his Spanish, asked whether to
remove the deprecated classes now: no, open the issue first. They stay
until 2.0.0; Ombú still imports them, so Ombú moves to the library's
classes first. The issue is
https://www.drupal.org/project/x402_pay/issues/3634901 and 1.0.0-beta3
carries it.
