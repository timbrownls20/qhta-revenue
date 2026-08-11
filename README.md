# QHTA Revenue

Admin-only, **read-only** combined income report for qhta.com.au. One screen —
**QHTA Income** — showing membership income (Paid Memberships Pro) and store
income (WooCommerce) in a single filterable, exportable table, with the Stripe
fee and the net actually banked against each order.

## Why it exists

The two income streams live on two admin screens that never meet: PMPro's
**Memberships → Orders** and **WooCommerce → Orders**. There has been no way to
answer *"how much came in last month, across both?"* without exporting each
separately and reconciling by hand — and neither screen answers the follow-up
question, *"how much of that did we actually bank after Stripe's cut?"*

This merges the two streams into one normalised table, adds a **Member?** flag
so you can see which store buyers are also financial members, and totals
**gross − fees = net** for whatever is currently filtered.

## Scope

**Belongs here:** admin-only, read-only reporting that reads across the PMPro
and WooCommerce order streams.

**Does not belong here:**

| Instead | Goes to |
|---|---|
| Editing an order, refunding, changing a level | WooCommerce / PMPro core — never this plugin |
| PMPro invoice production, re-issue, delivery | `qhta-pmpro-invoice-extensions` |
| WooCommerce invoices | `qhta-woo-invoice` |
| The recordings purchase gate | `qhta-commerce` |
| PMPro / account front-end behaviour | `qhta-membership` |
| Site-wide styling, Astra hooks, mega menu | `qhta-theme-extras` |
| Conference domain logic | the conference program plugin |
| Member *pricing* | PMPro WooCommerce Integration add-on (no code) |

**Test:** *"Is it admin-only, read-only reporting that reads across both order
streams?"* Yes → here. **Anything that writes does not belong here**, and there
is no code path in this plugin that writes to any order, member, user, option or
transient. Not even a screen option, because registering one would write user
meta — the page size is a filter instead.

It legitimately depends on **both** PMPro and WooCommerce, the same justified
dual dependency `qhta-membership` has, because the reporting question itself
spans both.

There is **no settings screen**. Everything is either automatic or a filter.

## The rule that shapes the code

**The two streams must be read through different mechanisms and only merged in
PHP.** There is deliberately no SQL union.

- **Store income → `wc_get_orders()`**, never raw SQL against `wp_posts`. With
  High-Performance Order Storage enabled, WooCommerce orders are not posts, so a
  `wp_posts` query returns an empty set — not an error, an empty set — and the
  report would show no store income at all while looking like it had worked.
  `wc_get_orders()` reads whichever storage the site is on.
- **Membership income → `$wpdb` on `{prefix}pmpro_membership_orders`**, with
  prepared statements. PMPro orders are not WooCommerce orders and
  `wc_get_orders()` cannot see them.

Both are wrapped in `function_exists()` / table-exists guards, so a deactivated
dependency reports no income of that kind and says so in a notice — it never
fatals.

## The screen

**QHTA Income** in the admin menu (top-level, `dashicons-money-alt`).

Top-level rather than nested under WooCommerce because half the income here is
not WooCommerce's: filing it under one of the two systems it reconciles would
imply the other is secondary, and the menu would disappear along with
WooCommerce's the moment WooCommerce were deactivated — taking the membership
income with it.

### Columns

| Column | Membership row | Store row |
|---|---|---|
| Source | `Membership` | `Store` |
| Date | order timestamp, shown in site time | order created date |
| Reference | PMPro order code → PMPro order screen | Woo order number → Woo order screen |
| Customer | billing name / display name, with email beneath | billing name / display name, with email beneath |
| Item | membership level name | first product name `(+N)` |
| Gross | order total | `$order->get_total()` |
| Stripe fee | recorded fee, `0` for Pay by Check, else **Unknown** | `_stripe_fee`, `0` for non-Stripe, else **Unknown** |
| Net | gross − fee | `_stripe_net`, else gross − fee |
| Status | PMPro status, normalised | Woo status, normalised |
| Gateway | PMPro gateway | Woo payment method title |
| Member? (now) | always Yes + level | **resolved** — see below |

