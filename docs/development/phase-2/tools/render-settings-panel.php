<?php
/**
 * Renders ONE settings panel through the REAL application, into a standalone page carrying the
 * real admin stylesheets, so a browser can audit and screenshot it.
 * =================================================================================================
 *
 * WHY THIS EXISTS AND WHAT IT IS NOT. `shoot-settings-live.cjs` drives the real settings screen by
 * clicking each tab, which is the strongest evidence available: real route, real auth, real AJAX.
 * It cannot reach the OPERATOR-ONLY tabs in a preview container, and the reason is worth recording
 * because it is not a permission problem:
 *
 *     app/Http/Middleware/XSS.php redirects EVERY `super admin` to the Laravel Updater whenever
 *     any migration file is missing from the `migrations` table. The preview database is built by
 *     `database/seed-p2dev.php` on top of the first-party migrations, and all 70 WorkDo modules
 *     register as active, so 96 vendored module migrations count as pending and the operator
 *     cannot open ANY admin page -- settings included.
 *
 * Running 96 vendored module migrations on SQLite to get a screenshot is not a trade worth making,
 * and editing `packages/workdo/` is out of bounds. So the operator-only panels are rendered here
 * instead, and the evidence is labelled for what it is:
 *
 *   PROVES      the panel's own markup and the real stylesheets, in a real browser: accessible
 *               names, `label[for]` resolution, absence of credential values, absence of
 *               pre-design-system classes, and measured contrast in all four configurations.
 *   DOES NOT    the route, the permission gate, the AJAX round trip, the tab rail, or any
 *               JavaScript the settings screen loads.
 *
 * Unlike `docs/design-system/tools/render-preview.php`, this boots Laravel and uses REAL Blade, so
 * `Form::model`, `@selected`, `@php` blocks with arrow functions and the `x-ss.*` components all
 * behave as they do in production. That matters: the panels below use all four.
 *
 * usage: php render-settings-panel.php <panel> <en|ar> <light|dark> <out.html>
 *        panel is a key of PANELS below, e.g. `storage`.
 */

$repo = dirname(__DIR__, 4);   // <repo>/docs/development/phase-2/tools -> <repo>
[$self, $panel, $locale, $theme, $out] = array_pad($argv, 5, null);
if (! $panel || ! $locale || ! $theme || ! $out) {
    fwrite(STDERR, "usage: render-settings-panel.php <panel> <en|ar> <light|dark> <out.html>\n");
    exit(2);
}

require $repo . '/vendor/autoload.php';
$app = require $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app()->setLocale($locale);

/*
 * The view data `SettingController::settingForm()` passes with each panel. `$setting` is the
 * key/value map of SAVED settings; the credential keys below carry OBVIOUSLY FAKE values on
 * purpose -- the whole point of the audit is that a stored credential must not reach the page, so
 * the fixture has to store one. Nothing here is a real secret, and nothing is read from the
 * database.
 */
$setting = [
    'storage_setting' => 's3',
    's3_key' => 'AKIA-FIXTURE-KEY', 's3_secret' => 'FIXTURE-S3-SECRET-DO-NOT-RENDER',
    's3_region' => 'eu-central-1', 's3_bucket' => 'fixture-bucket',
    's3_url' => 'https://fixture.example.test', 's3_endpoint' => 'https://s3.example.test',
    's3_storage_validation' => 'jpg,png,pdf', 's3_max_upload_size' => '4096',
    'wasabi_key' => 'WASABI-FIXTURE-KEY', 'wasabi_secret' => 'FIXTURE-WASABI-SECRET-DO-NOT-RENDER',
    'wasabi_region' => 'eu-central-2', 'wasabi_bucket' => 'fixture-wasabi',
    'wasabi_url' => 'https://wasabi.example.test', 'wasabi_root' => '/uploads',
    'wasabi_storage_validation' => 'jpg,webp', 'wasabi_max_upload_size' => '2048',
    'local_storage_validation' => 'jpg,png', 'local_storage_max_upload_size' => '1024',
    'chat_gpt_model' => 'gpt-4o',
    'cust_theme_bg' => 'off', 'cust_darklayout' => $theme === 'dark' ? 'on' : 'off',
    'color' => 'theme-3',
];

