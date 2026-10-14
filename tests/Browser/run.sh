#!/usr/bin/env bash
# ONE COMMAND, so the pages the browser loads are always the pages this checkout renders.
#
# Rendering and serving used to be two steps with two different directories, and nothing said so: a
# re-render wrote to one path while the server kept serving another, and the harnesses went green
# against the build before the fix. Everything below derives from $ROOT for that reason.
set -euo pipefail

ROOT="${STUDIO_ROOT:-/tmp/lynomia-studio-harness}"
PORT="${STUDIO_PORT:-8899}"
cd "$(dirname "$0")/../.."

rm -rf "$ROOT"; mkdir -p "$ROOT"
php tests/Browser/render-studio-pages.php "$ROOT"

# The stand-in layout loads the dashboard's OWN jQuery and Bootstrap from /js and /assets, because a
# harness that shipped its own copies would be testing a different page than the merchant gets.
ln -sfn "$PWD/public/js" "$ROOT/js"
ln -sfn "$PWD/public/assets" "$ROOT/assets"

php -S "127.0.0.1:${PORT}" -t "$ROOT" > "$ROOT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
until curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/builder-en.html"; do sleep 0.3; done

status=0
for s in "${@:-p4c-flow-builder p4d-channels p4e-commerce-automation p4f-templates-history}"; do
  for script in $s; do
    echo "===== $script ====="
    STUDIO_BASE="http://127.0.0.1:${PORT}" SHOT_DIR="$ROOT" node "tests/Browser/${script}.mjs" || status=1
  done
done
exit $status
