#!/usr/bin/env python3
"""
LEAD MAP FOR P1-SEC-76 -- AUTHENTICATED MODULE ROUTES THAT LOOK A ROW UP BY ID WITH NO TENANT MARKER IN THE HANDLER.   READ-ONLY.
==============================================================================================================================

WHY. `tests/Feature/Security/RouteCensusGateTest` classifies only the module routes that carry NO authentication middleware. A route behind
`auth` and a permission therefore looked finished, and P1-SEC-68 (Helpdesk) showed it was not: seven such handlers looked a ticket up by its
identifier alone. This script lists the authenticated module routes that have the same SHAPE, so that each can be read and executed.

WHAT IT IS NOT. It is a HEURISTIC and says so in every line it prints: **a lead is not a finding**. It reads the handler's method body as text.
It flags a body that contains an id lookup (`::find(`, `::findOrFail(`, `->find(`, `where('id'`, `::destroy(`, `::whereKey(`, `firstOrFail(`)
and NO tenant marker (`created_by`, `creatorId(`, `getCurrentStore(`, `store_id`, `current_store`, `workspace`, `Auth::user()->id`, `->user_id`,
`super admin`, ...). So it will list a handler that looks up PLATFORM data on purpose, and it will MISS a handler whose body mentions a tenant column
without applying it to that lookup, a handler whose lookup lives in a trait, a parent class or a helper (counted as UNRESOLVED below), and every
handler reached through a route with no parameter. Another host guard may already cover a route it lists (the manifest's `store-row:` and
`owner:` rules, ModuleResourceOwnershipGuard, ModuleOrderMutationGuard): check `App\\Support\\ModuleRouteManifest` before reading a handler.

PRECONDITIONS (stated, not assumed).
  * Run from the repository root with Python 3 and the vendored modules checked out (packages/workdo/*). No PHP, no network, no database.
  * It needs the route census, which is PHP's:
        php tests/Support/Security/bin/route-census.php > /tmp/census.json
    (every module loaded through the production loader on an in-memory SQLite database; it prints JSON on stdout).

USAGE
    python3 tests/Support/Security/bin/map-module-id-lookups.py /tmp/census.json          # a readable report
    python3 tests/Support/Security/bin/map-module-id-lookups.py /tmp/census.json --json   # the same, as JSON, for a diff between two runs

It writes nothing. Exit status is 0 whatever it finds: this is a map, not a gate.
"""
import collections
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]

LOOKUP = re.compile(
    r"(::find\(|::findOrFail\(|->find\(|->findOrFail\(|where\(\s*['\"]id['\"]|::destroy\(|::whereKey\(|firstOrFail\("
    r"|DB::table\([^)]*\)->where\(\s*['\"]id['\"])"
)
TENANT = re.compile(
    r"(created_by|creatorId\(|getCurrentStore\(|store_id|current_store|workspace|Auth::user\(\)->id|auth\(\)->user\(\)->id|\$user->id"
    r"|->user_id|super admin|resolveStore|ownedStore|sameTenant|where\(\s*['\"]user_id)"
)
PERMISSION = re.compile(r"isAbleTo\(")
FACADES = {
    'DB', 'Auth', 'Storage', 'File', 'Validator', 'Session', 'Cache', 'Log', 'Utility', 'Str', 'Hash', 'Crypt', 'Route', 'Schema', 'Mail',
    'Http', 'Artisan', 'Carbon', 'Arr', 'Redirect', 'URL', 'Request', 'App', 'Config', 'Event',
}
TENANT_COLUMNS = ('store_id', 'created_by', 'user_id', 'workspace', 'business_id')


def handler_file(fqcn):
    parts = fqcn.split('\\')

    if parts[0] != 'Workdo' or len(parts) < 3:
        return None

    base = ROOT / 'packages/workdo' / parts[1] / 'src'
    rest = parts[2:]

    for candidate in (base.joinpath(*rest), base.joinpath(rest[0].lower(), *rest[1:]), base.joinpath(rest[0].capitalize(), *rest[1:])):
        path = candidate.with_suffix('.php')

        if path.exists():
            return path

    return None


