<?php

namespace Tests\Unit\Analytics;

use Shetabit\Visitor\Drivers\JenssegersAgent;
use Tests\TestCase;

/**
 * ANA-004 — WHAT THE DEVICE DETECTOR ACTUALLY DETECTS, measured, because the device breakdown's
 * disclosure is a claim about this library and a claim must be checked.
 * =================================================================================================
 *
 * `MerchantAnalyticsFigures::devices()` groups `shetabit_visits.device`, and its hint tells a
 * merchant that ordinary desktop visits land under "Device not recorded" -- that the large bucket
 * is the detector's blind spot and not a failure. That sentence is only honest if the detector
 * really is blind in exactly that way, so this file drives the real driver with real user-agent
 * strings and records what comes back.
 *
 * THE ROOT CAUSE, read at the source (`vendor/shetabit/visitor/src/Agent.php`, read verbatim):
 *
 *     protected $desktopDevices = ['Macintosh' => 'Macintosh'];
 *
 * That array is the ENTIRE desktop rule set, and `device()` merges only the desktop, phone and
 * tablet rules. There is no Windows rule, no Linux rule and no ChromeOS rule anywhere in the
 * library, so for the commonest computer on the web `device()` returns an empty string -- not
 * "Desktop", not "Unknown", nothing. An `Other`-labelled bucket (which is what the pre-existing
 * `ThemeAnalyticController` prints) therefore reads as a kind of device when it is really "the
 * library has no rule for this".
 *
 * WHY `platform()` IS NOT THE FIX, and this is the measurement that mattered. An independent review
 * suggested grouping by `platform` instead, since it has Windows and Linux rules. Driven here:
 * `platform()` reports an IPHONE as `OS X` and an ANDROID phone as `Linux`. Grouping by platform
 * would therefore tell a merchant that their mobile traffic is desktop traffic -- a confident wrong
 * answer in place of an honest blank, which is the one trade this workstream never makes. The
 * remedy taken was to DISCLOSE the blind spot in the hint; this test is why.
 *
 * If a future version of the library learns Windows, THIS TEST FAILS -- which is the point. The
 * hint would then be describing a limitation that no longer exists, and somebody has to go and
 * delete that sentence rather than leave a stale caveat on a merchant's screen.
 */
final class VisitorDetectorPremiseTest extends TestCase
{
    /**
     * The driver reads the request's own `User-Agent`, so the agent is set on the request the
     * container holds rather than passed in.
     */
    private function detect(string $userAgent): array
    {
        request()->headers->set('User-Agent', $userAgent);

        $driver = new JenssegersAgent(request());

        return [
            'device' => (string) $driver->device(),
            'platform' => (string) $driver->platform(),
        ];
    }

    /**
     * A PLAIN LOOP RATHER THAN A DATA PROVIDER. PHPUnit 11 deprecates the `@dataProvider`
     * doc-comment form and warns on every run; the attribute form would work, but five cases that
     * share one assertion do not need the machinery, and a loop keeps the measured table and the
     * claim it supports in one place.
     *
     * @return array<string, array{device: string, platform: string}>
     */
    private static function measured(): array
    {
        return [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                => ['device' => '', 'platform' => 'Windows'],
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                => ['device' => '', 'platform' => 'Linux'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'
                => ['device' => 'Macintosh', 'platform' => 'OS X'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
                => ['device' => 'iPhone', 'platform' => 'OS X'],
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'
                => ['device' => 'Pixel', 'platform' => 'Linux'],
        ];
    }

    public function test_the_detector_reports_what_the_disclosure_says_it_reports(): void
    {
        foreach (self::measured() as $userAgent => $expected) {
            $actual = $this->detect($userAgent);

            $this->assertSame($expected['device'], $actual['device'], sprintf(
                'The device detector changed behaviour for "%s". The device breakdown hint on '
                . '/analytics tells merchants that desktop visits appear as not recorded because '
                . 'the library has no rule for them; if that is no longer true the sentence is a '
                . 'stale caveat and must be rewritten. Got device "%s", platform "%s".',
                $userAgent,
                $actual['device'],
                $actual['platform']
            ));

            $this->assertSame($expected['platform'], $actual['platform'], sprintf(
                'The platform detector changed behaviour for "%s". Got platform "%s".',
                $userAgent,
                $actual['platform']
            ));
        }
    }

    /**
     * THE WINDOWS AND LINUX BLIND SPOT, stated as its own claim rather than left inside a table.
     *
     * This is the sentence the hint makes, so it gets its own failing condition: if either of these
     * ever returns a non-empty device, the disclosure is wrong and the test says which one.
     */
    public function test_the_two_commonest_desktop_platforms_have_no_device_rule_at_all(): void
    {
        foreach (['Windows NT 10.0; Win64; x64' => 'Windows', 'X11; Linux x86_64' => 'Linux'] as $fragment => $name) {
            $actual = $this->detect('Mozilla/5.0 (' . $fragment . ') AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

            $this->assertSame('', $actual['device'], sprintf(
                '%s now resolves to the device "%s". The /analytics device hint says the detector '
                . 'does not identify it at all, which is now false.',
                $name,
                $actual['device']
            ));
        }
    }

    /**
     * AND THE REASON THE REVIEW'S ALTERNATIVE WAS REFUSED, pinned so nobody re-proposes it from
     * the plausible-sounding direction.
     *
     * `platform()` has Windows and Linux rules, so swapping the grouping column looks like a free
     * upgrade. It is not: a phone's platform is its KERNEL, so every iPhone is filed as `OS X` --
     * identical to a MacBook -- and every Android as `Linux` -- identical to a Linux desktop. The
     * breakdown would then report mobile traffic as desktop traffic with total confidence.
     */
    public function test_platform_cannot_replace_device_because_phones_report_desktop_platforms(): void
    {
        $iphone = $this->detect('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
        $mac = $this->detect('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15');

        $this->assertSame($mac['platform'], $iphone['platform'],
            'An iPhone and a Mac no longer share a platform string. If the library can now tell '
            . 'them apart, grouping /analytics by platform may finally be safe -- re-measure '
            . 'before changing it, and update the hint.');

        $android = $this->detect('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36');
        $linux = $this->detect('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertSame($linux['platform'], $android['platform'],
            'An Android phone and a Linux desktop no longer share a platform string.');
    }
}
