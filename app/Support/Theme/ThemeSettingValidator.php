<?php

namespace App\Support\Theme;

/**
 * PHASE 03 M3-C — TYPE-AWARE VALIDATION OF A THEME SETTING'S VALUE.
 * ================================================================================================
 *
 * WHAT THIS REPLACES, AND WHY IT CANNOT DELEGATE TO THE EXISTING MIDDLEWARE
 * ------------------------------------------------------------------------------------------------
 * The M3 directive requires that theme configuration hold "only allowed settings and values, not
 * arbitrary executable HTML or JavaScript". The `xss` middleware does not provide that, and the
 * project has already recorded why: `docs/discovery/11-RELEASE-READINESS.md` **S-09**, *"The 'XSS
 * middleware' provides no XSS protection and silently corrupts passwords."*
 *
 * Measured through `App\Http\Middleware\XSS` on theme-setting field names:
 *
 *     'Smart alerts for shoppers'   ->  'Smart s for shoppers'
 *     'Get javascript tips weekly'  ->  'Get  tips weekly'
 *     'Alert & Co <b>Bold</b>'      ->  UNCHANGED
 *     'Alerta Roja'                 ->  UNCHANGED
 *
 * The sweep is a case-sensitive `str_replace` of four substrings, so it damages ordinary lowercase
 * merchant copy while letting `Alert`, `JavaScript:` and `<b>Bold</b>` straight through. It is
 * neither a validator nor a sanitiser; it is a find-and-delete.
 *
 * THE RULE THIS APPLIES INSTEAD
 * ------------------------------------------------------------------------------------------------
 * Validation is driven by the type each theme DECLARES for the field, read from
 * `themes/<theme>/theme_json/` through `ThemeFieldCatalog`. Measured at M3-A: 956 declared settings
 * across the three shipped themes in exactly seven types, and of the 55 unescaped `{!! !!}` setting
 * outputs across 150 Blade files, naming 43 distinct settings, **every one is declared `text` (26)
 * or `textarea` (17)**. No `image`, `switch`, `slider`, `menu` or `date` value is ever emitted
 * unescaped. So constraining those two types reaches the whole persistent-output surface at its
 * source, which is a far smaller and more verifiable change than editing 55 Blade sites.
 *
 * REQUIREMENT 8, AND THE SHAPE THAT WOULD UNDO ALL OF IT
 * ------------------------------------------------------------------------------------------------
 * An unknown type is REFUSED before any dispatch. A review named the caller shape that quietly
 * reinstates the audited hole, and it is the one type-aware validation naturally reaches for:
 *
 *     match ($catalog->typeOf($theme, $name)) {
 *         'text', 'textarea' => $this->sanitise($value),
 *         default            => $value,          // <-- null lands here, UNSANITISED
 *     }
 *
 * `typeOf()` returns null for a field no theme declares, so a permissive `default` stores the raw
 * value for every unknown name. `validate()` below therefore refuses first and dispatches second,
 * and `assertTypeIsKnown()` exists so a future caller cannot reintroduce the pattern by accident.
 *
 * WHAT "DOES NOT CORRUPT LEGITIMATE CONTENT" MEANS HERE
 * ------------------------------------------------------------------------------------------------
 * This REFUSES a value it considers unsafe; it never silently rewrites one. A merchant who types
 * `Smart alerts for shoppers` gets their text stored verbatim -- that string contains no markup and
 * no scheme, so there is nothing to object to. A merchant who pastes a `<script>` block gets a
 * refusal naming the field, not a value quietly mangled into something they did not write. Silent
 * mutation is what S-09 is; an explicit refusal is a validator.
 *
 * Existing stored values are never retro-validated. The legacy corpus already contains markup
 * (`footer_description` = `<p>Legacy <b>HTML</b> already stored</p>`), and rewriting it would be a
 * destructive update of published settings, which the directive forbids. This gates WRITES.
 */
final class ThemeSettingValidator
{
    /** A refusal reason per rejected field, as `name => why`. */
    private array $errors = [];

    /**
     * Markup and scheme patterns refused in a free-text field.
     *
     * Case-insensitive and anchored on structure rather than on a word list, because a word list is
     * exactly what S-09 is. `alert` and `javascript` as WORDS are perfectly legitimate in shop copy
     * and are not mentioned here; `javascript:` as a URL SCHEME is not.
     */
    private const REFUSED_IN_FREE_TEXT = [
        '~<\s*/?\s*(script|iframe|object|embed|applet|form|meta|link|base|style|svg)\b~i'
            => 'contains an HTML tag that can execute or exfiltrate (<script>, <iframe>, <style> and similar)',
        '~<[a-z][^>]*\son[a-z]+\s*=~i'
            => 'contains an inline event handler attribute (onclick, onerror and similar)',
        /*
         * `javascript:` and `vbscript:` are never legitimate shop copy, so they are refused
         * wherever they appear. `\b` on the left so a word ENDING in them is not caught.
         */
        '~\b(?:javascript|vbscript)\s*:~i'
            => 'contains an executable URL scheme (javascript: or vbscript:)',

        /*
         * `data:` IS legitimate prose, and the first version of this rule refused it anywhere --
         * so "Big data: our approach" and even "metadata: see below" were rejected as XSS. That
         * had no left word boundary either, which is how `metadata` matched. A word list is what
         * S-09 is; a word list that eats ordinary copy is worse.
         *
         * A `data:` URL is only dangerous once it is an attribute or a CSS url(), which is a
         * context this can test for. Two precise rules replace one blunt one:
         */
        '~(?:=\s*["\']?|url\s*\(\s*["\']?)\s*data\s*:~i'
            => 'contains a data: URL in an attribute or url() position',
        '~^\s*data\s*:(?!\s)~i'
            => 'is itself a data: URL',
        '~&\#x?0*(?:6a|106|74|116)[;\s]~i'
            => 'contains a numeric character reference that decodes to a scheme prefix',
        '~\x00~'
            => 'contains a NUL byte',
    ];

