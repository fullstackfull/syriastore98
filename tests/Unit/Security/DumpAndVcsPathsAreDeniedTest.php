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
    public function test_both_htaccess_files_carry_the_p1_sec_40_block(): void
    {
        foreach ([self::ROOT_HTACCESS, self::PUBLIC_HTACCESS] as $file) {
            $this->assertFileExists($file);
            $body = file_get_contents($file);

            $this->assertStringContainsString(
                'P1-SEC-40',
                $body,
                basename(dirname($file)) . '/.htaccess no longer names P1-SEC-40. The deny '
                . 'block was removed or retitled. It is the only thing stopping the live host '
                . 'from serving .git and database dumps -- see the measured evidence in the '
                . 'root .htaccess header before deleting it.'
            );

            $this->assertMatchesRegularExpression(
                '/^\s*RedirectMatch\s+404/m',
                $body,
                'No RedirectMatch deny survives in ' . $file . '. <Directory> is illegal in '
                . '.htaccess and <FilesMatch> cannot match a path prefix, so RedirectMatch is '
                . 'what refuses the .git directory.'
            );
        }
    }

    #[Test]
    public function test_the_deny_block_sits_outside_the_mod_rewrite_guard(): void
    {
        // The pre-existing <Files .env> deny is INSIDE <IfModule mod_rewrite.c>, so it
        // stops applying the moment mod_rewrite is unloaded. Ours must not inherit that.
        $body = file_get_contents(self::ROOT_HTACCESS);

        // Locate real DIRECTIVES, not mentions. The header comment explains that the
        // block sits "OUTSIDE <IfModule mod_rewrite.c>", and a plain strpos finds that
        // sentence first -- which made this assertion fail against a correct file.
        $guardAt = $this->firstDirectiveOffset($body, '<IfModule\s+mod_rewrite\.c>');
        $denyAt = $this->firstDirectiveOffset($body, 'RedirectMatch\s+404');

        $this->assertNotFalse($guardAt, 'the mod_rewrite guard vanished from the root .htaccess');
        $this->assertNotFalse($denyAt, 'the RedirectMatch deny vanished from the root .htaccess');

        $this->assertLessThan(
            $guardAt,
            $denyAt,
            'The P1-SEC-40 deny rules moved INSIDE <IfModule mod_rewrite.c>. They must stay '
            . 'above it: everything in that guard silently stops applying if mod_rewrite is '
            . 'unloaded, which is exactly how the existing <Files .env> deny would fail open.'
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
            $this->isDenied($path, self::ROOT_HTACCESS),
            "The root .htaccess would SERVE {$path}. Every path in this provider was either "
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
            $this->isDenied($path, self::ROOT_HTACCESS),
            "The root .htaccess now REFUSES {$path}, which must keep working. Uploads are "
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
