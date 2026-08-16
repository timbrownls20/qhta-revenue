# Changelog

## 1.3.0 — 16 August 2026

### Added
- **A link out to Stripe on every row that has one**, under the order reference, for membership and
  store rows alike. The report could already tell you what Stripe deducted; it could not take you to
  the charge, so reconciling a figure against a payout meant copying a transaction id out of the CSV
  and pasting it into the dashboard's search.

  Built from the transaction id both systems already record, so it is one mechanism rather than two
  integrations: the id's prefix decides the destination (`ch_`/`py_`/`pi_` → payments, `in_` →
  invoices, `sub_` → subscriptions, `cus_` → customers). A membership row that carries both a payment
  and a subscription id offers both — they are different pages answering different questions.

  Two consequences of keying off the id rather than the gateway, both deliberate. An id that is not a
  Stripe object — a Pay by Check order's synthetic `CHECK…`, a manually entered order's blank — gets
  no link at all rather than one that lands on a 404. And test and live are resolved separately per
  source, membership from `pmpro_gateway_environment` and store from the WooCommerce Stripe gateway's
  own `testmode`, so a shop left in test while memberships run live does not send you to the wrong
  dashboard.

  New filters `qhta_revenue_stripe_dashboard_objects` and `qhta_revenue_stripe_dashboard_url`; the
  latter is where to prepend an `acct_…` segment if these links ever need to name the account
  explicitly.

## 1.2.0 — 12 August 2026

### Added
- **Healthcheck canaries**, in `includes/healthcheck.php` — six checks registered on
  `qhta-healthcheck`'s `qhta_healthcheck_checks` filter: the PMPro orders table *and the eight columns
  read by name*, `wc_get_orders()`, the `manage_woocommerce` capability the screen falls back from,
  the Stripe secret key resolving out of PMPro's options, a daily `GET /v1/balance` proving the key
  still works, and whether PMPro and WooCommerce agree on currency.

  This plugin's failure mode is quieter than most: it does not error when a dependency moves, it
  produces a **wrong number**. A renamed PMPro column yields an empty membership column rather than an
  exception; a rotated Stripe key yields a page of Unknown fees and an overstated net. Both still look
  like a report, which is why the canaries are about the integrity of the figures rather than about
  features appearing.

  The currency check is the one nothing else would ever surface — the report adds membership and store
  amounts into one total, and if the two systems are configured in different currencies that total is
  arithmetic on unlike units with nothing anywhere saying so.

  Nothing runs unless `qhta-healthcheck` is installed.

## 1.1.1 — 11 August 2026

### Fixed
- **A partially refunded store order no longer counts as fully paid.**
  WooCommerce leaves a partial refund on `completed` and `get_total()` returns
  the pre-refund figure, so such an order appeared in the Paid totals at its full
  gross with nothing on the row to say money had gone back. The refund is now
  deducted from that row's gross and net, the cell is flagged with the original
  total on hover, and the totals footer states how many orders and how much.

  | Refund | Before | Now |
  |---|---|---|
  | PMPro (always full — `refunds->create` with no amount) | status `refunded`, excluded from Paid | unchanged |
  | Woo, full | status `refunded`, excluded from Paid | unchanged |
  | Woo, **partial** | **counted at full gross under Paid** | deducted from gross and net, row flagged |

  A **full** refund is deliberately still not deducted: its status already
  carries the fact, the paid-only default filters it out anyway, and zeroing the
  amount would turn the Refunded view into a column of `$0.00` that says nothing
  about what was reversed.

- A non-Stripe order's net is now derived from the row's amount rather than
  re-read from `get_total()`, so a partial refund reaches the net on bank
  transfers too. With a zero fee the two were otherwise identical, which is why
  it went unnoticed.

### Notes
- **Stripe keeps its fee on a refund**, so a refunded order is a genuine loss of
  that fee. The report does not show that anywhere — noted in the README rather
  than guessed at.
- Refunds are still counted against the **order's** date, not the refund's. A
  July order refunded in August remains July income here. Changing that makes
  refunds a third data stream with rows of their own; see the open items.

## 1.1.0 — 11 August 2026

Membership fees now come from Stripe. In 1.0.0 every membership order read
**Unknown**, which was honest but useless — the totals showed `$1,340.00` gross
and `$0.00` net of known fees across 11 orders.

### The finding behind this release
- **PMPro does not record the Stripe processing fee anywhere.** 1.0.0 shipped a
  candidate list of order-meta keys and a diagnostic to confirm which was right;
  the answer turned out to be that there is nothing to confirm. PMPro's own
  gateway code contains no balance-transaction lookup and its orders screen shows
  no fee — every `balance_transaction` reference in the plugin is inside its
  bundled Stripe SDK. So the API is not the convenient route to a membership fee,
  it is the only one. The meta-key path is left in place (it costs nothing and an
  add-on may yet populate it) but is no longer expected to fire.

