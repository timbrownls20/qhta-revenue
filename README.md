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
streams?"* Yes → here. **Anything that changes a record does not belong here**,
and no code path in this plugin creates or modifies an order, member, user or
setting. Not even a screen option, because registering one would write user meta
against the viewer — the page size is a filter instead.

The one thing it writes, since 1.1.0, is the **Stripe fee cache** (transients).
A settled charge's fee is immutable, so re-fetching it on every page load would
be slow and pointless. That is a cache, not data: deleting every one of those
transients loses nothing but speed, and no business record is touched either
way.

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
| Reference | PMPro order code → PMPro order screen, plus a link out to Stripe | Woo order number → Woo order screen, plus a link out to Stripe |
| Customer | billing name / display name, with email beneath | billing name / display name, with email beneath |
| Item | membership level name | first product name `(+N)` |
| Gross | order total | order total, less any **partial** refund (flagged, original on hover) |
| Stripe fee | looked up from Stripe (PMPro records none), `0` for Pay by Check | `_stripe_fee`, else looked up from Stripe, `0` for non-Stripe |
| Net | gross − fee | `_stripe_net`, else gross − fee |
| Status | PMPro status, normalised | Woo status, normalised |
| Gateway | PMPro gateway | Woo payment method title |
| Member? (now) | always Yes + level | **resolved** — see below |

The raw source status is on the status cell as a tooltip.

### The link out to Stripe

Under the order reference, both kinds of row carry a link straight to the object
in the Stripe dashboard — the thing you actually need when a figure on this
screen has to be reconciled against a payout or a customer query.

The link is built from the transaction id each system already records, so it is
the same mechanism on both sides rather than two integrations:

| Stored id | Goes to |
|---|---|
| `ch_…`, `py_…`, `pi_…` | `/payments/…` |
| `in_…` | `/invoices/…` |
| `sub_…` | `/subscriptions/…` |
| `cus_…` | `/customers/…` |

A membership row often carries two — the payment and the subscription that
renewed it — and both are offered, because they are different pages answering
different questions.

Two things follow from building the link off the id rather than off the gateway:

- **An id that is not a Stripe object gets no link.** A Pay by Check membership
  order stores a synthetic id like `CHECKFCC8287EE3`; a manual order may store
  nothing. Those rows show no second line rather than a link to a 404.
- **Test and live are kept apart.** Membership links follow
  `pmpro_gateway_environment` — the same switch `qhta_revenue_stripe_key()` picks
  the key with — and store links follow the WooCommerce Stripe gateway's own
  `testmode` setting, so a shop left in test while memberships run live does not
  send you to the wrong dashboard.

The URL names the object and lets Stripe resolve it against whichever account
you are signed into, which is right for this site — memberships and the shop
settle into one account. Signed into a different Stripe account you will get
"no such payment"; `qhta_revenue_stripe_dashboard_url` is where to prepend an
`acct_…` segment if that ever matters here.

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

The report shows the fee **the gateway recorded, or the one Stripe itself
reports**, and the net that follows from it. It never computes a fee from a
percentage: Stripe's actual charge varies with card origin, currency and GST, so
a formula would produce a number that looks authoritative and is wrong.

There are four cases, and the difference between the last two matters most:

| Case | Fee | Net |
|---|---|---|
| Stripe order with a recorded fee | the recorded figure | gross − fee (or the recorded net) |
| Stripe order with nothing recorded | **looked up from Stripe** (see below) | gross − fee |
| **Non-Stripe** — Pay by Check, bank transfer | **`0`**, a known zero | = gross. That money arrives whole. |
| Stripe order the lookup cannot resolve | **Unknown** | Unknown |

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
- **Membership:** **Stripe itself.** See below — PMPro stores no fee at all.

### Why membership fees come from the Stripe API

**PMPro does not record the Stripe processing fee.** Not under a meta key that
was hard to find — it does not record it anywhere: there is no
balance-transaction lookup in PMPro's own gateway code, and nothing fee-related
on its orders screen. The 1.0.0 release shipped a candidate list of meta keys
and a diagnostic to confirm them; the answer came back that there is nothing to
confirm. So for membership orders the Stripe API is not the convenient route to
the fee, it is **the only one**.

WooCommerce is different — its Stripe gateway does store `_stripe_fee` — so
store rows only reach the API when that meta is missing (historic orders, or one
taken by a different gateway plugin).

**What it reads.** The charge's **balance transaction**, which is Stripe's own
record of what it deducted: `fee` is the all-in deduction, `fee_details` breaks
it into components.

**The credential.** It reuses the Stripe key PMPro already holds — nothing new
to create, store or rotate. The options are read directly because
`PMProGateway_stripe::get_secretkey()` has been **private** since PMPro 3.0; the
resolution below is that method's own logic against the same public options:

| Setup | Key read from |
|---|---|
| Manual API keys (`using_api_keys()`) | `pmpro_stripe_secretkey` |
| Stripe Connect, live | `pmpro_live_stripe_connect_secretkey` |
| Stripe Connect, sandbox | `pmpro_sandbox_stripe_connect_secretkey` |

The plugin only ever issues `GET` requests with it, and the key never leaves the
server. Because the site runs both Stripe connections against one Stripe
account, PMPro's key looks up a WooCommerce charge as readily as a membership
one. If you would rather use a Stripe **restricted** key scoped to read Charges,
PaymentIntents and Balance transactions, return it from the
`qhta_revenue_stripe_key` filter — nothing else changes. Returning `''` from
that filter switches the lookup off entirely.

