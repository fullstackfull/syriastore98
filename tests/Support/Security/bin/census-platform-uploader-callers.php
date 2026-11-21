#!/usr/bin/env php
<?php

/**
 * P1-SEC-73 -- A READ-ONLY CENSUS OF EVERY CALL OF THE PLATFORM'S UPLOADER FUNCTIONS.
 *
 * WHY IT IS A SCRIPT AND NOT A TEST. The figures in `docs/security/P01-SECURITY-WORK-QUEUE.md` (P1-SEC-73) -- how many calls the policy
 * covers, how many are first-party, how many are vendored, how many are not covered at all -- drift every time a module is added or a
 * call is written. A test that asserted them would fail for reasons that say nothing about security; a number in a document with no way
 * to reproduce it is a claim. So the way to reproduce it is here, and it asserts nothing.
 *
 * WHAT IT READS. The PHP tokens (never the text: a comment or a string that names a function is not a call) of every `.php` file under
 * `app/`, `packages/`, `routes/`, `resources/views` and `themes/`. A call is a name followed by `(` that is not a definition. For each
 * it records the CALLEE'S QUALIFIER (`Utility::`, `LandingPageSetting::`, a bare name), because `LandingPageSetting` carries a pair of
 * same-named functions of its own that `App\Services\PlatformUploadPolicy` does NOT reach.
 *
 * PRECONDITIONS. PHP with the tokenizer; run from the repository root, or pass the root as the first argument. Nothing is written.
 *
 *     php tests/Support/Security/bin/census-platform-uploader-callers.php          # the summary
 *     php tests/Support/Security/bin/census-platform-uploader-callers.php all      # and every call, with its arguments
 */
$root = rtrim($argv[1] ?? '', '/');
$verbose = in_array('all', $argv, true);

if ($root === '' || $root === 'all') {
    $root = getcwd();
}

if (! is_dir($root . '/app') || ! is_dir($root . '/packages')) {
    fwrite(STDERR, "Run this from the repository root (it must hold app/ and packages/), or pass the root as the first argument.\n");
    exit(2);
}

$names = ['upload_file', 'keywiseupload_file', 'multi_upload_file', 'jsonupload_file', 'upload_woo_file', 'upload_theme_image'];
$rows = [];

foreach (['app', 'packages', 'routes', 'resources/views', 'themes'] as $directory) {
    if (! is_dir("$root/$directory")) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$directory", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (stripos($source, 'upload') === false) {
            continue;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($tokens[$i][1], '\\'));
            $base = substr($name, (int) strrpos('\\' . $name, '\\'));

            if (! in_array($base, $names, true)) {
                continue;
            }

            // a definition is not a call
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $p--;
            }
            if ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_FUNCTION) {
                continue;
            }

            // the next significant token must be `(`
            $q = $i + 1;
            while ($q < $count && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) {
                $q++;
            }
            if ($q >= $count || $tokens[$q] !== '(') {
                continue;
            }

            // the qualifier: what stands before `::` or `->`
            $qualifier = '(bare)';
            $b = $i - 1;
            while ($b >= 0 && is_array($tokens[$b]) && $tokens[$b][0] === T_WHITESPACE) {
                $b--;
            }
            if ($b >= 0 && is_array($tokens[$b]) && in_array($tokens[$b][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) {
                $operator = $tokens[$b][1];
                $b--;
                while ($b >= 0 && is_array($tokens[$b]) && $tokens[$b][0] === T_WHITESPACE) {
                    $b--;
                }
                $qualifier = ltrim((is_array($tokens[$b]) ? $tokens[$b][1] : $tokens[$b]), '\\') . $operator;
                $qualifier = str_starts_with($qualifier, 'App\\Models\\') ? substr($qualifier, strlen('App\\Models\\')) : $qualifier;
            }

            // the top-level arguments
            $depth = 0;
            $arguments = [''];
            for ($k = $q; $k < $count; $k++) {
                $token = $tokens[$k];
                $text = is_array($token) ? $token[1] : $token;

                if ($text === '(' || $text === '[' || $text === '{') {
                    $depth++;
                    if ($depth > 1) {
                        $arguments[count($arguments) - 1] .= $text;
                    }
                    continue;
                }
                if ($text === ')' || $text === ']' || $text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    $arguments[count($arguments) - 1] .= $text;
                    continue;
                }
                if ($depth === 1 && $text === ',') {
                    $arguments[] = '';
                    continue;
                }
                if (! (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))) {
                    $arguments[count($arguments) - 1] .= $text;
                }
            }
            $arguments = array_map(static fn ($a) => trim((string) preg_replace('/\s+/', ' ', $a)), $arguments);
            if (count($arguments) === 1 && $arguments[0] === '') {
                $arguments = [];
            }

            $rows[] = [str_replace("$root/", '', $file->getPathname()), $tokens[$i][2], $base, $qualifier, count($arguments), $arguments];
        }
    }
}

usort($rows, static fn ($a, $b) => [$a[2], $a[3], $a[0], $a[1]] <=> [$b[2], $b[3], $b[0], $b[1]]);

$summary = [];
foreach ($rows as [$path, $line, $function, $qualifier, $argumentCount, $arguments]) {
    $owner = str_starts_with($path, 'packages/') ? 'vendored' : 'first-party';
    $key = sprintf('%-20s via %-22s %d args  %s', $function, $qualifier, $argumentCount, $owner);
    $summary[$key] = ($summary[$key] ?? 0) + 1;

    if ($verbose) {
        printf("%s:%d %s %s(%d) name=%s | path=%s | rules=%s | image=%s\n", $path, $line, $qualifier, $function, $argumentCount, $arguments[2] ?? '', $arguments[3] ?? '', $arguments[4] ?? '', $arguments[5] ?? '');
    }
}

ksort($summary);
echo "---- every call, by function, qualifier, argument count and owner\n";
foreach ($summary as $key => $n) {
    echo str_pad((string) $n, 4, ' ', STR_PAD_LEFT), "  $key\n";
}

// what App\Services\PlatformUploadPolicy covers: the four functions of Utility and helper.php, however they are qualified
$covered = ['first-party' => 0, 'vendored' => 0];
$notCovered = ['LandingPageSetting' => 0, 'upload_theme_image' => 0, 'upload_woo_file' => 0];

foreach ($rows as [$path, , $function, $qualifier]) {
    $owner = str_starts_with($path, 'packages/') ? 'vendored' : 'first-party';

    if (str_starts_with($qualifier, 'LandingPageSetting')) {
        $notCovered['LandingPageSetting']++;
    } elseif (in_array($function, ['upload_file', 'keywiseupload_file', 'jsonupload_file'], true) && str_starts_with($qualifier, 'Utility')) {
        $covered[$owner]++;
    } elseif ($function === 'multi_upload_file') {
        $covered[$owner]++;
    } elseif ($function === 'upload_theme_image') {
        $notCovered['upload_theme_image']++;
    } elseif ($function === 'upload_woo_file') {
        $notCovered['upload_woo_file']++;
    }
}

echo "\n---- reached by App\\Services\\PlatformUploadPolicy (Utility::upload_file, ::keyWiseUpload_file, ::jsonUpload_file and multi_upload_file)\n";
printf("%4d  first-party\n%4d  vendored\n%4d  in all\n", $covered['first-party'], $covered['vendored'], array_sum($covered));
echo "\n---- NOT reached by it (the vendored LandingPage module's own pair; the sibling helpers, which have their own rules)\n";
foreach ($notCovered as $what => $n) {
    printf("%4d  %s\n", $n, $what);
}
echo "\n", count($rows), " calls in all\n";
