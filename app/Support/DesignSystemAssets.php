<?php

namespace App\Support;

/**
 * THE CACHE KEY FOR THE DESIGN-SYSTEM ASSETS, DERIVED FROM THE FILE RATHER THAN TYPED.
 *
 * It used to be a hand-bumped literal -- `?v=p02-7` -- in TWO places: the merchant shell, and the
 * per-screen include that some screens pull in as well. MEASURED during P2.5-WAVE-29: the script
 * was on `p02-9` in one and `p02-7` in the other, which is two URLs for one file and two cache
 * entries for the same bytes; and the stylesheet was on `p02-7` in BOTH after its contents had
 * changed in the preceding wave. A returning merchant's browser would have served that wave's
 * script against the previous wave's stylesheet -- the pinned column's edge still drawn over a
 * stacked row, the row gutters still indenting -- which is exactly the half-applied state the
 * mirrored-stylesheet rule exists to prevent, arriving through a different door.
 *
 * A literal is a promise that every future author will remember. This reads the file instead, so
 * the key changes when and only when the bytes do, and the two call sites cannot disagree because
 * neither of them carries a number any more.
 *
 * The hash is memoised per process: Blade renders these tags once per request, but a layout and
 * its include both call this, and a second `md5_file` over ~300KB for an answer already in hand is
 * waste. It is NOT cached across requests -- in deployment the file changes exactly when the
 * process is replaced, and in local development a stale key is the whole defect being fixed.
 *
 * A missing file returns a constant rather than throwing: a wrong cache key is a cosmetic problem
 * and a 500 on every admin page is not. The `<link>` would 404 either way and the deployment is
 * already broken at that point.
 */
final class DesignSystemAssets
{
    /** @var array<string, string> */
    private static array $versions = [];

    public static function version(string $relative): string
    {
        if (isset(self::$versions[$relative])) {
            return self::$versions[$relative];
        }

        $path = public_path($relative);
        $hash = is_file($path) ? substr((string) md5_file($path), 0, 10) : 'missing';

        return self::$versions[$relative] = $hash;
    }

    /** Test seam: forget the memoised hashes so a test can change a file and ask again. */
    public static function flush(): void
    {
        self::$versions = [];
    }
}
