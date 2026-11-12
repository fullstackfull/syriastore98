<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-SEC-40. The document root is the REPOSITORY root on the live host: a root
 * index.php shim sits beside the root .htaccess, and `GET /.env` answered 403 on
 * 2026-11-11 while `GET /a-nonexistent-path` answered 404, which proves the root
 * .htaccess is the live config.
 *
 * The front-controller rewrite is guarded by `RewriteCond %{REQUEST_FILENAME} !-f`,
 * so Apache serves ANY file that really exists rather than routing it to index.php.
 * On the same date `.git/HEAD`, `.git/index`, `.git/objects/info/packs`,
 * `composer.lock` and `storage/logs/laravel.log` all answered 200 with real
 * Content-Length values. A readable `objects/info/packs` names the packfiles, which
 * is what a .git dumper needs to reconstruct the entire history -- including the
 * database dump blob and, per CLAUDE.md, an unrotated APP_KEY.
 *
 * This test does not need Apache. It extracts the deny patterns from the committed
 * .htaccess files and applies them the way Apache would: <FilesMatch> against the
 * basename, RedirectMatch against the URL path. It therefore fails if the block is
 * deleted, commented out, or narrowed so that it stops covering a path we measured
 * as reachable.
 *
 * The negative controls are the point. Denying /storage/ wholesale would have broken
 * every uploaded image, because uploads are served as /storage/uploads/<file> through
 * the public/storage symlink; only /storage/{logs,framework,app} are denied.
 */
class DumpAndVcsPathsAreDeniedTest extends TestCase
{
    private const ROOT_HTACCESS = __DIR__ . '/../../../.htaccess';
    private const PUBLIC_HTACCESS = __DIR__ . '/../../../public/.htaccess';
    private const DENY_CONF = __DIR__ . '/../../../docs/security/p1-sec-40-apache-deny.conf';

    /** URL paths that MUST be refused, each one measured reachable or dump-shaped. */
    public static function deniedPaths(): array
    {
        return [
            'git ref'              => ['/.git/HEAD'],
            'git config'           => ['/.git/config'],
            'git index'            => ['/.git/index'],
            'git packfile list'    => ['/.git/objects/info/packs'],
            'git reflog'           => ['/.git/logs/HEAD'],
            'git pack'             => ['/.git/objects/pack/pack-abc.pack'],
            'dotenv'               => ['/.env'],
            'dotenv variant'       => ['/.env.backup'],
            'the tracked dump'     => ['/storesyria_ecommercego.sql'],
            'a gzipped dump'       => ['/backup.sql.gz'],
            'a plain dump'         => ['/db.dump'],
            'a sqlite fixture'     => ['/database/p2dev.sqlite'],
            'laravel log'          => ['/storage/logs/laravel.log'],
            'framework cache'      => ['/storage/framework/sessions/abc'],
            'private disk'         => ['/storage/app/private-invoice.pdf'],
            'composer manifest'    => ['/composer.json'],
            'composer lock'        => ['/composer.lock'],
            'editor swapfile'      => ['/config/app.php.swp'],
        ];
    }

    /** URL paths that MUST keep working. A deny that breaks these is worse than none. */
    public static function allowedPaths(): array
    {
        return [
            'an uploaded image'    => ['/storage/uploads/profile/avatar.png'],
            'an upload via disk'   => ['/storage/uploads/logo/brand.jpg'],
            'the design system'    => ['/css/store-syria-ds.css'],
            'a script'             => ['/js/app.js'],
            'a themed asset'       => ['/market_assets/img/hero.webp'],
            'the front controller' => ['/index.php'],
            'the site root'        => ['/'],
            'a storefront route'   => ['/shop/my-store/product/42'],
            'an admin api route'   => ['/api/admin/orders'],
            'a webfont'            => ['/assets/fonts/cairo.woff2'],
        ];
    }

    #[Test]
    public function test_the_deny_rules_exist_and_name_p1_sec_40(): void
    {
        $this->assertFileExists(
            self::DENY_CONF,
            'docs/security/p1-sec-40-apache-deny.conf is gone. It is the written Apache '
            . 'control for P1-SEC-40. Deleting it suppresses a finding the owner deferred '
            . 'but did not resolve.'
        );

        $body = file_get_contents(self::DENY_CONF);

        $this->assertStringContainsString('P1-SEC-40', $body);
        $this->assertMatchesRegularExpression(
            '/^\s*RedirectMatch\s+404/m',
            $body,
            'No RedirectMatch deny survives in the conf. <Directory> is illegal in .htaccess '
            . 'and <FilesMatch> cannot match a path prefix, so RedirectMatch is what would '
            . 'refuse the .git directory once the owner activates these rules.'
        );
    }

