# F190 — recommended file-type lists for video and digital products, and what must be decided with them

> **Status: RECOMMENDATION ONLY. Nothing in this document has been implemented.** The owner's
> instruction, 2026-10-30: *"provide recommended extension lists for video and digital products, with
> MIME validation, content inspection, size limits and security. Do not widen the permitted file
> types without the list being approved."* So the lists below are proposals, the prerequisites are
> measured, and the code is unchanged.

---

## 1. What is wrong today, in one paragraph

`Utility::upload_file()` falls back to **one platform-wide list** when its `$custom_validation`
argument is empty: `local_storage_validation`, default **`jpg,jpeg,png,csv,svg,pdf`**
(`app/Models/Utility.php:62`). The same six extensions validate a product image, a sellable
download and a preview video. The two calls that need their own lists pass `[]`:
`ProductController.php:790` and `:1113` for `preview_video`, `:865` and `:1137` for
`downloadable_product`. So **a merchant cannot upload a video to the field named `preview_video`**,
and the refusal is reported rather than silent, which is why this is a usability defect and not a
security hole.

---

## 2. THREE THINGS THAT MUST BE DECIDED WITH THE LISTS, NOT AFTER THEM

These were measured while preparing the recommendation, and any one of them makes an approved list
useless or unsafe on its own.

### 2.1 A per-field list silently removes the size cap

`Utility.php:377-385`:

```php
if (count($custom_validation) > 0) {
    $validation = $custom_validation;          // REPLACES the whole array
} else {
    $validation = ['mimes:' . $mimes, 'max:' . $max_size];
}
```

`$custom_validation` **replaces** the rule array — it does not merge. So
`upload_file($request, 'preview_video', …, ['mimes:mp4,webm,mov'])` removes the `max:` rule along
with the old `mimes:`, and the only remaining limit is PHP's. **Every recommended list below
therefore carries its own `max:`**, and that is not a stylistic choice.

### 2.2 PHP's own cap makes a video list inert until infrastructure changes

Measured in this container:

| | |
|---|---|
| `upload_max_filesize` | **2M** |
| `post_max_size` | **8M** |
| the app's own default `max:` | `local_storage_max_upload_size`, default **2048** (KB) |

**A 2 MB ceiling is not a video field.** Whatever list is approved, a video upload fails at the PHP
layer before Laravel's validator sees it, and the merchant gets the generic refusal. Raising it is an
**ops change to `php.ini` / FPM / the web server** (and `client_max_body_size` if nginx fronts it),
not a code change, and it has memory and disk consequences this container cannot measure. **So the
video half of F190 has an infrastructure prerequisite, and approving the list without it changes
nothing a merchant can see.**

### 2.3 The real exposure is the SERVING model, not the extension list

**`public/uploads` is a symbolic link to `../uploads`** (verified: `public/uploads -> ../uploads`).
Uploaded files are served **directly by the web server** from the document root. There is no
controller in the path, so no `Content-Disposition: attachment`, no `X-Content-Type-Options:
nosniff`, and no entitlement check at download time —
`AccountProfileController::downloadLinkFor()` returns `get_file($path)`, which is a **URL**.

Three consequences, all live today and none introduced by this recommendation:

1. **`svg` is already in the shipped allow-list and is served same-origin.** An SVG can carry
   `<script>`. That is a stored-XSS vector in the current default list, before any widening.
2. **A downloadable product is protected by a guessable URL and nothing else.** The stored name is
   `rand(10, 100) . '_' . time() . '_' . $originalName` (`ProductController.php:864`) — **91
   possible prefixes × a one-second timestamp × a filename the product page often reveals.** Anyone
   who can guess it downloads paid content without an order.
3. **Any future list including an extension the web server executes or renders inline**
   (`php`, `phtml`, `phar`, `html`, `htm`, `xhtml`, `svg`, `xml`) turns an upload into code or
   script execution on the store's own origin.

**This is why the lists below are deliberately conservative and why §4 exists.** Recorded separately
as **F217** so it is tracked rather than buried in a recommendation.

---

## 3. The recommended lists

Each is given as the complete rule array to pass as `$custom_validation`, so the size cap cannot be
lost per §2.1. `mimes:` rather than `mimetypes:` **on purpose**: Laravel's `mimes` rule resolves the
file's type through Symfony's MIME guesser, which reads the file's **magic bytes** and then requires
the guessed type to match one of the named extensions. So `mimes:` already performs content
inspection — a `.mp4` that is really a ZIP is refused — whereas `mimetypes:` matches the literal
MIME string the client sent, which a client controls. The owner's "content inspection" is therefore
satisfied by `mimes:` and is **not** satisfied by trusting the extension.