$PANELS = [
    'storage' => ['setting.storage_setting', fn () => ['file_type' => config('files_types')]],
    'chatgpt' => ['setting.chatgpt_setting', fn () => ['ai_key_settings' => [
        (object) ['id' => 11, 'key' => 'FIXTURE-OPENAI-KEY-A-DO-NOT-RENDER'],
        (object) ['id' => 12, 'key' => 'FIXTURE-OPENAI-KEY-B-DO-NOT-RENDER'],
    ]]],
    'style'   => ['setting.style_setting', fn () => []],
    'cookie'  => ['setting.cookie_setting', fn () => []],
    'cache'   => ['setting.cache_setting', fn () => []],
    'recaptcha' => ['setting.recaptcha_setting', fn () => []],
    'seo'     => ['setting.seo_setting', fn () => ['plan' => null, 'timezones' => config('timezones')]],
];

if (! isset($PANELS[$panel])) {
    fwrite(STDERR, "unknown panel [$panel]; known: " . implode(', ', array_keys($PANELS)) . "\n");
    exit(2);
}

/* `$errors` is shared by `ShareErrorsFromSession`, which is web-middleware and does not run here;
   several panels read it through `@error`. An empty bag is what a GET with no validation failure
   carries. */
view()->share('errors', new Illuminate\Support\ViewErrorBag());

/* The panels branch on the principal's `type`. These are operator panels, so an operator is the
   right principal -- and it is a transient in-memory model, never written. */
$operator = new App\Models\User();
$operator->id = 1;
$operator->type = 'super admin';
$operator->language = $locale;
Illuminate\Support\Facades\Auth::setUser($operator);

[$view, $extra] = $PANELS[$panel];
$body = view($view, array_merge(['setting' => $setting], ($extra)()))->render();

/* The REAL admin stylesheets, chosen the way `layouts/app.blade.php:116-124` chooses them:
   server-side, from theme and direction. Linked as file:// so the browser loads the same bytes
   production serves. */
$dir = in_array($locale, ['ar', 'he'], true) ? 'rtl' : 'ltr';
$dark = $theme === 'dark';
/* The four names are taken from the layout's own branches, which do NOT follow one pattern:
   RTL+dark is `rtl-style-dark.css` but RTL+light is `style-rtl.css`, the other way round. A
   guessed `rtl-style.css` does not exist, and the precondition check below is what caught that
   rather than a page of unstyled markup being audited and passing. */
$sheet = $dir === 'rtl'
    ? ($dark ? 'rtl-style-dark.css' : 'style-rtl.css')
    : ($dark ? 'style-dark.css' : 'style.css');

$assets = $repo . '/public/assets/css';
$links = [];
$custom = $dir === 'rtl' ? 'rtl-custom.css' : 'custom.css';
foreach ([$assets . '/' . $sheet,
          $repo . '/public/css/' . $custom,
          $repo . '/public/css/store-syria-ds.css'] as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "MISSING STYLESHEET: $path -- the audit would measure unstyled markup\n");
        exit(3);
    }
    $links[] = '<link rel="stylesheet" href="file://' . $path . '">';
}

/* `data-ss-theme`, NOT `data-theme`. The design system's dark palette is declared under
   `[data-ss-theme="dark"]` and `layouts/app.blade.php:69` is what sets it. An earlier version of
   this file wrote `data-theme="dark"`, so every "dark" page it produced carried the dark ADMIN
   stylesheet over the LIGHT design-system palette -- and the contrast pass then reported
   `.ss-choice__label` at 3.84:1, which was an artefact of this file rather than a defect in the
   component. `data-ss-theme-source="host"` goes with it, exactly as the layout sets them. */
$html = '<!doctype html><html lang="' . htmlspecialchars($locale) . '" dir="' . $dir . '"'
    . ($dark ? ' data-ss-theme="dark" data-ss-theme-source="host"' : '') . '><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . htmlspecialchars($panel) . ' ' . $locale . ' ' . $theme . '</title>'
    . implode('', $links)
    /* The body class the layout really sets: `layouts/app.blade.php:320` is
       `<body class="{{ $themeColor ?? 'theme-3' }}">` and nothing else -- no `dark-mode`, which
       an earlier version of this file invented. Inventing a class means measuring rules that do
       not apply in production, in either direction. */
    . '</head><body class="' . htmlspecialchars($setting['color'] ?? 'theme-3') . '">'
    . '<div class="dash-container"><div class="dash-content"><div class="row"><div class="col-12">'
    /* The id the audit reads, so one audit function serves both this and the live screen. */
    . '<div id="pills-tabContent">' . $body . '</div>'
    . '</div></div></div></div></body></html>';

if (! is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, $html);
printf("%s %s/%s -> %s (%d bytes, sheet %s)\n", $panel, $locale, $theme, $out, strlen($html), $sheet);