    /**
     * MERGE SAFETY. The live document root is the repository root and the deployed tree
     * is a git checkout on `main` (.git/HEAD measured 2026-11-11). A deny directive
     * committed to either served .htaccess therefore goes live on the next `git pull`,
     * with no deploy pipeline and nobody intending it -- which would breach the owner's
     * standing instruction not to change public access to the test site or its .git.
     *
     * An earlier revision of this branch did carry those directives. This test is why
     * that cannot happen again silently.
     *
     * The pre-existing <Files .env> deny is excluded: it is already live on main and is
     * not ours to remove.
     */
    #[Test]
    public function test_neither_served_htaccess_activates_the_deferred_deny_rules(): void
    {
        foreach ([self::ROOT_HTACCESS, self::PUBLIC_HTACCESS] as $file) {
            $this->assertFileExists($file);

            $directives = [];
            $inStockEnvBlock = false;

            foreach (explode("\n", file_get_contents($file)) as $line) {
                if (preg_match('/^\s*#/', $line)) {
                    continue;
                }

                // The stock <Files .env> deny predates this branch and is already live on
                // main, so it is not ours to remove. Skip its whole block, nothing else.
                if (preg_match('/^\s*<Files\s+\.env\s*>/', $line)) {
                    $inStockEnvBlock = true;
                    continue;
                }
                if ($inStockEnvBlock) {
                    if (preg_match('#^\s*</Files>#', $line)) {
                        $inStockEnvBlock = false;
                    }
                    continue;
                }

                if (preg_match('/^\s*(RedirectMatch|Require\s+all\s+denied|Deny\s+from\s+all|<FilesMatch)/', $line)) {
                    $directives[] = trim($line);
                }
            }

            $this->assertSame(
                [],
                $directives,
                basename(dirname($file)) . '/.htaccess has gained an ACTIVE deny directive: '
                . implode(' | ', $directives) . "\n"
                . 'The P1-SEC-40 rules are OWNER-DEFERRED. They belong in '
                . 'docs/security/p1-sec-40-apache-deny.conf, which no server reads. Putting '
                . 'them here arms them: the deployed tree is a checkout of main, so merging '
                . 'changes public access on the live host the next time anyone pulls. The '
                . 'owner asked for the opposite. Move them back to the conf.'
            );
        }
    }

    #[Test]
    public function test_both_served_files_still_explain_where_the_rules_went(): void
    {
        foreach ([self::ROOT_HTACCESS, self::PUBLIC_HTACCESS] as $file) {
            $body = file_get_contents($file);

            $this->assertStringContainsString(
                'P1-SEC-40',
                $body,
                basename(dirname($file)) . '/.htaccess no longer names P1-SEC-40. The rules '
                . 'are deliberately absent, so this comment is the only thing telling the '
                . 'next reader that the absence is a deferral rather than an oversight. '
                . 'Removing it suppresses the finding.'
            );

            $this->assertStringContainsString(
                'p1-sec-40-apache-deny.conf',
                $body,
                basename(dirname($file)) . '/.htaccess no longer points at the conf that '
                . 'holds the deny rules, so nobody can find them.'
            );
        }
    }

    /**
     * If the conf wrapped its rules in <IfModule mod_rewrite.c>, anyone activating them by
     * copying the file verbatim would inherit the flaw the existing <Files .env> deny has:
     * silently ceasing to apply the moment mod_rewrite is unloaded.
     */
    #[Test]
    public function test_the_conf_does_not_bury_its_rules_in_the_mod_rewrite_guard(): void
    {
        $body = file_get_contents(self::DENY_CONF);

        $this->assertFalse(
            $this->firstDirectiveOffset($body, '<IfModule\s+mod_rewrite\.c>'),
            'The P1-SEC-40 conf now wraps its rules in <IfModule mod_rewrite.c>. Everything '
            . 'inside that guard stops applying if mod_rewrite is unloaded, which is exactly '
            . 'how the stock <Files .env> deny fails open. Keep the rules unguarded.'
        );
    }

    /**
     * @param string $path
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('deniedPaths')]
    public function test_a_dangerous_path_is_refused(string $path): void
    {
        $this->assertTrue(
            $this->isDenied($path, self::DENY_CONF),
            "The P1-SEC-40 conf would SERVE {$path}. Every path in this provider was either "
            . 'measured returning 200 on the live host on 2026-11-11 or is dump-shaped. '
            . 'Widen the deny patterns rather than deleting the case.'
        );
    }

    /**
     * @param string $path
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('allowedPaths')]
    public function test_a_legitimate_path_still_resolves(string $path): void
    {
        $this->assertFalse(
            $this->isDenied($path, self::DENY_CONF),
            "The P1-SEC-40 conf now REFUSES {$path}, which must keep working. Uploads are "
            . 'served as /storage/uploads/<file> through the public/storage symlink, so a '
            . 'deny on /storage/ wholesale breaks every product image on the storefront. '
            . 'Only /storage/{logs,framework,app} may be denied.'
        );
    }

    /**
     * Offset of the first line that is really the given directive -- comment lines are
     * skipped, so prose describing a directive is never mistaken for one.
     */
    private function firstDirectiveOffset(string $body, string $directive): int|false
    {
        foreach (explode("\n", $body) as $i => $line) {
            if (preg_match('/^\s*#/', $line)) {
                continue;
            }
            if (preg_match('/^\s*' . $directive . '/', $line)) {
                // Offset of this line's start within the body.
                return strlen(implode("\n", array_slice(explode("\n", $body), 0, $i))) + 1;
            }
        }

        return false;
    }

    /**
     * Apply the committed rules the way Apache does: <FilesMatch> tests the basename,
     * RedirectMatch tests the URL path.
     */
    private function isDenied(string $path, string $file): bool
    {
        $body = file_get_contents($file);
        $basename = basename($path);

        foreach ($this->filesMatchPatterns($body) as $pattern) {
            if (@preg_match('#' . $pattern . '#', $basename) === 1) {
                return true;
            }
        }

        foreach ($this->redirectMatchPatterns($body) as $pattern) {
            if (@preg_match('#' . $pattern . '#', $path) === 1) {
                return true;
            }
        }

        // The stock <Files .env> block, which matches an exact filename.
        if (preg_match('/<Files\s+(\S+)\s*>/', $body, $m) && $basename === trim($m[1], '"\'')) {
            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function filesMatchPatterns(string $body): array
    {
        preg_match_all('/<FilesMatch\s+"([^"]+)"\s*>/', $body, $m);

        return $m[1] ?? [];
    }

    /** @return list<string> */
    private function redirectMatchPatterns(string $body): array
    {
        preg_match_all('/^\s*RedirectMatch\s+404\s+"([^"]+)"/m', $body, $m);

        return $m[1] ?? [];
    }
}
