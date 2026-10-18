<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The committed Claude Code project configuration under `.claude/`, held to the same standard as
 * anything else here: it is EXECUTED, not merely inspected.
 *
 * WHY THAT MATTERS FOR THIS PARTICULAR ARTEFACT. A PreToolUse hook blocks a tool call only by
 * exiting with code **2**. Every other non-zero exit — including the 255 of a PHP fatal error —
 * lets the call proceed. So a hook with a syntax error, a bad regex, or a renamed field does not
 * announce itself: it silently stops protecting anything and nothing looks different. The tests
 * below pipe real payloads through the real script and assert on the real exit code, because that
 * is the only way this class of failure is visible.
 *
 * These tests are also the thing that keeps the hook's WorkDo allowlist honest against
 * `.github/scripts/guard-no-workdo-changes.sh`. A local guard that is MORE permissive than CI
 * hands back exactly the false confidence it was added to remove.
 */
class ClaudeConfigurationTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function hookPath(): string
    {
        return $this->root() . '/.claude/hooks/guard-protected-paths.php';
    }

    /**
     * Run the hook exactly as Claude Code would: the payload on stdin, nothing else.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function runHook(array $payload): array
    {
        $process = proc_open(
            ['php', $this->hookPath()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root()
        );

        $this->assertIsResource($process, 'Could not execute the hook.');

        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function assertBlocked(array $payload, string $because): array
    {
        $result = $this->runHook($payload);

        $this->assertSame(2, $result['exit'],
            $because . ' — the hook exited ' . $result['exit'] . '. ONLY exit code 2 blocks a tool '
            . 'call; every other code lets it proceed, so this is a guard that is not guarding. '
            . 'stderr: ' . trim($result['stderr']));

        $this->assertNotSame('', trim($result['stderr']),
            'A refusal must explain itself on stderr — that is the text the model is shown.');

        $decoded = json_decode(trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'The refusal did not print a JSON decision on stdout.');
        $this->assertSame('deny',
            $decoded['hookSpecificOutput']['permissionDecision'] ?? null,
            'The stdout decision is not the documented `permissionDecision: deny`.');

        return $result;
    }

    private function assertAllowed(array $payload, string $because): void
    {
        $result = $this->runHook($payload);

        $this->assertSame(0, $result['exit'],
            $because . ' — the hook exited ' . $result['exit'] . ' instead of 0. A guard that '
            . 'refuses legitimate work is a guard that gets switched off. stderr: '
            . trim($result['stderr']));
    }

    // ===================================================================================
    // THE CONFIGURATION IS PRESENT AND WELL-FORMED
    // ===================================================================================

    public function test_every_configuration_file_is_committed_and_present(): void
    {
        foreach ([
            '.claude/settings.json',
            '.claude/hooks/guard-protected-paths.php',
            '.claude/agents/tenant-authz-reviewer.md',
            '.claude/commands/tenant-check.md',
            '.claude/README.md',
            'CLAUDE.md',
        ] as $relative) {
            $this->assertFileExists($this->root() . '/' . $relative);
        }
    }

    public function test_the_settings_file_wires_the_hook_as_documented(): void
    {
        $settings = json_decode(
            (string) file_get_contents($this->root() . '/.claude/settings.json'),
            true
        );

        $this->assertIsArray($settings, '.claude/settings.json is not valid JSON.');

        $matchers = $settings['hooks']['PreToolUse'] ?? null;
        $this->assertIsArray($matchers, 'No hooks.PreToolUse entry — the hook is not wired at all.');

        $tools = explode('|', $matchers[0]['matcher'] ?? '');

        // The matcher is a pipe-separated list of exact tool names. Every tool that can reach a
        // protected path must be in it, or the hook simply never runs for that tool.
        foreach (['Edit', 'Write', 'NotebookEdit', 'Read', 'Bash'] as $tool) {
            $this->assertContains($tool, $tools,
                "The PreToolUse matcher does not include {$tool}, so the hook never runs for it.");
        }

        $hook = $matchers[0]['hooks'][0] ?? [];
        $this->assertSame('command', $hook['type'] ?? null);
        $this->assertStringContainsString('guard-protected-paths.php',
            $hook['command'] . ' ' . implode(' ', $hook['args'] ?? []),
            'The wired command does not point at the guard script.');
        $this->assertStringContainsString('${CLAUDE_PROJECT_DIR}',
            implode(' ', $hook['args'] ?? []),
            'The script path is not expressed with ${CLAUDE_PROJECT_DIR}, so it breaks when the '
            . 'session is started from a different directory.');
    }

    public function test_the_hook_script_is_executable(): void
    {
        // A non-executable hook fails rather than blocks — and a failing PreToolUse hook does not
        // block the call.
        $this->assertTrue(is_executable($this->hookPath()),
            'The hook script is not executable.');
    }

    public function test_personal_configuration_is_not_committed(): void
    {
        $gitignore = (string) file_get_contents($this->root() . '/.gitignore');

        $this->assertStringContainsString('.claude/settings.local.json', $gitignore,
            'Personal settings are not gitignored, so someone\'s local overrides will be '
            . 'committed over the shared project configuration.');

        $this->assertFileDoesNotExist($this->root() . '/.claude/settings.local.json',
            'A personal settings file is present in the repository.');
    }

    // ===================================================================================
    // RULE 1 — packages/workdo/, WITH the LynomiaChat exception
    // ===================================================================================

    #[DataProvider('vendoredModuleWrites')]
    public function test_a_write_to_a_vendored_module_is_refused(string $tool, array $input): void
    {
        $result = $this->assertBlocked(
            ['hook_event_name' => 'PreToolUse', 'tool_name' => $tool, 'tool_input' => $input],
            "A {$tool} into a vendored WorkDo module was not refused"
        );

        $this->assertStringContainsString('packages/workdo/', $result['stderr']);
        $this->assertStringContainsString('LynomiaChat', $result['stderr'],
            'The refusal does not mention the exemption, so a developer working on the '
            . 'first-party module cannot tell why they were blocked.');
    }

    public static function vendoredModuleWrites(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'Edit, absolute path' => ['Edit', ['file_path' => $root . '/packages/workdo/LandingPage/src/X.php']],
            'Edit, relative path' => ['Edit', ['file_path' => 'packages/workdo/LandingPage/src/X.php']],
            'Edit, ./ prefixed' => ['Edit', ['file_path' => './packages/workdo/LandingPage/src/X.php']],
            'Write' => ['Write', ['file_path' => 'packages/workdo/Blog/src/New.php', 'content' => 'x']],
            'NotebookEdit' => ['NotebookEdit', ['notebook_path' => 'packages/workdo/Blog/a.ipynb']],
            'MultiEdit, nested path' => ['MultiEdit', ['edits' => [['file_path' => 'packages/workdo/Blog/src/A.php']]]],
            'a different vendored module' => ['Edit', ['file_path' => 'packages/workdo/ProductAffiliate/src/Y.php']],
        ];
    }

    #[DataProvider('firstPartyModuleWrites')]
    public function test_a_write_to_the_first_party_module_is_allowed(string $path): void
    {
        $this->assertAllowed(
            ['tool_name' => 'Edit', 'tool_input' => ['file_path' => $path]],
            'LynomiaChat is first-party and must stay editable'
        );
    }

    public static function firstPartyModuleWrites(): array
    {
        return [
            'relative' => ['packages/workdo/LynomiaChat/src/Providers/X.php'],
            'absolute' => [dirname(__DIR__, 3) . '/packages/workdo/LynomiaChat/src/X.php'],
            'nested deeply' => ['packages/workdo/LynomiaChat/src/a/b/c/D.php'],
        ];
    }

    public function test_the_exemption_is_an_exact_name_and_not_a_prefix(): void
    {
        // A vendored module whose name merely STARTS with the exempt one must still be refused,
        // or a future `LynomiaChatPro` from upstream would be editable by accident.
        $this->assertBlocked(
            ['tool_name' => 'Edit', 'tool_input' => ['file_path' => 'packages/workdo/LynomiaChatPro/src/X.php']],
            'The allowlist matched a prefix rather than an exact directory name'
        );
    }

    public function test_the_hook_allowlist_agrees_with_the_ci_guard(): void
    {
        // CI is the authority. A local guard that is MORE permissive than CI gives back exactly
        // the false confidence it exists to remove.
        $hook = (string) file_get_contents($this->hookPath());
        $ci = (string) file_get_contents($this->root() . '/.github/scripts/guard-no-workdo-changes.sh');

        preg_match('/const FIRST_PARTY_MODULES = \[(.*?)\];/s', $hook, $hookMatch);
        preg_match('/FIRST_PARTY_MODULES=\((.*?)\)/s', $ci, $ciMatch);

        $this->assertNotEmpty($hookMatch, 'Could not find the hook allowlist.');
        $this->assertNotEmpty($ciMatch, 'Could not find the CI allowlist.');

        preg_match_all("/'([A-Za-z0-9_]+)'/", $hookMatch[1], $hookNames);
        preg_match_all('/([A-Za-z0-9_]+)/', trim($ciMatch[1]), $ciNames);

        $fromHook = $hookNames[1];
        $fromCi = $ciNames[1];
        sort($fromHook);
        sort($fromCi);

        $this->assertSame($fromCi, $fromHook,
            'The hook and the CI guard disagree about which WorkDo modules are first-party. '
            . 'CI: [' . implode(', ', $fromCi) . '] hook: [' . implode(', ', $fromHook) . ']');
    }

    public function test_reading_a_vendored_module_is_still_allowed(): void
    {
        // Understanding the modules is most of the work on this platform. Only WRITES are refused.
        $this->assertAllowed(
            ['tool_name' => 'Read', 'tool_input' => ['file_path' => 'packages/workdo/LandingPage/src/X.php']],
            'Reading a vendored module must stay possible'
        );
    }

    // ===================================================================================
    // RULE 2 — secret-bearing files, for every tool including subagents
    // ===================================================================================

    #[DataProvider('secretPaths')]
    public function test_a_secret_file_is_refused_for_reads_and_writes(string $path): void
    {
        foreach (['Read', 'Edit', 'Write'] as $tool) {
            $this->assertBlocked(
                ['tool_name' => $tool, 'tool_input' => ['file_path' => $path]],
                "{$tool} of {$path} was not refused"
            );
        }
    }

    public static function secretPaths(): array
    {
        return [
            '.env' => ['.env'],
            '.env.testing' => ['.env.testing'],
            '.env.production' => ['.env.production'],
            'the api log' => ['storage/logs/api-2026-10-18.log'],
            'a private key' => ['storage/app/private.pem'],
            'composer credentials' => ['auth.json'],
            'an ssh key' => ['.ssh/id_rsa'],
        ];
    }

    #[DataProvider('safeConfigPaths')]
    public function test_the_documented_safe_files_stay_readable(string $path): void
    {
        // CLAUDE.md tells people to read .env.example for key NAMES. A guard that contradicted the
        // project's own instructions would be the wrong guard.
        $this->assertAllowed(
            ['tool_name' => 'Read', 'tool_input' => ['file_path' => $path]],
            $path . ' must stay readable'
        );
    }

    public static function safeConfigPaths(): array
    {
        return [
            '.env.example' => ['.env.example'],
            'config/auth.php' => ['config/auth.php'],
            'the laravel log' => ['storage/logs/laravel.log'],
            'an ordinary source file' => ['app/Models/Customer.php'],
        ];
    }

    // ===================================================================================
    // Bash — the half permission rules cannot see
    // ===================================================================================

    #[DataProvider('refusedCommands')]
    public function test_a_shell_command_reaching_a_protected_path_is_refused(string $command): void
    {
        $this->assertBlocked(
            ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]],
            "The shell command was not refused: {$command}"
        );
    }

    public static function refusedCommands(): array
    {
        return [
            'cat the env file' => ['cat .env'],
            'grep the env file' => ['grep APP_KEY .env'],
            'read the api log' => ['tail -100 storage/logs/api-2026-10-18.log'],
            'sed into a vendored module' => ["sed -i 's/a/b/' packages/workdo/Blog/src/X.php"],
            'redirect into a vendored module' => ['echo x > packages/workdo/Blog/src/X.php'],
            'append into a vendored module' => ['echo x >> packages/workdo/Blog/src/X.php'],
            'rm inside a vendored module' => ['rm packages/workdo/Blog/src/X.php'],
            'patch a vendored module' => ['git apply /tmp/p.diff packages/workdo/Blog/src/X.php'],
        ];
    }

    #[DataProvider('allowedCommands')]
    public function test_legitimate_shell_commands_are_not_refused(string $command): void
    {
        $this->assertAllowed(
            ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]],
            "A legitimate command was refused: {$command}"
        );
    }

    public static function allowedCommands(): array
    {
        return [
            // Reading and searching the modules is most of the work here.
            'grep the module tree' => ['grep -rn "store_id" packages/workdo/Blog/src/'],
            'list a module' => ['ls packages/workdo/LandingPage/src'],
            'cat a module file' => ['cat packages/workdo/Blog/src/X.php'],
            // Writing to the FIRST-PARTY module.
            'sed into LynomiaChat' => ["sed -i 's/a/b/' packages/workdo/LynomiaChat/src/X.php"],
            // The documented-safe config file.
            'cat the env example' => ['cat .env.example'],
            'grep the env example' => ['grep CACHE .env.example'],
            // Ordinary work.
            'run the tests' => ['php artisan test --testsuite=Unit'],
            'read the laravel log' => ['tail storage/logs/laravel.log'],
            'git status' => ['git status --porcelain'],
        ];
    }

    // ===================================================================================
    // The hook must not break on anything it is handed
    // ===================================================================================

    #[DataProvider('degeneratePayloads')]
    public function test_the_hook_fails_open_rather_than_erroring(string $raw): void
    {
        // A PHP fatal error exits 255, which does NOT block — so a crash here is invisible in
        // normal use. These payloads assert it exits cleanly instead of crashing.
        $process = proc_open(
            ['php', $this->hookPath()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root()
        );

        fwrite($pipes[0], $raw);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit,
            'A degenerate payload did not exit 0. Input: ' . $raw
            . ' / stderr: ' . trim($stderr));

        $this->assertStringNotContainsStringIgnoringCase('fatal', $stderr,
            'The hook raised a PHP error. A fatal error exits 255, which does NOT block a tool '
            . 'call, so the guard would silently stop working.');
        $this->assertStringNotContainsStringIgnoringCase('warning', $stderr);
        $this->assertSame('', trim($stdout), 'The hook printed a decision for a payload it should ignore.');
    }

    public static function degeneratePayloads(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n"],
            'not json' => ['this is not json'],
            'a json array' => ['[]'],
            'a json string' => ['"hello"'],
            'null' => ['null'],
            'no tool name' => ['{"tool_input":{"file_path":".env"}}'],
            'no tool input' => ['{"tool_name":"Edit"}'],
            'null input' => ['{"tool_name":"Edit","tool_input":null}'],
            'input is a string' => ['{"tool_name":"Edit","tool_input":"x"}'],
            'unknown tool' => ['{"tool_name":"WebFetch","tool_input":{"url":"https://x.test"}}'],
            'empty bash command' => ['{"tool_name":"Bash","tool_input":{"command":""}}'],
            'deeply nested' => ['{"tool_name":"Edit","tool_input":{"a":{"b":{"c":[{"d":1}]}}}}'],
        ];
    }

    // ===================================================================================
    // The subagent and the command
    // ===================================================================================

    public function test_the_reviewer_subagent_is_read_only(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/agents/tenant-authz-reviewer.md');

        $this->assertStringStartsWith('---', $source, 'The subagent has no frontmatter.');

        preg_match('/^---\s*\n(.*?)\n---/s', $source, $m);
        $frontmatter = $m[1] ?? '';

        $this->assertStringContainsString('name: tenant-authz-reviewer', $frontmatter);
        $this->assertStringContainsString('description:', $frontmatter,
            'Without a description Claude cannot decide when to delegate to it.');

        preg_match('/^tools:\s*(.+)$/m', $frontmatter, $toolsMatch);
        $this->assertNotEmpty($toolsMatch,
            'The subagent declares no `tools`, so it INHERITS every tool available to subagents — '
            . 'including Bash and the write tools. That is the opposite of a read-only reviewer.');

        $tools = array_map('trim', explode(',', $toolsMatch[1]));
        sort($tools);

        $this->assertSame(['Glob', 'Grep', 'Read'], $tools,
            'The reviewer\'s tool list changed. It must stay read-only: with Bash it could read '
            . 'secrets, and with Edit or Write it could change the code it is reviewing. Declared: '
            . implode(', ', $tools));
    }

    /**
     * The subagent and the slash command CANNOT be invoked from the session that creates them —
     * Claude Code registers project agents and commands at session start, so both become live only
     * in a new session in this repository. Measured: `Agent(subagent_type: tenant-authz-reviewer)`
     * answers "Agent type not found", and `/tenant-check` answers "Unknown skill".
     *
     * So a live smoke test is not available here, and these tests are the substitute: they hold
     * both files to the documented frontmatter contract, field by field, so that "it will load" is
     * evidenced rather than assumed.
     */
    #[DataProvider('frontmatterContracts')]
    public function test_the_frontmatter_satisfies_the_documented_contract(
        string $relative,
        array $required,
        array $forbidden
    ): void {
        $source = (string) file_get_contents($this->root() . '/' . $relative);

        $this->assertStringStartsWith("---\n", $source,
            $relative . ' must open with `---` on line 1 or the frontmatter is not parsed at all.');

        preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $source, $m);
        $this->assertNotEmpty($m, $relative . ' has no closing `---`.');

        $keys = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):/', $line, $k)) {
                $keys[] = $k[1];
            }
        }

        foreach ($required as $key) {
            $this->assertContains($key, $keys, "{$relative} is missing the `{$key}` field.");
        }

        foreach ($forbidden as $key) {
            $this->assertNotContains($key, $keys,
                "{$relative} declares `{$key}`, which is not permitted in this file type.");
        }
    }

    public static function frontmatterContracts(): array
    {
        return [
            // A subagent requires name + description. Nothing is forbidden.
            'the subagent' => ['.claude/agents/tenant-authz-reviewer.md', ['name', 'description', 'tools'], []],
            // A command file may NOT declare `name` or `paths` — those belong to skills.
            'the command' => ['.claude/commands/tenant-check.md', ['description'], ['name', 'paths']],
        ];
    }

    public function test_the_subagent_name_is_valid_and_matches_its_filename(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/agents/tenant-authz-reviewer.md');

        preg_match('/^name:\s*(.+)$/m', $source, $m);
        $name = trim($m[1] ?? '');

        $this->assertSame('tenant-authz-reviewer', $name,
            'The subagent is addressed by its `name` field, not its filename. They must agree or '
            . 'the owner will invoke a name that does not resolve.');

        // Documented constraints on the name field.
        $this->assertLessThanOrEqual(256, strlen($name));
        $this->assertStringNotContainsString(':', $name);
        $this->assertStringStartsNotWith('-', $name);
    }

    public function test_every_tool_the_subagent_declares_is_a_real_tool(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/agents/tenant-authz-reviewer.md');

        preg_match('/^tools:\s*(.+)$/m', $source, $m);
        $declared = array_map('trim', explode(',', $m[1] ?? ''));

        // If a `tools` entry resolves to no tool, the subagent fails to LAUNCH. A typo here is a
        // subagent that never runs, and the frontmatter looks fine.
        $known = ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash', 'WebFetch', 'WebSearch',
                  'NotebookEdit', 'Task', 'Agent', 'TodoWrite', 'SlashCommand'];

        foreach ($declared as $tool) {
            $this->assertContains($tool, $known,
                "`{$tool}` is not a known tool name. A tools entry that resolves to nothing makes "
                . 'the subagent fail to launch.');
        }
    }

    public function test_the_subagent_model_field_is_a_permitted_value(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/agents/tenant-authz-reviewer.md');

        preg_match('/^model:\s*(.+)$/m', $source, $m);

        if ($m === []) {
            $this->addToAssertionCount(1); // omitting it is valid

            return;
        }

        $this->assertContains(trim($m[1]), ['inherit', 'sonnet', 'opus', 'haiku', 'fable'],
            'The `model` field must be inherit, a tier name, or a full model id.');
    }

    public function test_the_reviewer_covers_every_area_the_owner_asked_for(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/agents/tenant-authz-reviewer.md');

        foreach ([
            'cross-tenant' => 'null_tenant',
            'caller-supplied identifiers' => '$request->',
            'role and permission escalation' => 'isAbleTo',
            'token boundaries' => 'CustomAuth',
            'order ownership' => 'Order',
            'payment ownership' => 'money',
            'account ownership' => 'password',
            'API authorization' => 'routes/api.php',
            'session handling' => 'remember_token',
        ] as $area => $marker) {
            $this->assertStringContainsString($marker, $source,
                "The reviewer no longer covers {$area} (looked for '{$marker}').");
        }
    }

    public function test_the_tenant_check_command_only_orchestrates_existing_suites(): void
    {
        $source = (string) file_get_contents($this->root() . '/.claude/commands/tenant-check.md');

        $this->assertStringContainsString('description:', $source);

        // It must name the suites that exist...
        foreach (['tests/Unit/Tenancy', 'tests/Feature/Tenancy', 'tests/Feature/Security'] as $path) {
            $this->assertStringContainsString($path, $source,
                "The command does not run {$path}.");
            $this->assertDirectoryExists($this->root() . '/' . $path,
                "The command names {$path}, which does not exist.");
        }

        // ...and it must point at the gate, because "2 failed" is the expected state.
        $this->assertStringContainsString('assert-known-test-outcomes.php', $source,
            'The command does not run the known-outcome gate, so its output cannot be '
            . 'interpreted: the Feature suite has 2 accepted failures by design.');

        $this->assertFileExists($this->root() . '/.github/scripts/assert-known-test-outcomes.php');
    }

    public function test_claude_md_does_not_restate_the_large_documents(): void
    {
        $claudeMd = (string) file_get_contents($this->root() . '/CLAUDE.md');

        // Concise by instruction: it points at docs/ rather than duplicating them.
        $this->assertLessThan(300, count(explode("\n", $claudeMd)),
            'CLAUDE.md has grown into a second copy of docs/. It is meant to be the short list of '
            . 'what will bite you, pointing at the full analysis.');

        foreach ([
            'docs/security/P01-SECURITY-WORK-QUEUE.md',
            'docs/development/phase-1/10-NEXT-WORK-PACKAGES.md',
        ] as $pointer) {
            $this->assertStringContainsString($pointer, $claudeMd,
                "CLAUDE.md does not point at {$pointer}.");
            $this->assertFileExists($this->root() . '/' . $pointer,
                "CLAUDE.md points at {$pointer}, which does not exist.");
        }
    }
}
