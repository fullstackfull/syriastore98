#!/usr/bin/env bash
# P1-SEC-40 / #120: refuse tracked database dumps in the repository root.
# Deleting the working-tree dump is only partial containment. This guard does NOT
# erase the leaked blob from history; incident #120 remains open until the hosted
# history is purged, exposure reviewed, and applicable secrets rotated.
set -euo pipefail
cd "${1:-.}"
fail=0
while IFS= read -r -d '' file; do
  [[ "$file" == */* ]] && continue
  case "${file,,}" in
    *.sql|*.sql.gz|*.dump|*.dump.gz|*.db|*.sqlite|*.sqlite3|*.bak)
      printf '::error title=Exposed DB artifact::Tracked root database artifact: %s\n' "$file" >&2
      fail=1 ;;
  esac
done < <(git ls-files -z)
if [[ "$fail" -ne 0 ]]; then
  echo 'Database dumps must be transferred privately, outside Git. Incident #120 remains open until history cleanup.' >&2
  exit 1
fi
echo 'OK: no database dump is tracked at the repository root (history is NOT checked).'
