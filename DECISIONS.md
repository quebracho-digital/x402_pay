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

Revisit each one when a site needs it.

### Markdownify is not a dependency

Markdownify and x402_pay convert with the same library, and both can be
enabled. Markdownify is a `require-dev` so that CI asks a sold node through
each of its routes (`MarkdownifyTest`).

- **Rejected:** depending on it, which would add a module for what x402_pay
  already does.

## Before 2026-10-25

Earlier decisions are in the README (what it does and why) and in git
history. New ones go here.
