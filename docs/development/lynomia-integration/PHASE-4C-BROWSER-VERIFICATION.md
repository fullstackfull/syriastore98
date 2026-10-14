# Phase 4C — browser verification of the Automation Studio and Flow Builder

Run in **Chromium 1194 via Playwright**, headless, against the real Blade views rendered by the
real application with the real translations and the real route table. Not a description of what
the code should do: 44 assertions against a running page, four of which failed the first time and
found four defects that are fixed in the same commit.

## How it was run, and what that does and does not cover

`php /tmp/.../render.php` boots the Laravel application, points the view finder at a stand-in
`layouts.app`, and renders `merchant.automation` and `merchant.builder` for `en` and `ar`. The
stand-in layout loads **the dashboard's own** `jquery-3.6.0.min.js`, `popper.min.js`,
`bootstrap.min.js` and `style.css` / `style-rtl.css` from `public/`, sets `<html dir>` exactly as
`layouts/app.blade.php` does, and nothing else. The four pages are served by `php -S` and driven
by Playwright, with `page.route()` answering `/lynomia/automation/api/**` from fixtures.

**Covered:** the markup, the stylesheet, every line of the page's JavaScript, the real
translations, the real `dir` handling, pointer and touch input, keyboard input, and the request
bodies the page actually sends.

**Not covered here, covered by PHPUnit instead:** routing, authentication, the permission
middleware, tenancy, and the BFF's own projections — 36 tests in
`tests/Feature/Lynomia/LynomiaAutomationStudioTest.php` and `LynomiaFlowBuilderTest.php`. The
dashboard chrome (sidebar, header) is the same one Phase 2's inbox was verified against in A3 and
is not re-verified here.

**Not covered by anything yet:** a live Lynomia. Every API response in this run is a fixture
shaped to match the controller's own projections.

## What the browser found

Four defects, all in code that read correctly and all invisible to PHPUnit:

| # | Defect | Why no PHP test could see it |
|---|---|---|
| 1 | **Blade ate the variable token.** The picker builds a `{{ name }}` token to insert into a message. Written literally, Blade compiled it as one of its own echo tags and the picker inserted `+ name +` into the merchant's text. Written with Blade's `@` escape, the `@` survived into the message. The braces are now concatenated so Blade never sees a tag. | The defect is in what Blade emits, and the emitted page is only executed by a browser. |
| 2 | **Connecting two steps with a mouse did nothing.** `setPointerCapture` retargets every later pointer event to the capturing element, so `event.target` on `pointerup` was the canvas and never the node dropped on. Resolved by position with `elementFromPoint` now. | It needs a real pointer-capture implementation. |
| 3 | **Delete removed the wrong step.** Tab moves focus without selecting, so a keyboard user who tabbed to a step and pressed Delete removed whatever they had last *clicked*. The keydown handler now acts on the focused step. | It needs real focus management. |
| 4 | **The host's dictionary hijacked four Arabic words.** The module's JSON translations share a flat namespace with the host's own 2,848-key `resources/lang/ar.json`, and the host wins: `Live` rendered as **حياً** ("alive"), `Off` as **أطفئ** (the imperative "turn it off!"), and Yes/No carried trailing dots from another screen. Those four are now a namespaced PHP group the host cannot override. | Both files are valid and each is correct on its own; only the rendered page shows which one won. |

Each is covered by a regression test — the first and the last in
`LynomiaFlowBuilderTest`, the middle two by the browser run itself.

## The 44 assertions

| | |
|---|---|
| ✅ | canvas draws every node of the loaded graph — `3 nodes` |
| ✅ | canvas draws the edges — `1 edges` |
| ✅ | a Buttons node grows one handle per choice plus its extras — `4 handles` |
| ✅ | a node with a validation error is marked on the canvas |
| ✅ | the problem list is shown and is clickable — `1 problems` |
| ✅ | clicking a palette step adds it to the canvas — `3 -> 4` |
| ✅ | the configuration form is built from the served fields — `Message* | Expected answer | Keywords | Keep the answer as | How many tries | If the answer is not understood | Give up after (minutes)` |
| ✅ | a conditionally required field becomes required when its trigger is chosen |
| ✅ | a number field carries the server limits |
| ✅ | typing in the form updates the node on the canvas — `What is your order number?` |
| ✅ | the variable picker inserts an allow-listed token — `What is your order number?{{ contact.name }}` |
| ✅ | dragging from an output to a step connects them — `1 -> 2` |
| ✅ | an unsaved change is announced |
| ✅ | a node can be dragged to a new place — `translate(140,420) -> translate(260,510)` |
| ✅ | arrow keys nudge the focused step — `translate(260,510) -> translate(270,510)` |
| ✅ | Enter opens the focused step for configuration |
| ✅ | Delete removes the focused step |
| ✅ | the start step is refused rather than removed |
| ✅ | saving sends the graph and the lock_version it loaded — `{"sent":1,"lock":5,"nodes":3}` |
| ✅ | a saved draft is reported and the dirty mark clears |
| ✅ | the next save carries the token the server handed back — `lock=6` |
| ✅ | a lost race opens the conflict dialog rather than losing the work |
| ✅ | the merchant work is still on screen while they choose — `3 nodes` |
| ✅ | "keep mine" re-saves against the token the server returned — `lock=11` |
| ✅ | a publish that fails validation says so and marks the step — `This automation cannot be published yet:` |
| ✅ | Test Mode shows the transcript — `hi | How can we help?` |
| ✅ | Test Mode offers the choices the flow sent — `Track order` |
| ✅ | the page is right to left in Arabic |
| ✅ | the palette is in Arabic — `النهاية | إرسال رسالة | طرح سؤال` |
| ✅ | the panels follow the reading direction in RTL — `palette x=1196 stage x=348` |
| ✅ | the canvas coordinates are untouched by direction — `translate(140,40)` |
| ✅ | the configuration form is in Arabic — `الرسالة* | الإجابة المتوقعة | الكلمات المفتاحية | حفظ الإجابة باسم | عدد المحاولات | إذا لم تُفهم الإجابة | التوقف بعد (دقائق)` |
| ✅ | no horizontal overflow in Arabic at desktop width — `0px` |
| ✅ | no horizontal page scroll on a 390px phone — `0px` |
| ✅ | the panels stack above the canvas on a phone — `palette bottom=421 stage top=421` |
| ✅ | a tap selects a step on a touch screen |
| ✅ | the toolbar buttons are big enough to tap — `31,31,31` |
| ✅ | the Studio lists the account flows — `2 cards` |
| ✅ | a live flow and a disabled one read differently — `مُفعَّلة / متوقفة` |
| ✅ | the ceiling disables the new button rather than letting it fail |
| ✅ | the ceiling is explained |
| ✅ | the usage meter reflects the allowance — `100%` |
| ✅ | no horizontal overflow on the Studio in Arabic — `0px` |
| ✅ | no uncaught JavaScript error on any page |
## Screenshots

Taken during the run; not committed (they are large and reproducible):
`shot-builder-en.png`, `shot-builder-ar.png`, `shot-builder-mobile-ar.png`, `shot-studio-ar.png`.

## Direction, deliberately

The chrome flips and the canvas does not. A left-to-right graph would have to be mirrored for
Arabic, and mirroring means either rewriting every stored `x` on load — so the same flow has two
different graphs — or drawing it backwards from what is saved. The flow therefore runs **top to
bottom**, which reads the same in both directions; only the panels around it flip, which
Bootstrap's logical utilities do on their own. The assertion `the canvas coordinates are
untouched by direction` pins that: `start` is at `translate(140,40)` in Arabic exactly as in
English.
