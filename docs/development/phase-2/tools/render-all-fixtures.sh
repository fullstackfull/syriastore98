#!/usr/bin/env bash
# Renders EVERY preview fixture the two browser-check suites need, into $1.
#
# WHY THIS EXISTS. Both suites are driven from a directory of pre-rendered HTML that is NOT
# committed, and neither suite's docblock lists the full set -- PHASE-02-TEST-EVIDENCE.md named
# `nav2-*` where the code reads `nav3-*`, and said nothing about the `orig-*` baselines at all.
# Reconstructing it by reading failures cost most of an afternoon, twice. A missing fixture does
# not report as missing: it reports as a FAILING CHECK or a harness TypeError, which reads like a
# regression in the thing being tested. This script is the answer to that.
#
# THE `orig-*` BASELINES COME FROM GIT. They are the PRE-REDESIGN pilot screen, whose markup
# (`.tab-pane.active .list-group-item`) no longer exists in the working tree -- the checks compare
# the redesign against it, so rendering the current view as "orig" silently compares it with
# itself.
#
# usage: bash docs/development/phase-2/tools/render-all-fixtures.sh <outDir>
set -euo pipefail

OUT="${1:?usage: render-all-fixtures.sh <outDir>}"
cd "$(dirname "$0")/../../../.."
mkdir -p "$OUT"

R=docs/design-system/tools/render-preview.php
P2=docs/development/phase-2/tools/previews
PILOT=resources/views/attribute_option/index.blade.php

# The last commit in which the pilot screen still carried its ORIGINAL markup.
ORIG_COMMIT=c54b4442
ORIG_TMP="$(mktemp -d)"
git show "${ORIG_COMMIT}:${PILOT}" > "${ORIG_TMP}/index.blade.php"

# ---- design-system suite (browser-checks.cjs) ----
php $R "$PILOT" en full   "$OUT/new-en-full.html"       light
php $R "$PILOT" ar full   "$OUT/new-ar-full.html"       light
php $R "$PILOT" en empty  "$OUT/new-en-empty.html"      light
php $R "$PILOT" ar empty  "$OUT/new-ar-empty.html"      light
php $R "$PILOT" en noperm "$OUT/new-en-noperm.html"     light
php $R "$PILOT" en full   "$OUT/new-en-full-dark.html"  dark
php $R "$PILOT" ar full   "$OUT/new-ar-full-dark.html"  dark
php $R "$PILOT" en empty  "$OUT/new-en-empty-dark.html" dark
php $R "${ORIG_TMP}/index.blade.php" en full "$OUT/orig-en-full.html"      light
php $R "${ORIG_TMP}/index.blade.php" en full "$OUT/orig-en-full-dark.html" dark

# ---- Phase 02 suite (browser-checks-p02.cjs) ----
for loc in en ar; do
  for st in full empty noperm; do
    for th in light dark; do
      php $R "$P2/dashboard-after.blade.php" "$loc" "$st" "$OUT/after-$loc-$st-$th.html" "$th"
    done
  done
done

php $R "$P2/components.blade.php" en full   "$OUT/comp-en-light.html"  light
php $R "$P2/components.blade.php" ar full   "$OUT/comp-ar-light.html"  light
php $R "$P2/components.blade.php" en noperm "$OUT/comp-en-errors.html" light

# nav2-* is the LEGIBILITY pass (section 9), nav3-* the ZONES pass (section 10 and 14).
for loc in en ar; do
  for th in light dark; do
    php $R "$P2/sidebar-nav.blade.php" "$loc" full   "$OUT/nav2-$loc-$th-full.html"   "$th"
    php $R "$P2/sidebar-nav.blade.php" "$loc" full   "$OUT/nav3-$loc-$th-full.html"   "$th"
    php $R "$P2/sidebar-nav.blade.php" "$loc" bgoff  "$OUT/nav2-$loc-$th-bgoff.html"  "$th"
    php $R "$P2/sidebar-nav.blade.php" "$loc" noperm "$OUT/nav2-$loc-$th-noperm.html" "$th"
  done
done

rm -rf "$ORIG_TMP"
echo
echo "rendered $(ls "$OUT" | wc -l) fixtures into $OUT"
echo "now:  SS_PREVIEW_DIR=$OUT php -S 127.0.0.1:8099 -t public docs/design-system/tools/preview-server.php &"
echo "      node docs/design-system/tools/browser-checks.cjs"
echo "      node docs/development/phase-2/tools/browser-checks-p02.cjs"