- **PMPro's free Stripe Connect integration takes an extra 2%**, paid to Stranger
  Studios out of the same payout as Stripe's fee.
  `PMProGateway_stripe::get_application_fee_percentage()` returns 0 only on
  manual API keys or a premium licence, and the countries where it is disabled
  are BR/IN/MX/MY — Australia is not exempt. No stored meta key could ever have
  surfaced this; the balance transaction itemises it, so now it is visible.

### Added
- **Stripe fee backfill** (`includes/stripe-fees.php`). For any Stripe order with
  no recorded fee, the charge's **balance transaction** is fetched and its `fee`
  used. Applies to both sources — for membership it is the only path, for store
  orders only when `_stripe_fee` is missing.

- **Reuses PMPro's existing Stripe credentials**, so there is no second key to
  create, store or rotate. `PMProGateway_stripe::get_secretkey()` has been
  private since PMPro 3.0, so the same public options are read directly with that
  method's own logic (manual key, or the live/sandbox Connect key per
  `pmpro_gateway_environment`). Only `GET` requests are ever issued with it. The
  `qhta_revenue_stripe_key` filter swaps in a restricted read-only key, or `''`
  to switch the lookup off.

- **Fee breakdown.** Where Stripe itemises the deduction, the fee cell shows the
  split on hover (`Stripe $2.94 + PMPro platform fee $2.20`) and the totals call
  the platform component out separately — because unlike a card network's cut, it
  is removable by buying a licence.

- **Transaction-id routing.** `ch_`/`py_` → the charge, `pi_` → the
  PaymentIntent's latest charge, `in_` → the invoice's charge. Anything else (a
  `sub_` subscription, a PayPal reference) has no single charge behind it and
  stays Unknown rather than being forced through a wrong endpoint.

- **A footer that says why a fee is still unknown**, distinguishing the three
  cases that need three different responses: no usable Stripe key, lookups
  deferred by the per-request cap (with a link to fetch the next batch), or an
  order whose fee genuinely cannot be retrieved.

### Changed
- **The "writes nothing" claim is now stated precisely rather than absolutely.**
  Fee lookups are cached in transients, so the plugin does write. The meaningful
  half of the guarantee is unchanged and restated in the plugin header and README:
  no order, member, user or setting is ever created or modified. A cache is not
  data — deleting every one of those transients loses nothing but speed.

- Both `*_fee_net` filters now carry a third element, the fee breakdown.
  `qhta_revenue_normalise_fee_net()` pads a two-element return, so a filter
  written against 1.0.0 keeps working.

### Decisions worth knowing
- **No Stripe SDK.** The call goes through `wp_remote_get()`. PMPro and
  WooCommerce each bundle their own copy of the Stripe PHP SDK, and loading a
  third into the same process invites a version clash — for four fields off one
  endpoint, a plain HTTP request is both smaller and safer.

- **A failed lookup is cached for an hour.** Without that, a Stripe outage or a
  revoked key would have every page load retry every uncached order, and the
  screen would hang for as long as the problem lasted.

- **Live lookups are capped at 25 per page load.** A wide date range full of
  uncached orders would otherwise become hundreds of sequential HTTP requests and
  a timeout. Cached rows do not count against the cap, so the cost is one-off and
  the footer says how many are still queued.

- **A charge that settled in another currency stays Unknown.** Its fee is in the
  settlement currency and cannot be subtracted from the order's gross without a
  conversion this report does not do. Unknown beats a number that looks right.

- **Every failure path returns Unknown, never zero.** No key, an unroutable id, a
  network error, a currency mismatch — all of them leave the fee unresolved and
  the row out of the net total, which is the invariant the whole report rests on.

## 1.0.0 — 11 August 2026

First release. A single admin screen, **QHTA Income**, that answers a question
neither of the two order screens on this site could: *how much came in, across
both membership and store, and how much of it did we actually bank?*

### Added
- **One combined income table.** PMPro membership orders and WooCommerce store
  orders, normalised to a shared row shape and sorted by date descending, with
  sortable columns and pagination via `WP_List_Table`. Each reference links back
  to the order on its own system's screen.

- **A "Member?" flag on store rows** — the signal that did not exist anywhere
  before. Resolved from the order's user ID, or by matching the billing email to
  an account for a guest order, or reported as `Unknown (deleted user)` when the
  buyer's account is gone. It reflects **current** membership status, which the
  column header says out loud (`Member? (now)`), because a reader who assumed
  otherwise would misread every lapsed member's old order.