The raw source status is on the status cell as a tooltip.

### Status normalisation

Both vocabularies fold onto one small set, so "paid" means one thing across
sources and the totals can be trusted:

| Normalised | PMPro | WooCommerce |
|---|---|---|
| **Paid** | `success` | `completed`, `processing` |
| **Refunded** | `refunded` | `refunded` |
| **Pending** | `pending`, `review`, `token` | `pending`, `on-hold` |
| **Failed / Cancelled** | `error`, `cancelled` | `failed`, `cancelled` |

`processing` counts as Paid because on this site it means the money has been
taken and the goods are not yet delivered; the report is about income received,
not fulfilment. `on-hold` deliberately does not — that is Woo's "awaiting
payment". A status in neither list (one added by another plugin) keeps its own
label and shows under **All statuses** rather than being dropped.

### Member? — and the caveat

The useful new signal, and it earns its keep on **store** rows: which of the
people buying recordings are also financial members? Resolution order:

1. Order has a WP user ID → `pmpro_getMembershipLevelForUser()` → `Yes — <level>`
   or `No`.
2. Guest order → look the billing email up as a WP user, then as above.
   Membership requires an account, so an email with no user behind it is a
   definite `No`.
3. User ID that no longer resolves → `Unknown (deleted user)`. Their membership
   history went with the account; reporting them as "not a member" would assert
   something nobody knows.

> **This is *current* membership status, not status at the time of the order.**
> The column header says `Member? (now)` for that reason. A member who has since
> lapsed shows `No` against an order they placed while financial, and someone who
> joined last week shows `Yes` against a purchase from two years ago.

Current status is one cheap call per buyer and answers the question actually
being asked at the screen. At-time-of-order is reconstructable from PMPro's
history in `pmpro_memberships_users` (`startdate` / `enddate` / `modified`) but
is materially more work and ambiguous for anyone who lapsed and renewed — see
*Open items*.

Membership rows are always `Yes` with their level: a membership order *is* a
membership.

### Stripe fee and net — how "Unknown" works

The report shows the fee **the gateway recorded** and the net that follows from
it. It never computes a fee from a percentage: Stripe's actual charge varies
with card origin, currency and GST, so a formula would produce a number that
looks authoritative and is wrong.

There are three cases, and the difference between the last two matters:

| Case | Fee | Net |
|---|---|---|
| Stripe order with a recorded fee | the recorded figure | gross − fee (or the recorded net) |
| **Non-Stripe** — Pay by Check, bank transfer | **`0`**, a known zero | = gross. That money arrives whole. |
| Stripe order with nothing recorded | **Unknown** | Unknown |

**Unknown is never shown as `$0.00`, and never counted as zero.** A row with an
unknown fee contributes its gross to the gross total and contributes *nothing*
to the fee and net totals, and the totals bar says how many rows that was —
"net of known fees — 3 orders have no recorded fee". Treating unknown as zero
would silently add the whole gross to the net and overstate what was banked,
which is the one error this report exists to prevent. In the CSV those cells are
**blank**, not `0`, for the same reason: a blank sums to nothing.

Sources read:

- **Store:** order meta `_stripe_fee` / `_stripe_net` (current WooCommerce Stripe
  Gateway), falling back to the 3.x-era `Stripe Fee` / `Net Revenue From Stripe`.
  Read with `$order->get_meta()`, which is HPOS-safe. Filter:
  `qhta_revenue_woo_fee_meta_keys`.
- **Membership:** PMPro order meta, against a **candidate list of keys that has
  not yet been confirmed against a real order on this site** — see the next
  section. Until it is confirmed, Stripe membership orders read **Unknown**,
  which is honest. Filter: `qhta_revenue_pmpro_fee_meta_keys`.

### Confirming the PMPro fee source (do this once)

PMPro's order screen displays "Stripe Fee" and "Stripe Payout", so the figures
exist — but whether PMPro *stores* them as order meta or *fetches* them live
from the charge's Stripe balance transaction at render time has not been checked
against a real order. Guessing a meta key was explicitly not good enough, so the
plugin ships a diagnostic instead of a guess:

> Open **QHTA Income** with **`&qhta_revenue_diag=1`** on the end of the URL.

It lists every meta key actually stored against the five most recent Stripe
membership orders.

- **A fee / payout key is listed** → add it to the
  `qhta_revenue_pmpro_fee_meta_keys` filter (or to the default list in
  `includes/data-pmpro.php`) and membership fees stop reading Unknown.
- **Nothing is listed** → PMPro is fetching those figures live from Stripe, and
  the only route to them is the optional API backfill (see *Open items*).

The diagnostic is read-only and capability-gated like the rest of the screen.

### Filters

All combinable, all reflected in the totals and the export.

- **Period** — quick presets **This month** (default) · Last month · This
  financial year (AU, 1 Jul – 30 Jun) · All time, as links; plus **From** / **To**
  date fields for a custom range. Presets are links and the date fields submit a
  custom range, so "I chose a preset" and "I typed dates" can never contradict
  each other. All ranges resolve in **site timezone (Australia/Brisbane)**.
- **Source** — Both (default) / Membership only / Store only.
- **Status** — **Paid only (default)**, All statuses, or one normalised status.
  Paid-only is the default because the headline question is income actually
  received.
- **Members only** — store rows narrow to buyers who are members now.
- **Name or email** — free-text search over customer name and email.

A reversed date range is treated as a typo and swapped rather than returning
nothing; an invalid date is ignored rather than producing an empty report that
looks like "no income".

### Totals

Row count, **gross**, **Stripe fees** and **net banked**, split Membership /
Store with a grand total — always for the **whole filtered set**, not the visible
page. So "Paid, last month, Store only" gives the store's paid gross, fees and
net for last month.

### CSV export

Exports **exactly the current filtered and sorted set** — every matching row,
not just the page on screen. Unified schema for both sources:

```
source, date, ref, customer, email, item, amount, fee, net, status, gateway,
member, member_level, txn_ids
```

UTF-8 with a BOM so Excel opens Australian names cleanly; money as bare numbers
with a dot decimal and no symbol or thousands separator, so the columns arrive
as numbers a spreadsheet can sum; filename names the range
(`qhta-revenue_2026-07-01_to_2026-07-31.csv`). Streamed with `fputcsv` to
`php://output` rather than assembled in memory.

The export is the one request that leaves the page, so it is handled on
`admin_post` — before any output, the only place a download can legitimately
start — and is checked for **both capability and nonce** even though it still
only reads.

## What this is not

- **Not an accounting ledger.** It is an operational reconciliation view. It
  reports gross order totals as recorded and net of the fees the gateway
  recorded; it is not a source of truth for BAS or tax.
- **Not a Stripe payout reconciliation.** It shows the **per-order** fee and net.
  It does not reconcile against Stripe *payout batches*, fees on refunds,
  disputes or chargebacks, or the Stripe balance itself.
- **Net is net of fees, not net of refunds.** Refunds are not subtracted from the
  totals. Refunded orders appear under the Refunded status and can be filtered
  to, but a refunded order under "All statuses" still contributes its gross.
- **No charts.** A table, totals and CSV. No charting library, no dashboard
  widget.
- **No front end.** Nothing renders on the public site.

## Requirements

- WordPress 6.0+, PHP 7.4+.
- **Paid Memberships Pro** for membership income (reads
  `{prefix}pmpro_membership_orders`, `_membership_levels`,
  `_membership_ordermeta`; uses `pmpro_getMembershipLevelForUser()`).
- **WooCommerce** for store income (`wc_get_orders()`, `WC_Order`). HPOS is
  supported and irrelevant to the caller — nothing here reads `wp_posts`.

Either can be absent. With PMPro off, store rows still render and a notice says
membership income is missing; with WooCommerce off, vice versa; with both off,
the screen loads with an empty state and no error.

No external dependencies, no build step, no Composer.

## Capability

`manage_woocommerce` — shop managers as well as administrators — because this is
an operational shop-facing report rather than a site setting.

