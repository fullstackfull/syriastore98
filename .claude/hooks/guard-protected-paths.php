#!/usr/bin/env php
<?php

/**
 * PreToolUse hook — refuse edits to vendored WorkDo modules, and to secret-bearing files.
 *
 * WHY THIS EXISTS, AND WHAT IT IS NOT.
 *
 * `.github/scripts/guard-no-workdo-changes.sh` is the authority and stays the authority: it runs
 * in CI, on the real diff, and cannot be bypassed from a developer's machine. This hook is a
 * convenience layer in front of it. Its whole value is TIMING -- it refuses the edit at the moment
 * it is attempted, instead of letting the work be done, committed and pushed and then rejected by
 * CI, by which point the fix has been written in the wrong place and has to be written again.
 *
 * It is deliberately NOT a security boundary. Anything that edits files without going through a
 * Claude Code tool call never reaches this script, and the hook can be switched off by editing
 * .claude/settings.json. A local hook that could not be disabled would be a different and worse
 * thing. CI is what makes the rule true; this just makes it cheap to obey.
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 1 — packages/workdo/ is vendored commercial code.
 *
 * An in-place edit is silently reverted by the next upstream module update and leaves no patch
 * record of what changed or why. A fix that appears to need a module file almost always belongs in
 * the host application: a Policy, a FormRequest, a Gate, a query scope, or route middleware. If it
 * genuinely cannot, forking the module is an owner decision, not something to slip into a PR.
 *
 * FIRST-PARTY MODULES ARE EXEMPT, BY EXACT NAME ONLY. The directory belongs to the loader, not the
 * vendor -- App\Providers\PackageServiceProvider discovers addons by scanning
 * packages/workdo/<Name>, so a module this project WROTE has nowhere else to live. LynomiaChat is
 * such a module: new here, no upstream counterpart, so there is no upstream update to revert it.
 *
 * The allowlist is exact names, not a pattern, so a newly vendored module cannot fall into it by
 * accident and adding one is a visible edit to this file. It is kept byte-identical in meaning to
 * the CI script's FIRST_PARTY_MODULES array, and
 * tests/Unit/Ci/ClaudeConfigurationTest asserts the two lists agree -- a local guard that is more
 * permissive than CI would hand back exactly the false confidence this is meant to remove.
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 2 — secret-bearing files are not readable or writable through a tool call.
 *
 * The project configuration requires that no hook or subagent gets unrestricted access to secrets.
 * An instruction in a prompt is not a control, so this is enforced here, for EVERY tool including
 * every subagent: the `tenant-authz-reviewer` is read-only by its own frontmatter, but a Read of
 * `.env` would still have been within its rights.
 *
 * This matters specifically in this repository: APP_KEY is in git history and unrotated, and
 * `storage/logs/api-*.log` is known to have contained plaintext passwords, OTPs and bearer tokens
 * (redaction was added for future requests only). Neither is ever needed to judge code.
 *
 * ---------------------------------------------------------------------------------------------
 * CONTRACT, against the documented PreToolUse behaviour:
 *
 *   exit 2            BLOCKS the call, and stderr is given to the model as the reason. This is
 *                     the mechanism this hook relies on, and it blocks even if stdout says allow.
 *   exit 0            allows; stdout is parsed as JSON if it is a single object. The
 *                     `hookSpecificOutput.permissionDecision` form is also printed on a refusal,
 *                     so the refusal is expressed in both shapes the documentation describes.
 *   any other code    does NOT block -- the call proceeds. That is the one trap here: a PHP fatal
 *                     error exits 255 and would fail OPEN silently.
 *
 * Failing open is the right default for THIS guard and the wrong one in general. It is right here
 * only because CI is the authority: `.github/scripts/guard-no-workdo-changes.sh` runs on the real
 * diff and cannot be bypassed locally, so a hook that silently stopped working costs a round trip
 * through CI rather than letting a bad change through. tests/Unit/Ci/ClaudeConfigurationTest
 * executes this script against real payloads so a fatal error cannot go unnoticed.
 *
 * `permissions.deny` in .claude/settings.json is the declarative layer beside this one: deny rules
 * apply to every tool and every subagent regardless of workspace trust, and cover Read, Grep,
 * Glob, Edit and Write. What they do NOT cover is a shell command -- `cat .env` through Bash is
 * invisible to them. That gap is precisely what the Bash branch at the bottom of this file is for,
 * and why both layers exist.
 */