No SDK is used. The call goes through `wp_remote_get()` against
`api.stripe.com`, deliberately: both PMPro and WooCommerce bundle their own copy
of the Stripe PHP SDK, and loading a third would risk a version clash in the
same process.

**Which ids can be looked up.** The stored transaction id decides the route —
`ch_`/`py_` → the charge, `pi_` → the PaymentIntent's latest charge, `in_` → the
invoice's charge. An id of another shape (a `sub_` subscription, a PayPal
reference) has no single charge behind it and is left Unknown.

**Caching and pacing.** Each resolved fee is cached for 30 days, each failed
lookup for an hour (so an outage or a revoked key cannot cause a retry storm on
every page load). Live lookups are capped at **25 per page load** so a wide date
range cannot time out; the footer says how many are still queued and offers to
fetch the next batch. Cached rows do not count against the cap.

**Currency.** A balance transaction is denominated in the *settlement* currency.
If that is not the shop's currency, the fee cannot be subtracted from the
order's gross without a conversion this report does not do, so it stays
**Unknown** rather than becoming a number that looks right.

### PMPro's own 2% — worth knowing

PMPro's **free** Stripe Connect integration charges an extra **2% application
fee** on top of Stripe's processing fee, paid to Stranger Studios out of the same
payout. `PMProGateway_stripe::get_application_fee_percentage()` returns 0 only if
the site is on manual API keys or holds a premium PMPro licence — and the
countries where it is disabled are BR, IN, MX and MY, so **Australia is not
exempt**.

A stored meta key would never have revealed this. The balance transaction does,
as a separate `application_fee` line, so:

- the **fee cell** shows the split on hover (`Stripe $2.94 + PMPro platform fee
  $2.20`), marked with a dotted underline;
- the **totals** call out the platform component separately whenever it is
  non-zero, because unlike a card network's cut it is removable — a premium
  licence sets it to zero.

If that line shows a meaningful number, the licence may pay for itself.

### The order-meta diagnostic

Still available at **`&qhta_revenue_diag=1`** on the report URL: it lists every
meta key stored against the five most recent Stripe membership orders. It no
longer has a fee to find, but it is the quickest way to see what PMPro *is*
storing against an order. Read-only and capability-gated like the rest of the
screen.

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
- **Refunds are handled two different ways, on purpose.** A **partial** refund is
  deducted from that order's gross (and so from the net), because WooCommerce
  leaves such an order on `completed` and it would otherwise read as fully paid;
  the cell is flagged and shows the original total on hover. A **full** refund is
  not deducted — its status already says Refunded, the Paid-only default filters
  it out, and zeroing it would make the Refunded view a column of `$0.00`. Under
  "All statuses" a fully refunded order still contributes its gross.
- **Refunds are reported against the order's date, not the refund's.** A July
  order refunded in August still counts in July. Reporting refunds on the date
  they happened would make them a third data stream with rows of their own — a
  bigger change than this report is scoped for.
- **Stripe keeps its fee on a refund.** So a refunded order is a real loss of the
  fee, which this report does not show anywhere.
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

No external dependencies, no build step, no Composer. The Stripe fee lookup
uses `wp_remote_get()` against `api.stripe.com` — the site needs outbound HTTPS,
which it already does for both gateways.

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
| `qhta_revenue_pmpro_fee_meta_keys` | a few candidates | Vestigial — PMPro stores no fee. Left for an add-on that does |
| `qhta_revenue_stripe_key` | PMPro's key | Supply a restricted read-only key, or `''` to switch the lookup off |
| `qhta_revenue_stripe_cache_ttl` | 30 days | How long a resolved fee is cached |
| `qhta_revenue_stripe_miss_ttl` | 1 hour | How long a failed lookup is remembered |
| `qhta_revenue_stripe_lookup_budget` | `25` | Live Stripe lookups allowed per page load |
| `qhta_revenue_stripe_dashboard_objects` | charge/PI/invoice/subscription/customer | Which id prefixes get a dashboard link, and where they point |
| `qhta_revenue_stripe_dashboard_url` | resolved URL, or `null` | Rewrite a row's Stripe link — account-scoped URLs — or return `null` to suppress it |
| `qhta_revenue_store_fee_net` | meta, then Stripe | Last word on a store order's fee/net |
| `qhta_revenue_membership_fee_net` | Stripe | Last word on a membership order's fee/net |
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

1. **Member? current vs at-time-of-order.** Ships as current status. Confirm that
   is acceptable, or promote the historic version.
2. **The PMPro platform fee.** If the 2% line in the totals is material, a
   premium PMPro licence removes it — worth pricing against the annual figure.
   Nothing to build; a commercial decision the report now makes visible.
3. **Restricted Stripe key.** The lookup currently reuses PMPro's key. If you
   would rather it held read-only credentials of its own, create a Stripe
   restricted key (Charges, PaymentIntents, Balance transactions: read) and
   return it from `qhta_revenue_stripe_key`.
4. **Refunds on their own date.** Partial refunds are now deducted from the order
   they belong to (1.1.1), but every refund is still counted against the *order's*
   date rather than the refund's. Reporting them on the date the money went back
   means refunds become rows in their own right — say so if that is the view you
   actually reconcile against.
5. **Monthly summary.** A totals-by-month mini-table above the list (still no
   charts).
6. **Menu placement and capability.** Top-level and `manage_woocommerce` as
   shipped — both are one filter away from anything else.