That capability is not part of WordPress; WooCommerce grants it to the
administrator and shop_manager roles on install. If it does not exist on the site
at all (WooCommerce never installed) the screen falls back to `manage_options`,
so a site with only membership income is not locked out of its own report. The
test is deliberately on the *role*, not the current user, so deactivating
WooCommerce does not silently widen access. Filter: `qhta_revenue_capability`.

## Install

Build the zip and upload it:

```sh
./scripts/build-zip.sh
```

Then **wp-admin → Plugins → Add New → Upload Plugin → Activate**. (WordPress on
Hostinger.) The build script refuses to run if the plugin header and
`QHTA_REVENUE_VERSION` disagree, and syntax-checks every PHP file first.

Activating adds the **QHTA Income** menu; deactivating removes it and leaves
nothing behind — the plugin stores no options, no tables and no user meta, so
there is nothing to clean up.

Admin-only, so there are no caching concerns: admin screens are not
page-cached, and no hPanel cache exclusion is needed.

## Filters

| Filter | Default | For |
|---|---|---|
| `qhta_revenue_capability` | `manage_woocommerce` | Restrict to `manage_options`, or widen |
| `qhta_revenue_per_page` | `50` | Rows per page |
| `qhta_revenue_status_map` | see above | Add a custom order status to a normalised bucket |
| `qhta_revenue_woo_fee_meta_keys` | `_stripe_fee` / `_stripe_net` (+ 3.x keys) | A different Stripe plugin's meta keys |
| `qhta_revenue_pmpro_fee_meta_keys` | unconfirmed candidates | **The one to set** once the diagnostic reveals the real key |
| `qhta_revenue_store_fee_net` | recorded meta | Where a Stripe-API backfill would hook in for store orders |
| `qhta_revenue_membership_fee_net` | recorded meta | Ditto for membership orders |
| `qhta_revenue_pmpro_timestamps_are_utc` | `true` | If a PMPro order's date here disagrees with PMPro's own screen, try this first |
| `qhta_revenue_rows` | merged rows | Last word on the row set |

## Notes and caveats

- **Currency.** Everything is assumed **AUD**, gross (including tax), as recorded
  on the order. Mixed currencies would need a per-currency split in the totals —
  out of scope; the totals would silently add them together today.
- **Timezone.** All ranges and displayed dates are in site time
  (Australia/Brisbane). PMPro stores its order timestamps in UTC and the report
  converts both ways; without that, orders in the ten hours after Brisbane
  midnight would land in the wrong day, and at a month boundary in the wrong
  month.
- **Performance.** v1 loads every in-range order from both sources and merges in
  PHP. That is the right trade at this site's scale (low hundreds of orders) —
  and correct pagination across two independent sources requires over-fetching
  anyway. If either stream reaches the thousands, narrow the default date range
  or paginate per source. The screen is not otherwise optimised and does not
  cache.
- **Test orders.** Nothing is hidden. The `$1.00 Individual [Test]` order and any
  `[deleted]` buyer appear like any other row — filters and status are how you
  exclude them, rather than a hardcoded rule that would eventually hide something
  real.

## Open items

1. **Member? current vs at-time-of-order.** v1 ships current status. Confirm that
   is acceptable, or promote the historic version.
2. **Confirm the PMPro fee source** — run `&qhta_revenue_diag=1` (above). Until
   this is done, Stripe membership orders show an Unknown fee.
3. **Stripe-API fee backfill.** For historic Stripe orders with no recorded fee,
   look the charge's balance transaction up via the Stripe API. Needs a read-only
   restricted secret key and the SDK or a REST call; one key can back both PMPro
   and Woo lookups. Off by default and not built — the two `*_fee_net` filters
   are where it would attach.
4. **Net of refunds.** Whether the headline "actual income" should also subtract
   refunds, as a further column beside net-of-fees.
5. **Monthly summary.** A totals-by-month mini-table above the list (still no
   charts).
6. **Menu placement and capability.** Top-level and `manage_woocommerce` as
   shipped — both are one filter away from anything else.