### 3.1 `preview_video` — conservative

```php
['mimes:mp4,webm,mov', 'max:51200']        // 50 MB
```

| extension | why |
|---|---|
| `mp4` | H.264/AAC in an MP4 container; the only format every current browser plays |
| `webm` | VP8/VP9; the open-format fallback, native in Chrome and Firefox |
| `mov` | QuickTime. Included because **phones produce it** — an iPhone video is `.mov` — and a merchant filming a product on a phone is the common case. It is the same ISO base media container family as MP4. |

**Deliberately excluded:** `avi`, `wmv`, `flv`, `mkv`, `mpg`, `3gp` — legacy or
poorly-browser-supported containers that would upload successfully and then fail to play, which is a
worse merchant experience than a clear refusal. `ogv` excluded as effectively unused. `gif` excluded
because it is an image and already allowed by the image field.

**50 MB is a proposal and it depends entirely on §2.2.** It is a reasonable size for a short product
clip at phone quality; it is also 25× the current PHP ceiling.

### 3.2 `downloadable_product` — conservative, and it is the one that needs a decision rather than a default

```php
['mimes:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg', 'max:51200']    // 50 MB
```

| group | extensions | why |
|---|---|---|
| documents | `pdf`, `epub`, `docx`, `xlsx`, `pptx` | the common sellable document formats. The OOXML trio are ZIP containers and are **not** executed by the server. |
| archives | `zip` | the universal wrapper a merchant uses for anything else — fonts, design files, multi-file bundles. **One archive format only**, so there is one thing to reason about. |
| audio | `mp3`, `wav` | sellable audio |
| data | `csv` | already permitted today; kept so nothing a merchant sells now stops working |
| images | `png`, `jpg`, `jpeg` | a sellable image (a print, a template preview) |

**Deliberately excluded, each for a stated reason:**

| excluded | why |
|---|---|
| `svg` | **script-bearing and served same-origin** (§2.3). It is in today's list; this recommendation does **not** carry that forward, and removing it is a separate decision because a merchant may be relying on it for images. |
| `html`, `htm`, `xhtml`, `xml` | rendered inline on the store's own origin — stored XSS |
| `php`, `phtml`, `phar`, `cgi`, `pl`, `py`, `sh` | executable by a misconfigured handler |
| `exe`, `msi`, `dmg`, `apk`, `bat`, `ps1` | distributing executables from a storefront is a decision about what the platform is, not a file-type question. If merchants must sell software, the answer is `zip` plus an explicit policy, not these. |
| `rar`, `7z`, `tar`, `gz` | no reason beyond `zip`; each is another parser and another decompression-bomb surface |

**`zip` deserves its own sentence.** It is safe to *store* and safe to *serve as an attachment*, and
the platform never unzips it — I verified there is no extraction call on this path. The risk of a ZIP
is to whoever opens it, which is true of any file a store sells.

---

## 4. What I recommend be decided **before** either list is applied

In this order, because the first two change what the lists mean:

1. **Serve downloadable products through a controller, not a URL** — an authenticated route that
   checks the order entitlement, then streams with `Content-Disposition: attachment` and
   `X-Content-Type-Options: nosniff`. This single change makes the download list a question of
   merchant convenience rather than of platform security, and it closes the guessable-URL bypass in
   §2.3(2). Tracked as **F217**.
2. **Decide the size ceiling and have ops raise `upload_max_filesize` / `post_max_size` /
   `client_max_body_size` to match**, or accept that the video field stays unusable (§2.2).
3. **Decide `svg`** — keep it for images and accept the same-origin script risk, or drop it and find
   out which merchants were using it. Not mine to choose, and it is a live risk either way.
4. **Then** pass the approved arrays at the four call sites. That is a four-line change and it is the
   smallest part of this.

---

## 5. What this document does not claim

It does not claim the current behaviour is a security hole: `Utility::upload_file()` **fails
closed** — an unlisted type is refused and the refusal is reported (`$path_video['msg']`). The field
is unusable for video and **honest about it**. Nothing here is urgent in the way F215 or F216 were.

It also does not claim these lists are complete for Store Syria's merchants. They are conservative
defaults chosen to be defensible; the owner may know of formats their merchants sell today that
belong in them, and a list that is too narrow refuses real business. **That is exactly why this is a
recommendation and the code is untouched.**