- **Stripe fee and net banked, per order and in the totals.** Read from what the
  gateway recorded — `_stripe_fee` / `_stripe_net` order meta on the Woo side,
  PMPro order meta on the membership side — never computed from a percentage,
  because Stripe's actual charge varies with card origin, currency and GST and a
  formula would be authoritative-looking and wrong.

- **A three-way distinction the report depends on:** a Stripe order with a
  recorded fee shows it; a **non-Stripe** order (Pay by Check, bank transfer)
  shows a **known zero**, because that money arrives whole; a Stripe order with
  nothing recorded shows **Unknown**. Unknown is never rendered as `$0.00` and
  never counted as zero — such a row contributes its gross to the gross total and
  nothing to the fee or net totals, and the totals bar says how many rows that
  was. In the CSV those cells are blank rather than `0`, so they sum to nothing.
  Silently treating unknown as zero would add the whole gross to the net and
  overstate what was banked, which is the one error this report exists to prevent.

- **Filters, all combinable, all reflected in the totals and the export:** period
  (This month by default, plus Last month / This AU financial year / All time as
  quick links, plus a custom From–To range), source, status (Paid only by
  default), members only, and free-text search over name and email.

- **A totals bar** showing count, gross, fees and net for the whole filtered set
  — not the visible page — split Membership / Store with a grand total.

- **CSV export** of exactly the current filtered and sorted set, in one unified
  schema for both sources, UTF-8 with a BOM so Excel opens Australian names
  cleanly, streamed with `fputcsv`. Handled on `admin_post` (before any output,
  the only place a download can legitimately start) and checked for both
  capability and nonce.

- **A fee-source diagnostic** at `&qhta_revenue_diag=1`, listing every meta key
  stored against recent Stripe membership orders. It exists because the one thing
  that could not be settled without a real order on this site — whether PMPro
  stores the Stripe fee or fetches it live — was not worth guessing at. See the
  README; this is the first thing to run after installing.

### Decisions worth knowing
- **The two streams are read through different mechanisms and merged only in
  PHP. There is no SQL union.** WooCommerce is read via `wc_get_orders()` and
  nothing anywhere in this plugin touches `wp_posts`: under High-Performance
  Order Storage the orders are not posts, so a `wp_posts` query returns an empty
  set — not an error, an empty set — and the report would show no store income
  while looking like it had worked. PMPro is read via `$wpdb` on its own tables
  with prepared statements, because `wc_get_orders()` cannot see PMPro orders at
  all.

- **PMPro is queried with `SELECT o.*` rather than a named column list.** Its
  order schema has gained and renamed columns across 2.x and 3.x, and a named
  list would fatal or return nulls beside the wrong version. Selecting everything
  and reading each field defensively makes a schema difference cost a blank cell
  instead of an error.

- **Nothing here writes.** No order, member, user, option or transient is created
  or changed by any code path, including the export. That is also why no screen
  option is registered for the page size — doing so would write user meta, and
  "reads only" is worth more than a configurable page count. The page size is a
  filter.

- **Date presets are links, not a dropdown.** A preset `<select>` sitting beside
  two date inputs is always ambiguous — choose a preset *and* type dates and one
  of them has to be silently discarded. Presets navigate; the date fields submit
  a custom range; the two cannot contradict each other.

- **PMPro order timestamps are treated as UTC** and converted both ways, so a
  Brisbane-morning order does not fall in the previous day — or, at a month
  boundary, the previous month. Behind the
  `qhta_revenue_pmpro_timestamps_are_utc` filter, since it is an assumption about
  someone else's storage.

- **The capability test is on the administrator *role*, not the current user.**
  `manage_woocommerce` is WooCommerce's, not WordPress's, so a site without
  WooCommerce would lock everyone out of a report that still has membership
  income to show; it falls back to `manage_options` in that case. Testing the
  current user instead would have silently widened access to any admin the moment
  WooCommerce were deactivated.

- **A missing dependency degrades rather than fatals, and says so.** With PMPro
  off the store rows still render; with WooCommerce off the membership rows still
  do; a notice names what is missing, because silently halving the income is
  worse than an error.

### Known limits
- Net is net of **fees**, not of **refunds** — refunded orders show under the
  Refunded status but their gross is not subtracted.
- Not a Stripe payout reconciliation: per-order fees only, no payout batches,
  dispute or chargeback fees, or balance.
- All figures assumed AUD; mixed currencies would be added together.
- Loads all in-range orders from both sources and merges in PHP — correct at this
  site's scale, revisit if either stream reaches the thousands.
- Membership Stripe fees read **Unknown** until the fee source is confirmed with
  the diagnostic and the real meta key supplied.
