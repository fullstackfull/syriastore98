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
     * `mimes:` RATHER THAN `mimetypes:`, which is the "real type and content verification, not the
     * name alone" the decision requires. Laravel's `mimes` rule resolves the file through Symfony's
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
        'rules' => ['mimes:mp4,webm,mov', 'max:51200'],
    ],

    'downloadable_product' => [
        /*
         * OFF, BY THE OWNER'S GATE. Flip this only when F217 is closed — the exposure report
         * (`php artisan downloads:exposure-report`) must show PUBLICLY REACHABLE: 0 against the
         * real database — or a proven safe containment is in place.
         */
        'enabled' => env('UPLOAD_DIGITAL_TYPES_ENABLED', false),
        'rules' => [
            'mimes:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg',
            'max:51200',
        ],
    ],

];
