#!/usr/bin/env bash
#
# Produce the deploy zip for wp-admin -> Plugins -> Add New -> Upload Plugin.
#
# Reads the version out of the plugin header rather than taking it as an
# argument, and refuses to build unless the header and QHTA_REVENUE_VERSION
# agree. Same guard as qhta-commerce, qhta-membership and qhta-theme-extras: a
# mismatch means the two sources of truth for "what version is live" have
# drifted.
#
# Usage: ./scripts/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="qhta-revenue"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
BOOTSTRAP="$PLUGIN_DIR/$PLUGIN_SLUG.php"

# Version: 1.0.0  ->  1.0.0
header_version="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p" "$BOOTSTRAP")"

# define( 'QHTA_REVENUE_VERSION', '1.0.0' );  ->  1.0.0
const_version="$(sed -n "s/^define([[:space:]]*'QHTA_REVENUE_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$BOOTSTRAP")"

if [[ -z "$header_version" || -z "$const_version" ]]; then
	echo "error: could not read the version from $BOOTSTRAP" >&2
	echo "       header='$header_version' constant='$const_version'" >&2
	exit 1
fi

if [[ "$header_version" != "$const_version" ]]; then
	echo "error: version mismatch — bump both before deploying." >&2
	echo "       plugin header:         $header_version" >&2
	echo "       QHTA_REVENUE_VERSION:  $const_version" >&2
	exit 1
fi

VERSION="$header_version"
ZIP_PATH="$PLUGIN_DIR/$PLUGIN_SLUG-$VERSION.zip"

# Syntax-check every PHP file so a typo cannot reach the live site. This plugin
# only loads in wp-admin, so a parse error would take the dashboard down rather
# than the public site — still not something to find out on qhta.com.au.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null
	done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -print0)
else
	echo "note: php not on PATH, skipping syntax check" >&2
fi

rm -f "$ZIP_PATH"

# WordPress needs the plugin folder as the top level inside the archive, so zip
# has to run from the parent. The output lands in the plugin root, which is
# inside the tree being zipped — build to a temp file and move it in afterwards
# so the archive cannot swallow itself.
staging_dir="$(mktemp -d)"
trap 'rm -rf "$staging_dir"' EXIT
staging_zip="$staging_dir/$PLUGIN_SLUG-$VERSION.zip"

# Excludes: editor cruft, git metadata, local Claude settings (permission
# allowlist, not for the web server), previous builds, the handover notes, and
# this build tooling.
cd "$PARENT_DIR"
zip -rq "$staging_zip" "$PLUGIN_SLUG" \
	-x "*.DS_Store" \
	   "*.git*" \
	   "*.claude*" \
	   "*.zip" \
	   "qhta-revenue/HEALTHCHECK.md" \
	   "$PLUGIN_SLUG/scripts/*" \
	   "$PLUGIN_SLUG/$PLUGIN_SLUG-handover.md"

mv "$staging_zip" "$ZIP_PATH"

echo "built $ZIP_PATH ($VERSION)"
unzip -l "$ZIP_PATH"

cat <<EOF

Next:
  1. wp-admin -> Plugins -> Add New -> Upload Plugin -> replace -> activate
  2. The screen appears as "QHTA Income" in the admin menu. There is no
     front-end footprint and nothing to configure.
  3. Smoke test, in this order:
     a. QHTA Income loads    -> table shows BOTH Membership and Store rows
     b. totals bar           -> Gross - Fees = Net, split Membership / Store
     c. a store row's Member? -> agrees with that buyer's PMPro level
     d. a bank-transfer row  -> fee \$0.00, net = gross (NOT "Unknown")
     e. a Stripe store row   -> a real fee, not \$0.00 and not Unknown
     f. change the period    -> totals change with it, not just the table
     g. Members only         -> store rows drop to member buyers
     h. Export CSV           -> opens in Excel with names intact, and the row
                                count matches the totals bar (not the page)
     i. paste the export URL -> without the nonce it must refuse
     j. deactivate PMPro     -> store rows still render, warning notice shown
     k. deactivate Woo       -> membership rows still render, ditto
  4. Then settle the open question the report cannot answer for itself:
     open QHTA Income with &qhta_revenue_diag=1 and read the fee-source
     diagnostic. If a Stripe fee/payout meta key is listed, add it to the
     qhta_revenue_pmpro_fee_meta_keys filter and membership fees stop reading
     Unknown. If nothing is listed, PMPro is fetching those figures live from
     Stripe and only the (optional, off by default) API backfill can get them.
EOF