// ---------------------------------------------------------------------------------------------

/** First-party modules under packages/workdo/. Exact directory names. Mirrors the CI script. */
const FIRST_PARTY_MODULES = [
    'LynomiaChat',
];

/** Paths that may not be read or written through a tool call, as regex over a repo-relative path. */
const SECRET_PATTERNS = [
    // .env and its variants, but NOT .env.example -- that file is tracked, holds no values, and is
    // how you learn which configuration keys exist. CLAUDE.md points people at it, so blocking it
    // would make this guard contradict the project's own instructions.
    '#(^|/)\.env$#',
    '#(^|/)\.env\.(?!example$)[^/]+$#',
    // storage/logs/api-*.log is the measured risk: it held plaintext passwords, OTPs and bearer
    // tokens, and redaction was added for FUTURE requests only. laravel.log is left readable --
    // over-blocking is how a guard gets switched off.
    '#(^|/)storage/logs/api[^/]*\.log$#',
    '#(^|/)(id_rsa|id_ed25519)(\.pub)?$#',
    '#\.(pem|p12|pfx|key|keystore|jks)$#',
    '#(^|/)auth\.json$#',                 // composer credentials
    '#(^|/)\.npmrc$#',
];

// ---------------------------------------------------------------------------------------------

$raw = stream_get_contents(STDIN);

if ($raw === false || trim($raw) === '') {
    exit(0);
}

$payload = json_decode($raw, true);

if (! is_array($payload)) {
    // Unparseable input is not a reason to block work. CI remains the authority.
    exit(0);
}

$tool = (string) ($payload['tool_name'] ?? $payload['toolName'] ?? '');
$input = $payload['tool_input'] ?? $payload['toolInput'] ?? [];
$input = is_array($input) ? $input : [];

/**
 * Every path this tool call would touch. Covers the field names the file-editing tools use, and
 * recurses, so a MultiEdit's `edits[].file_path` and any future nesting are both caught.
 */
function collectPaths(array $input): array
{
    $keys = ['file_path', 'filePath', 'path', 'notebook_path', 'notebookPath'];
    $found = [];

    array_walk_recursive($input, function ($value, $key) use (&$found, $keys) {
        if (is_string($value) && in_array($key, $keys, true)) {
            $found[] = $value;
        }
    });

    // array_walk_recursive skips non-leaf values, so top-level string fields are re-checked.
    foreach ($keys as $key) {
        if (isset($input[$key]) && is_string($input[$key])) {
            $found[] = $input[$key];
        }
    }

    return array_values(array_unique($found));
}

/** Repo-relative, slash-normalised, with any `./` and absolute prefix removed. */
function normalise(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    if (str_starts_with($path, $root . '/')) {
        $path = substr($path, strlen($root) + 1);
    }

    return ltrim(preg_replace('#^\./#', '', $path), '/');
}

function isProtectedWorkdo(string $relative): bool
{
    if (! preg_match('#^packages/workdo/([^/]+)/#', $relative, $m)) {
        return false;
    }

    return ! in_array($m[1], FIRST_PARTY_MODULES, true);
}

function isSecret(string $relative): bool
{
    foreach (SECRET_PATTERNS as $pattern) {
        if (preg_match($pattern, $relative)) {
            return true;
        }
    }

    return false;
}

function refuse(string $reason): void
{
    // The documented stdout form. The deprecated top-level `decision`/`reason` pair is
    // deliberately NOT emitted: it maps to this same decision and is retired for PreToolUse.
    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'deny',
            'permissionDecisionReason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES), "\n";

    // Exit 2 is what actually blocks, and stderr is what the model is shown.
    fwrite(STDERR, $reason . "\n");

    exit(2);
}

// ---------------------------------------------------------------------------------------------
// File-editing and file-reading tools: judge each path.
// ---------------------------------------------------------------------------------------------

$writingTools = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit', 'Update'];
$readingTools = ['Read', 'NotebookRead'];

