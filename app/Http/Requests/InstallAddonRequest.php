<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use ZipArchive;

/**
 * SEC-F5 -- authenticated zip-upload remote code execution.
 *
 * AddonController::ThemeInstall and ModuleController::install extracted an
 * attacker-supplied archive straight into the running application with no
 * authorization at all. The contrast inside AddonController is damning: :21 and
 * :153 restrict the methods that merely LIST themes to 'super admin', while the
 * method that unpacks an uploaded zip into the codebase was open to every
 * authenticated user -- and public self-registration grants type=admin, so the
 * path was anonymous-visitor-to-RCE in two HTTP requests.
 *
 * This request carries the upload validation. Authorization is ALSO asserted as
 * the first statement of each controller method, so the control does not depend
 * solely on route grouping -- which is precisely what failed in SEC-F2.
 */
class InstallAddonRequest extends FormRequest
{
    /**
     * File extensions permitted inside an addon/module archive.
     */
    public const ALLOWED_ENTRY_EXTENSIONS = [
        'php', 'json', 'blade.php', 'js', 'css', 'scss', 'html', 'txt', 'md',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'xml', 'yml', 'yaml', 'lock', 'map', 'vue', 'mo', 'po', 'csv',
    ];

    public function authorize(): bool
    {
        return Gate::allows('manage-platform-addons');
    }

    public function rules(): array
    {
        return [
            // mimes:zip needs the fileinfo extension, verified present via php -m.
            'file' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ];
    }

    /**
     * Reject an archive whose entries would escape the extraction directory, or
     * which carries a file type an addon has no business shipping.
     *
     * Returns null when the archive is acceptable, or a human-readable reason.
     * Static so both controllers share one implementation rather than each
     * growing its own copy.
     */
    public static function archiveRejectionReason(string $archivePath): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            return __('The uploaded file could not be read as a zip archive.');
        }

        $count = $zip->numFiles;

        if ($count === 0) {
            $zip->close();
            return __('The zip file is empty or does not contain any valid files.');
        }

        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || $name === '') {
                $zip->close();
                return __('The zip file contains an unreadable entry.');
            }

            // Normalise separators so Windows-built archives are checked too.
            $normalised = str_replace('\\', '/', $name);

            // Path traversal and absolute paths.
            if (str_starts_with($normalised, '/')
                || preg_match('#^[A-Za-z]:#', $normalised)
                || $normalised === '..'
                || str_starts_with($normalised, '../')
                || str_contains($normalised, '/../')
                || str_ends_with($normalised, '/..')
            ) {
                $zip->close();
                return __('The zip file contains an entry that would be written outside the install directory.');
            }

            // Directory entries need no extension check.
            if (str_ends_with($normalised, '/')) {
                continue;
            }

            $basename = basename($normalised);

            // Reject dotfiles that could overwrite configuration.
            if (str_starts_with($basename, '.')) {
                $zip->close();
                return __('The zip file contains a hidden file, which is not permitted.');
            }

            if (!str_contains($basename, '.')) {
                $zip->close();
                return __('The zip file contains an entry with no file extension.');
            }

            $extension = strtolower(substr($basename, strrpos($basename, '.') + 1));

            if (!in_array($extension, self::ALLOWED_ENTRY_EXTENSIONS, true)) {
                $zip->close();
                return __('The zip file contains a file type that is not permitted in an addon.');
            }
        }

        $zip->close();

        return null;
    }

    /**
     * A filesystem-safe directory name derived from a client-supplied filename.
     *
     * ModuleController took getClientOriginalName() and did
     * str_replace('.zip', '', ...) -- not a suffix match -- then interpolated
     * the result straight into 'packages/workdo/' . $fileName with no
     * basename(), so '../../public/x.zip' escaped the install directory.
     */
    public static function safeDirectoryName(string $clientFilename): string
    {
        $name = basename(str_replace('\\', '/', $clientFilename));

        // Strip the extension only if it really is the suffix.
        if (str_ends_with(strtolower($name), '.zip')) {
            $name = substr($name, 0, -4);
        }

        // Allow only characters that cannot alter a path.
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name) ?? '';

        return $name;
    }
}
