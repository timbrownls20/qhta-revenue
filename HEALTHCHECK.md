# Healthcheck note — keep qhta-revenue's canaries current

**Standing rule.** When this plugin is created or changed in a way that adds or alters an external
dependency (a PMPro table or column read by name, a WooCommerce read path, a capability, a gateway option, or a currency assumption), update its **qhta-healthcheck** canaries in the *same* change.

The canaries live **in this repository**, in `includes/healthcheck.php`, registered on qhta-healthcheck's
`qhta_healthcheck_checks` filter. That is the whole point: changing the dependency and changing the
canary for it are the same diff, in the same review, deployed together. There is no central copy to
keep in step — `qhta-healthcheck/includes/checks.php` deliberately holds none.

A new dependency with no canary is the silent-failure risk qhta-healthcheck exists to catch.

## How it behaves

- Nothing runs unless **qhta-healthcheck** is installed. `add_filter()` on a hook nobody applies
  costs nothing, so this file is inert without it.
- The assertion helpers (`qhta_healthcheck_assert_*`) belong to qhta-healthcheck and are only ever
  called from inside its runner, which wraps every check in a try/catch. An older qhta-healthcheck
  missing one shows up as a failed check, never a fatal.
- Until this plugin is deployed at **1.2.0** or later, qhta-healthcheck reports it amber —
  *"no canaries defined"*. That is expected during the rollout, not a bug: each plugin joins on its
  own next deploy, no coordinated release needed.

## This plugin's canaries (6)

| Canary | Sev | Watches |
|---|---|---|
| PMPro orders table and columns | critical | `wp_pmpro_membership_orders` + the 8 columns read by name |
| `wc_get_orders()` available | critical | the HPOS-safe read path for store income |
| Report capability exists | warning | administrator still holds `manage_woocommerce` |
| Stripe secret key resolvable | warning | `qhta_revenue_stripe_key()` returns something |
| Stripe API accepts the key | warning, **remote** | one `GET /v1/balance` a day — a present key is not a working key |
| PMPro and WooCommerce agree on currency | warning | the combined total is not adding unlike units |

This plugin's failure mode is unusually quiet even by QHTA standards: it does not error when a
dependency moves, it produces a **wrong number**. A renamed PMPro column yields an empty membership
column; a missing Stripe key yields a page of Unknown fees and an overstated net. Both still look
like a report. The currency canary is the one nothing else would ever surface.

## More
- Full rule, rationale and the per-plugin canary list: `qhta-healthcheck/qhta-healthcheck-handover.md`.
- qhta-healthcheck covers internal correctness only; site-up liveness is the external "QHTA site guardian" HTTP task. Keep both.
