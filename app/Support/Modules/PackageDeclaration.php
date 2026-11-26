<?php

namespace App\Support\Modules;

/**
 * WHAT AN UPLOADED PACKAGE SAYS ABOUT ITSELF, READ WITHOUT RUNNING A LINE OF IT.
 *
 * MARKET-002 asks for "security, compatibility, versioning and permission reviews". Three of those
 * four need facts about the package, and every one of those facts is in the package -- which is
 * code that MARKET-004 forbids executing. So every reader here is a STATIC TEXT READ:
 *
 *   - the manifest goes through `json_decode`, never `include`;
 *   - the permission names come out of the seeder's source by regular expression, NOT by calling
 *     the seeder, which would be the exact thing the prohibition rules out. `Artisan::call(
 *     'package:seed X')` -- which `ModuleController::enable()` does -- executes the package.
 *
 * THE PERMISSION READ IS DELIBERATELY ALLOWED TO BE INCOMPLETE, and saying so is the point.
 * It recognises the shape all 70 vendored modules use (`$permissions = [ 'Manage X', ... ];` inside
 * a `PermissionTableSeeder`), and a package that declares its permissions another way will come
 * back with fewer names or none. That is honest for a review INPUT -- a reviewer is told what was
 * found and a `null` is distinguishable from an empty list -- and it is why nothing in this
 * application makes an authorization decision from the result. `ModuleTrust` does not read it.
 *
 * CLAUDE.md's census rule applies and is followed: a list of DANGEROUS spellings is always short by
 * the one nobody thought of, so where a judgement is made it is made by enumerating the SAFE set.
 * Here no judgement is made at all -- these are facts for a person to read.
 */
final class PackageDeclaration
{
    /** The manifest of a module directory, or an empty array when there is none to read. */
    public static function manifest(string $directory): array
    {
        $path = $directory . '/module.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The declared version, as a short label rather than a number.
     *
     * MEASURED, and it is why this is a string: all 70 vendored manifests carry `version` as a JSON
     * NUMBER (`"version":1.6`), and `App\Classes\Module::find()` reads it into `$this->version`
     * where `resources/views/module/index.blade.php:220` prints it through `sprintf('%.1f')`. So
     * `1.6` and `1.60` are one value to the product today and a semantic version would be rounded
     * to nonsense by that view. Keeping the label verbatim loses nothing a reviewer needs and keeps
     * a package that declares `"2.0.1"` readable.
     *
     * NOTHING IN THE APPLICATION COMPARES TWO VERSIONS. Measured 2026-11-26: `version` is written
     * in `Module::find()` (`app/Classes/Module.php:59`, `:72`, `:85`) and read in exactly one place
     * -- that view -- so there is no upgrade path, no minimum-platform-version check and no
     * downgrade refusal anywhere. Recording the declared version at upload time is the first half
     * of MARKET-002's "versioning"; what the platform should DO about a version is a rule nobody
     * has stated, and one is not invented here.
     */
    public static function declaredVersion(string $directory): ?string
    {
        $manifest = self::manifest($directory);

        if (! array_key_exists('version', $manifest)) {
            return null;
        }

        $version = $manifest['version'];

        if (is_float($version) || is_int($version)) {
            $version = (string) $version;
        }

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version === '' ? null : mb_substr($version, 0, 40);
    }

    /**
     * The modules this one says it needs, from the manifest's `parent_module`.
     *
     * This is MARKET-002's "compatibility" in the only form the platform already understands:
     * `ModuleController::Check_Parent_Module()` refuses to ENABLE a module whose parents are not
     * active. Recording the list at upload time means a reviewer sees the dependency before the
     * enable screen does, and sees it for a package that was never enabled.
     *
     * @return list<string>
     */
    public static function declaredDependencies(string $directory): array
    {
        $parents = self::manifest($directory)['parent_module'] ?? [];

        if (! is_array($parents)) {
            return [];
        }

        $out = [];

        foreach ($parents as $parent) {
            if (is_string($parent) && trim($parent) !== '') {
                $out[] = trim($parent);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The permission names the package's seeders declare, read out of their SOURCE.
     *
     * Returns null when no seeder source could be read at all -- which a reviewer must be able to
     * tell apart from "a seeder was read and declared nothing".
     *
     * @return list<string>|null
     */
    public static function declaredPermissions(string $directory): ?array
    {
        $seeders = glob($directory . '/src/database/seeders/*.php') ?: [];

        if ($seeders === []) {
            return null;
        }

        $names = [];
        $read  = false;

        foreach ($seeders as $seeder) {
            $source = @file_get_contents($seeder);

            if ($source === false) {
                continue;
            }

            $read = true;

            /*
             * `$permissions = [ ... ];` -- the shape every vendored PermissionTableSeeder uses.
             * The inner capture is then split on quoted strings, so a trailing comma, a comment or
             * odd whitespace inside the literal costs nothing.
             */
            if (! preg_match_all('/\$permissions\s*=\s*\[(.*?)\]\s*;/s', $source, $blocks)) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (! preg_match_all('/([\'"])((?:(?!\1).)*)\1/s', $block, $literals)) {
                    continue;
                }

                foreach ($literals[2] as $literal) {
                    $literal = trim($literal);

                    if ($literal !== '') {
                        $names[] = $literal;
                    }
                }
            }
        }

        if (! $read) {
            return null;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
