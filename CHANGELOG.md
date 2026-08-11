# Changelog

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
