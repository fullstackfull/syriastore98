<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * THE PHASE 03 FIXTURE TILES ARE NOT COMMITTED WHERE THE WEBSERVER SERVES THEM.
 * =================================================================================================
 *
 * WHAT HAPPENED, and it is the reason this file exists rather than a precaution.
 *
 * Phase 03's M0 and M1 committed 44 synthetic tiles under the REPOSITORY-ROOT `uploads/` tree. The
 * guard that forbids exactly that -- `.github/scripts/guard-no-new-root-uploads.sh`, P1-OWN-10 /
 * OWNER DECISION 11 -- never fired, because `ci.yml` triggers only on `pull_request` and on pushes
 * to `main`/`master`, and Phase 03 had no pull request. **The violation sat on the branch for five
 * milestones and the first PR found it in its first run.**
 *
 * WHY IT IS NOT MERELY REPOSITORY WEIGHT, quoting the guard because it states the mechanism:
 *
 *     the tracked `.htaccess` rewrites `/uploads/...` to `public/uploads/...` ONLY when the file
 *     does NOT exist at the root, so one that DOES exist there is served directly by the
 *     webserver, with no application and no authorisation.
 *
 * The content is synthetic, so nothing private was disclosed. The MECHANISM is real, and the
 * guard's job is to stop the set growing.
 *
 * THE FIX, and the measurement that shaped it. `public/uploads` is a SYMLINK to `../uploads` --
 * measured, and `.gitignore:1-8` explains that the application's `theme` disk genuinely writes to
 * the repository root, so a dev environment needs the link to see its own media. "Write to
 * public/uploads instead" therefore resolves to the same directory the guard refuses commits in.
 *
 * So the bytes live in `docs/development/phase-3/fixtures/media/`, outside anything served, and the
 * seeder copies them to `uploads/` when a developer seeds -- the same pattern `.gitignore` already
 * uses for the P2 dev fixture at `/uploads/1/`. Committing is what the guard forbids; writing at
 * runtime is what the application does.
 *
 * This test reads FILES ONLY. It needs no database, no application and no CI.
 */
class StorefrontFixtureMediaIsNotServedTest extends TestCase
{
    private const FIXTURE_DIR = 'docs/development/phase-3/fixtures/media';
    private const SEEDER = 'docs/development/phase-3/tools/seed-storefront-fixture.php';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Tracked paths under the repository-root uploads/ tree, as git really holds them. */
    private function trackedUploads(): array
    {
        $output = [];
        $code = 0;

        exec('cd ' . escapeshellarg($this->root()) . ' && git ls-files uploads 2>/dev/null',
            $output, $code);

        $this->assertSame(0, $code, 'git ls-files failed, so this test could not be evaluated.');

        return $output;
    }

    #[Test]
    public function test_no_phase_03_fixture_tile_is_tracked_under_the_served_root(): void
    {
        $offenders = array_values(array_filter($this->trackedUploads(),
            static fn (string $path): bool => str_contains($path, 'p3fixt')));

        $this->assertSame([], $offenders, sprintf(
            "A Phase 03 fixture tile is committed under the repository-root uploads/ tree, where\n"
            . "the webserver serves it directly with no application and no authorisation:\n  %s\n\n"
            . "Put the bytes in %s and let the seeder copy them. See P1-OWN-10 and\n"
            . "docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md.",
            implode("\n  ", $offenders), self::FIXTURE_DIR));
    }

    /**
     * PREMISE. If the committed source is gone, the test above passes for the wrong reason -- there
     * are no tiles anywhere, so of course none is in the wrong place, and every storefront capture
     * silently renders broken tiles instead.
     */
    #[Test]
    public function test_the_committed_fixture_media_is_still_there(): void
    {
        $dir = $this->root() . '/' . self::FIXTURE_DIR;

        $this->assertDirectoryExists($dir,
            'The committed fixture media is missing, so the assertion that none of it sits in the '
            . 'served root is vacuous.');

        $files = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getFilename();
            }
        }

        $this->assertCount(44, $files,
            'The fixture tile count changed. 44 is MEASURED -- it is what M0 and M1 committed and '
            . 'what was moved out of the served root. Update this number deliberately, with the '
            . 'reason, rather than to make the test pass: ' . count($files) . ' found.');
    }

    /**
     * And the seeder actually stages them, so a fresh clone renders. Asserting the move without
     * asserting the copy would leave a repository that passes its guards and shows broken images.
     */
    #[Test]
    public function test_the_seeder_stages_the_committed_media_into_the_uploads_tree(): void
    {
        $source = file_get_contents($this->root() . '/' . self::SEEDER);

        $this->assertNotFalse($source, self::SEEDER . ' is missing.');

        $this->assertStringContainsString("fixtures/media", $source,
            'The seeder no longer references the committed fixture media, so a fresh clone would '
            . 'have no tiles to render.');
        $this->assertStringContainsString('stageFixtureMedia', $source,
            'The staging step is gone. The tiles are committed outside the served tree and nothing '
            . 'copies them into place.');
        $this->assertStringContainsString("base_path('uploads')", $source,
            "The staging target changed. It must be the repository-root uploads/ -- that is where "
            . "the application's `theme` disk really writes, and `public/uploads` is a SYMLINK to "
            . 'it rather than a separate directory.');
    }

    /**
     * The destinations must be gitignored, or the next `git add -A` re-commits what was just moved
     * out and the guard fires again.
     */
    #[Test]
    public function test_the_staging_destinations_are_gitignored(): void
    {
        foreach ([
            'uploads/p3fixt/logo-aleppo-soap.png',
            'uploads/2/p3fixt/olive-pure.png',
            'uploads/5/p3fixt/buds-pro.png',
            'uploads/6/p3fixt/mint-pot.png',
        ] as $path) {
            $output = [];
            $code = 0;

            exec('cd ' . escapeshellarg($this->root())
                . ' && git check-ignore ' . escapeshellarg($path) . ' 2>/dev/null', $output, $code);

            $this->assertSame(0, $code,
                "`{$path}` is NOT gitignored, so seeding a development environment leaves it ready "
                . 'to be committed back into the served root by the next `git add -A`.');
        }
    }
}
