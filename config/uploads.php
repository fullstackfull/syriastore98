<?php

return [

    /*
     * F190 / OWNER DECISION D-29.5 — the approved per-field upload lists.
     *
     * "APPROVED IN PRINCIPLE, SUBJECT TO SAFE FILE DELIVERY", with one hard gate:
     *
     *   "Do not enable the digital-type widening before F217 is closed, or a proven safe
     *    containment is applied."
     *
     * F217 is NOT closed. `PaidDownloadController` closes the path for every download served from
     * now on, but the files that ALREADY exist remain reachable at their public URLs, because paid
     * downloadables and ordinary product images are written to the same directory by the same
     * `Utility::upload_file()` call — so no path rule denies one without denying the other, and the
     * decision requires authorised public media to keep working. Containing them means moving the
     * files and rewriting their database paths, which is a production data write.
     *
     * SO THE TWO HALVES ARE DELIBERATELY NOT SYMMETRIC, and the asymmetry is the owner's gate
     * rather than caution of mine:
     *
     *   - `preview_video` IS enabled. A preview video is PUBLIC by design — it is shown on the
     *     product page — so F217's question ("can someone download paid goods without an order")
     *     does not arise for it. The gate's wording is specific: "for digital products".
     *   - `downloadable_product` is NOT enabled. Widening what a merchant may sell as a paid
     *     download, while the delivered files are still reachable without an order, would increase
     *     the value of the exposure F217 has not yet contained. Flipping `digital_types_enabled`
     *     to true is the one action that enables it, and it belongs to whoever closes F217.
     *
     * NEITHER LIST CONTAINS `svg`, AND THAT IS THE SVG CONTAINMENT THE DECISION ASKED FOR — as far
     * as it can be taken without a wholesale change. The platform-wide list
     * (`local_storage_validation`, default `jpg,jpeg,png,csv,svg,pdf`) still permits SVG for
     * product IMAGES, and an SVG can carry `<script>` served same-origin. Removing it there would
     * change the rules for every merchant and every WorkDo upload at once, which the decision
     * forbids, and would break any merchant using an SVG logo today. What IS done here: the two
     * fields that take their own list can no longer receive one. What remains is recorded as F217's
     * sibling exposure and needs either a business decision about SVG or the same
     * serve-through-a-controller treatment.
     *
     * EVERY LIST CARRIES ITS OWN `max:`, and that is not style. `Utility::upload_file()` REPLACES
     * the whole rule array when `$custom_validation` is non-empty (`Utility.php:377-385`) rather
     * than merging, so a list without `max:` silently removes the size cap. The decision says so
     * explicitly: "include max with any custom_validation; do not drop the size rule when
     * replacing the Rule Array."
     *
     * `mimes:` AND `extensions:` TOGETHER, AND THE SECOND ONE IS A CORRECTION. This file first
     * carried `mimes:` alone and claimed it delivered "real verification of the type and content,
     * not just the name". The CONTENT half was true. **THE NAME HALF WAS NOT ADDRESSED AT ALL, AND
     * THE NAME IS WHAT THE WEB SERVER ACTS ON** — found by an independent review and then measured:
     *
     *     a file with valid MP4 magic bytes, named `clip.html`, `clip.svg`, `clip.js` or
     *     `clip.xhtml`, PASSED `mimes:mp4,webm,mov`.
     *
     * `mimes:` compares the type GUESSED FROM THE BYTES against the named extensions; it does not
     * look at the client's extension at all. Laravel's own `shouldBlockPhpUpload` catches
     * `php/phtml/phar`, which is why those were refused and the gap looked closed — but nothing
     * stopped `.html` or `.svg`. And the stored name keeps the caller's extension:
     * `ProductController:787` builds `'video_' . time() . rand() . '.' . $ext` where `$ext` is
     * `getClientOriginalExtension()`. The file lands in `uploads/<store>/video`, which is under the
     * document root through the `public/uploads` symlink. So MP4 bytes under an `.html` name was
     * **stored XSS on the store's own origin**, reachable by a merchant against their own shoppers.
     *
     * `extensions:` validates the CLIENT-SUPPLIED EXTENSION against the same list, so both halves
     * have to agree. Measured after the change: `.html`, `.svg`, `.htaccess` and `.js` refused;
     * `.mp4` and `.MP4` accepted, so the fix is case-insensitive and does not break a normal upload.
     *
     * (`.htaccess` was already defanged by the `video_` prefix — Apache only honours a file named
     * exactly `.htaccess` — but it is refused now rather than relying on that.)
     *
     * `mimetypes:` is deliberately NOT used for the content half: it matches the MIME string the
     * CLIENT sent, which a client controls. Laravel's `mimes` rule resolves the file through Symfony's
     * MIME guesser, which reads the file's MAGIC BYTES and then requires the guessed type to match
     * one of the named extensions — so a `.mp4` that is really a ZIP is refused. `mimetypes:` would
     * match the MIME string the CLIENT sent, which a client controls.
     *
     * THE 50 MB CEILING IS CONDITIONAL AND THE CONDITION IS NOT MET IN THIS CONTAINER. Measured:
     * `upload_max_filesize = 2M`, `post_max_size = 8M`. PHP refuses a larger upload before Laravel's
     * validator is reached, so the video field remains unusable for anything but a very short clip
     * until an operator raises those plus the web server's own body limit. That is infrastructure,
     * documented in the F190 recommendation, and not something this file can fix.
     */

    'preview_video' => [
        'enabled' => true,
        'rules' => ['mimes:mp4,webm,mov', 'extensions:mp4,webm,mov', 'max:51200'],
    ],

    'downloadable_product' => [
        /*
         * OFF, BY THE OWNER'S GATE. Flip this only when F217 is closed — the exposure report
         * (`php artisan downloads:exposure-report`) must show PUBLICLY REACHABLE: 0 against the
         * real database — or a proven safe containment is in place.
         */
        /*
         * PARSED STRICTLY, because `env()` ALONE OPENED THIS GATE ON "no".
         *
         * Laravel's `Env::get` converts only the literals true/false/null/empty and their
         * parenthesised forms; everything else comes back as a RAW STRING. `UploadRules` then tested
         * `empty($config['enabled'])`, and `empty('no')` is FALSE — so measured:
         *
         *     UPLOAD_DIGITAL_TYPES_ENABLED=no      => gate OPEN
         *     UPLOAD_DIGITAL_TYPES_ENABLED=off     => gate OPEN
         *     UPLOAD_DIGITAL_TYPES_ENABLED=maybe   => gate OPEN
         *
         * An operator typing `no` to keep it shut would have opened it. This is the owner's HARD
         * condition on F217, so it must open only on an unambiguous yes, and anything else — a
         * typo, a disabling word, an empty value — leaves it closed. Found by an independent review.
         */
        'enabled' => in_array(
            strtolower(trim((string) env('UPLOAD_DIGITAL_TYPES_ENABLED', ''))),
            ['1', 'true', 'yes', 'on'],
            true
        ),
        'rules' => [
            'mimes:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg',
            'extensions:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg',
            'max:51200',
        ],
    ],

];