def method_body(source, name):
    match = re.search(r'function\s+' + re.escape(name) + r'\s*\(', source)

    if not match:
        return None

    start = source.find('{', match.end())

    if start < 0:
        return None

    depth = 0

    for index in range(start, len(source)):
        if source[index] == '{':
            depth += 1
        elif source[index] == '}':
            depth -= 1

            if depth == 0:
                return source[start:index + 1]

    return None


def model_facts(module, name):
    for path in sorted((ROOT / 'packages/workdo' / module / 'src').rglob(name + '.php')):
        text = path.read_text(errors='ignore')
        table = re.search(r"\$table\s*=\s*['\"]([a-z_0-9]+)['\"]", text)
        fillable = re.search(r"\$fillable\s*=\s*\[(.*?)\]", text, re.S)
        columns = re.findall(r"['\"]([a-z_]+)['\"]", fillable.group(1)) if fillable else []

        return {'table': table.group(1) if table else None, 'tenant_columns': [c for c in TENANT_COLUMNS if c in columns]}

    return None


def main(argv):
    paths = [a for a in argv if not a.startswith('--')]

    if len(paths) != 1:
        print(__doc__)
        return 2

    census = json.loads(Path(paths[0]).read_text())
    routes = [r for r in census['routes'] if r['module'] and r['authenticated'] and re.search(r'\{[^}]+\}', r['uri'])]
    mutating = [r for r in routes if r['method'] in ('POST', 'PUT', 'PATCH', 'DELETE')]
    unresolved = 0
    leads = []
    cache = {}

    for route in sorted(routes, key=lambda r: (r['module'], r['uri'], r['method'], r['action'])):
        if '@' not in route['action']:
            unresolved += 1
            continue

        fqcn, method = route['action'].split('@', 1)
        path = handler_file(fqcn)
        body = method_body(cache.setdefault(path, path.read_text(errors='ignore')), method) if path else None

        if body is None:
            unresolved += 1
            continue

        if LOOKUP.search(body) and not TENANT.search(body):
            models = sorted({m for m in re.findall(r"\b([A-Z][A-Za-z0-9_]+)::(?:find|findOrFail|where|destroy|whereKey)\(", body) if m not in FACADES})
            leads.append({
                'module': route['module'],
                'method': route['method'],
                'uri': route['uri'],
                'handler': fqcn.split('\\')[-1] + '@' + method,
                'permission_checked': bool(PERMISSION.search(body)),
                'models': {m: model_facts(route['module'], m) for m in models},
            })

    summary = {
        'authenticated_module_routes_with_a_parameter': len(routes),
        'of_which_mutating': len(mutating),
        'handlers_unresolved': unresolved,
        'leads': len(leads),
    }

    if '--json' in argv:
        print(json.dumps({'summary': summary, 'leads': leads}, indent=1, sort_keys=True))
        return 0

    print(f"authenticated module routes with a parameter: {summary['authenticated_module_routes_with_a_parameter']} (mutating: {summary['of_which_mutating']})")
    print(f"handlers whose body could not be read (a trait, a parent, a helper): {unresolved}")
    print(f"LEADS -- an id lookup and no tenant marker in the handler's body: {len(leads)}   (a lead is not a finding; see the header)")

    by_module = collections.defaultdict(list)

    for lead in leads:
        by_module[lead['module']].append(lead)

    for module, items in sorted(by_module.items(), key=lambda kv: (-len(kv[1]), kv[0])):
        print(f"\n== {module} ({len(items)})")

        for lead in items:
            facts = '; '.join(
                f"{name}({(info or {}).get('table') or '?'}: {','.join((info or {}).get('tenant_columns') or []) or 'no tenant column in $fillable'})"
                for name, info in lead['models'].items()
            ) or 'no model named'
            print(f"   {lead['method']:7} {lead['uri']}  {lead['handler']}  [{'permission' if lead['permission_checked'] else 'NO permission'}]  {facts}")

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