if (in_array($tool, $writingTools, true) || in_array($tool, $readingTools, true)) {
    $isWrite = in_array($tool, $writingTools, true);

    foreach (collectPaths($input) as $path) {
        $relative = normalise($path);

        if (isSecret($relative)) {
            refuse(sprintf(
                "REFUSED: %s is secret-bearing and is not reachable through a tool call.\n\n"
                . "  tool: %s\n  path: %s\n\n"
                . "APP_KEY is in this repository's git history and unrotated, and "
                . "storage/logs/api-*.log is known to have held plaintext passwords, OTPs and "
                . "bearer tokens.\n"
                . "Nothing in these files is needed to read or judge code. If a configuration "
                . "VALUE matters, read the key's name from config/ or .env.example and ask the "
                . "owner for the value.",
                $relative,
                $tool,
                $path
            ));
        }

        if ($isWrite && isProtectedWorkdo($relative)) {
            refuse(sprintf(
                "REFUSED: packages/workdo/ is vendored third-party code.\n\n"
                . "  tool: %s\n  path: %s\n\n"
                . "An in-place edit is silently reverted by the next upstream module update and "
                . "leaves no patch record.\n\n"
                . "Put the fix in the host application instead -- a Policy, a FormRequest, a "
                . "Gate, a query scope, or route middleware. Refusing the route is usually the "
                . "answer when the module's own code is wrong.\n\n"
                . "If it genuinely cannot live outside the module, forking it is an owner "
                . "decision, not part of this change.\n\n"
                . "First-party modules are exempt by exact name: %s.\n"
                . ".github/scripts/guard-no-workdo-changes.sh enforces the same rule in CI and is "
                . "the authority.",
                $tool,
                $path,
                implode(', ', FIRST_PARTY_MODULES)
            ));
        }
    }

    exit(0);
}

// ---------------------------------------------------------------------------------------------
// Bash: only clear MUTATIONS of a protected path, and any read of a secret file.
//
// Deliberately narrow. A hook that blocked `grep -r packages/workdo/` would be wrong and would
// get switched off within a day, and then the WorkDo rule would have no local layer at all. So
// this matches a mutating command applied to a protected path, not any mention of one.
// ---------------------------------------------------------------------------------------------

if ($tool === 'Bash') {
    $command = (string) ($input['command'] ?? '');

    if ($command === '') {
        exit(0);
    }

    // Any attempt to reach a secret file through the shell, however it is spelled. This is the
    // half `permissions.deny` cannot see.
    foreach (['.env', 'storage/logs/api', 'auth.json', '.npmrc', 'id_rsa', 'id_ed25519'] as $needle) {
        if (str_contains($command, $needle)) {
            // `.env.example` is tracked, documented and safe; it holds no values. Allow a command
            // that mentions ONLY .env.example.
            if ($needle === '.env'
                && preg_match('#\.env\.example#', $command)
                && ! preg_match('#\.env(?!\.example)(?![\w.-])#', $command)) {
                continue;
            }

            refuse(sprintf(
                "REFUSED: this command references %s, which is secret-bearing.\n\n"
                . "  command: %s\n\n"
                . "APP_KEY is in git history and unrotated, and storage/logs/api-*.log has held "
                . "plaintext passwords, OTPs and bearer tokens. Read the key NAMES from config/ "
                . "or .env.example instead; ask the owner for any value.",
                $needle,
                $command
            ));
        }
    }

    // A mutating command aimed at packages/workdo/.
    $mutators = '(sed\s+-i|perl\s+-[pi]|tee|rm|mv|cp|truncate|chmod|chown|patch|git\s+apply|git\s+checkout\s+--|install\s)';

    if (preg_match('#packages/workdo/([^/\s\'"]+)#', $command, $module)
        && ! in_array($module[1], FIRST_PARTY_MODULES, true)
        && (preg_match('#' . $mutators . '#i', $command)
            || preg_match('#>\s*\.?/?packages/workdo/#', $command)
            || preg_match('#>>\s*\.?/?packages/workdo/#', $command))) {
        refuse(sprintf(
            "REFUSED: this command would modify packages/workdo/%s, which is vendored "
            . "third-party code.\n\n  command: %s\n\n"
            . "Put the fix in the host application instead. First-party modules are exempt by "
            . "exact name: %s.\n"
            . ".github/scripts/guard-no-workdo-changes.sh enforces this in CI and is the "
            . "authority.",
            $module[1],
            $command,
            implode(', ', FIRST_PARTY_MODULES)
        ));
    }
}

exit(0);
