# `.claude/` — project configuration

Committed, so it applies to anyone working in this repository. Personal configuration does not
belong here: it goes in `~/.claude/` (skills, personal agents) or `.claude/settings.local.json`,
which is gitignored.

| | |
|---|---|
| `settings.json` | permission deny rules and the PreToolUse hook wiring |
| `hooks/guard-protected-paths.php` | refuses edits to vendored WorkDo modules, and tool access to secret-bearing files |
| `agents/tenant-authz-reviewer.md` | read-only reviewer for authorization and tenancy changes |
| `commands/tenant-check.md` | `/tenant-check` — runs the existing tenancy and authorization suites |

`../CLAUDE.md` holds the project rules themselves.

## Why the two protection layers are split the way they are

They protect different things because they **can express** different things.

**`permissions.deny` handles secrets.** Deny rules apply to every tool and every subagent
regardless of workspace trust, and cover `Read`, `Grep`, `Glob`, `Edit` and `Write` in one
declaration. That is the right shape for "this file is never readable".

What they cannot see is a **shell command**: `cat .env` through `Bash` is invisible to a `Read`
rule. That gap is why the hook also has a Bash branch.

**The hook handles `packages/workdo/`.** It has to, because the rule has an **exception**:
`LynomiaChat` is first-party and must stay editable. A deny rule cannot express
"deny this tree except one subdirectory" — deny beats allow in every scope, so there is no way to
carve the exemption back out. A script can, so the WorkDo rule lives there and there is
deliberately **no** blanket `Edit(./packages/workdo/**)` deny rule.

## What these are not

**CI is the authority.** `.github/scripts/guard-no-workdo-changes.sh` runs on the real diff and
cannot be bypassed from a developer's machine. The hook's whole value is *timing* — it refuses at
the moment of the edit, instead of letting the work be written in the wrong place, committed,
pushed, and only then rejected.

A local hook is not a security boundary. It can be switched off by editing `settings.json`, and
anything that writes files without going through a Claude Code tool call never reaches it. A hook
that could not be disabled would be a worse thing than this one.

Note two documented behaviours that matter here:

- **Only exit code 2 blocks.** Any other non-zero exit lets the call proceed, so a fatal error in
  the hook fails **open**. `tests/Unit/Ci/ClaudeConfigurationTest` executes the script against real
  payloads so that cannot happen unnoticed.
- **Hooks are not sandboxed and permission rules do not restrict them.** The hook process itself
  can read anything the user can. It restricts what *tool calls* may touch, which is the layer the
  model acts through.