    /** `image` values are stored paths, so they get the project's own path rules. */
    private const IMAGE_PATTERN = '~^[A-Za-z0-9._/-]*$~';

    /**
     * Validate a whole write against one theme's declared taxonomy.
     *
     * @param  array<string, mixed>  $payload  name => value, as posted
     * @return array<string, string>           the accepted subset, canonicalised
     */
    public function validate(string $theme, array $payload): array
    {
        $this->errors = [];
        $accepted = [];

        foreach ($payload as $name => $value) {
            $name = (string) $name;
            $type = ThemeFieldCatalog::typeOf($theme, $name);

            /*
             * REQUIREMENT 8. Refuse BEFORE dispatching on the type. An unknown name has no declared
             * type, so there is no rule that could apply to it and no safe default -- including
             * "store it unchanged", which is the audited hole.
             */
            if ($type === null) {
                $this->errors[$name] = ThemeFieldCatalog::editableFields($theme) === []
                    ? "theme '{$theme}' declares no editable fields at all, so no write is accepted"
                    : 'is not a field this theme declares';

                continue;
            }

            $problem = $this->checkValue($type, $value);

            if ($problem !== null) {
                $this->errors[$name] = $problem;

                continue;
            }

            $accepted[$name] = $value;
        }

        return ThemePayload::canonicalise($accepted);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function passed(): bool
    {
        return $this->errors === [];
    }

    /**
     * The guard that stops requirement 8 being undone later.
     *
     * A caller writing its own `match` on the type must call this first. It throws rather than
     * returning a boolean so that forgetting to check the return value is not a silent accept.
     */
    public static function assertTypeIsKnown(string $theme, string $name): string
    {
        $type = ThemeFieldCatalog::typeOf($theme, $name);

        if ($type === null) {
            throw new \InvalidArgumentException(
                "Theme '{$theme}' declares no field '{$name}'. Reject an unknown field BEFORE "
                . 'dispatching on its type: a permissive `default` arm stores the raw value and '
                . 'reinstates the hole this validator exists to close.');
        }

        return $type;
    }

    /** @return string|null  the reason it is refused, or null when acceptable */
    private function checkValue(string $type, mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return 'must be a string; a repeater arrives as a JSON string and stays one';
        }

        $string = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');

        if (strlen($string) > ThemePayload::MAX_VALUE_BYTES) {
            return 'is ' . strlen($string) . ' bytes, over the '
                . ThemePayload::MAX_VALUE_BYTES . '-byte ceiling';
        }

        return match ($type) {
            'text', 'textarea' => $this->checkFreeText($string),
            'switch'           => $this->checkSwitch($string),
            'image'            => $this->checkImage($string),
            'slider'           => $this->checkRepeater($string),
            'menu'             => $this->checkFreeText($string),
            'date'             => $this->checkDate($string),

            /*
             * NOT a permissive default. `ThemeFieldCatalog::KNOWN_TYPES` is the measured set of
             * types the shipped themes use; a type outside it is a theme declaring something this
             * validator has no rule for, and guessing is how an `image` or a repeater would end up
             * reaching an unescaped output without anyone deciding that it should.
             */
            default => "declares type '{$type}', which this validator has no rule for. Add a rule "
                . 'and decide whether that type can reach an unescaped output before allowing it',
        };
    }

    private function checkFreeText(string $value): ?string
    {
        foreach (self::REFUSED_IN_FREE_TEXT as $pattern => $why) {
            if (preg_match($pattern, $value) === 1) {
                return $why;
            }
        }

        return null;
    }

    private function checkSwitch(string $value): ?string
    {
        return in_array($value, ['0', '1', '', 'on', 'off'], true)
            ? null
            : "must be 0 or 1 (the themes' own defaults use 1/0)";
    }

    private function checkImage(string $value): ?string
    {
        if ($value === '') {
            return null;        // clearing an image is legitimate
        }

        if (preg_match(self::IMAGE_PATTERN, $value) !== 1) {
            return 'is a stored path and may contain only letters, digits, dot, underscore, '
                . 'hyphen and forward slash';
        }

        if (str_contains($value, '..')) {
            return 'is a stored path and must not traverse';
        }

        return null;
    }

    /**
     * A repeater value is a JSON string the storefront `json_decode()`s at render time.
     *
     * It is checked for parseability and for free-text safety of its LEAF STRINGS, then stored
     * BYTE-EXACTLY -- never re-encoded. Re-encoding would reorder keys and change escaping, so a
     * value byte-identical to what the merchant saved would come back different and every
     * `payload_hash` comparison would report a phantom change.
     */
    private function checkRepeater(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'is a repeater and must be valid JSON (' . $e->getMessage() . ')';
        }

        if (! is_array($decoded)) {
            return 'is a repeater and must be a JSON array or object';
        }

        $problem = null;

        array_walk_recursive($decoded, function ($leaf) use (&$problem) {
            if ($problem === null && is_string($leaf)) {
                $problem = $this->checkFreeText($leaf);
            }
        });

        return $problem === null ? null : 'contains an entry that ' . $problem;
    }

    private function checkDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        /*
         * Deliberately permissive. M3-A measured that the themes' OWN shipped defaults are not all
         * valid for their declared type -- `privacy_policy_create_date` ships a human-readable
         * string against a declared `date` (ms-epoch) type in all three themes. A strict rule here
         * would refuse a value the theme itself supplies. This checks only that it is not markup.
         */
        return $this->checkFreeText($value);
    }
}
