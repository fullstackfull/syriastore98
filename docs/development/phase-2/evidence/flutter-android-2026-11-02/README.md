# Flutter and Android verification — 2026-11-02

Directive E §7, executed in the release-integration container. **Every figure below was produced by
running the command named next to it in this container on 2026-11-02.** Where something could not
be run, this file says so and names the exact unresolved dependency instead of a figure.

| | |
|---|---|
| repository | `fullstackfull/StoreSyria_app98` |
| branch | `claude/loving-noether-f4qxdt` (release-integration) |
| head | `6a8213f7b88a3c610d056e0bc2d811998b8772f5` |
| Flutter | 3.47.7, channel stable, framework `abaf9c5237`, engine `d8a2d6c768` |
| Dart | 3.13.5 |
| SDK location | `/opt/fl/flutter` — **present in this container but not on `PATH`**, which is why a first look suggested Flutter was absent. It is not. |

---

## 1. What was executed, and what it returned

| item | command | result |
|---|---|---|
| dependency resolution | `flutter pub get` | **Got dependencies.** 130 packages have newer versions held back by dependency constraints; **no resolution failure and no override**. |
| static analysis | `flutter analyze` | **247 issues: 247 `info`, 0 `warning`, 0 `error`.** Severity counted from the output rather than eyeballed (`grep -oE '^\s+(info\|warning\|error)' \| sort \| uniq -c`). |
| unit and widget tests | `flutter test` | **1,367 tests, all passed**, 1m10s. |
| the Crashlytics pairing condition | `flutter test test/security/no_credential_logging_test.dart` | **4 passed.** |

The 247 analyzer items are all `info`-level style suggestions — predominantly
`use_super_parameters`, `unnecessary_import` and `prefer_const_constructors`. **None is a warning
and none is an error.** This matches the historical baseline exactly, so the integration merge
introduced no analyzer regression.

### A tooling side effect that must never be committed

`flutter pub get`, `flutter analyze` and `flutter build` all **rewrite `analysis_options.yaml`**,
re-injecting an `analyzer: exclude:` block that lists the platform directories. It was reverted
after every run here and `git diff --stat analysis_options.yaml` is empty. A diff of that file after
a tooling run is the tool's doing, not a change anybody made — `test/README.md` already records
this.

---

## 2. Release signing — REVIEWED (code), NOT EXERCISED (no build possible)

The configuration was read end to end (`android/app/build.gradle.kts`) and the hygiene around it
was checked by command. **No signing credential was created, supplied or invented.**

| property | how it was checked | result |
|---|---|---|
| the release build type does not reference the debug signing config | read `buildTypes.release` | **confirmed** — `signingConfig` is assigned only `if (hasReleaseSigning)`; there is no `signingConfigs.getByName("debug")` anywhere in the release path |
| no fallback when the keystore is absent | read `signingConfigs` | **confirmed** — the `release` config is `create`d only when `key.properties` exists, all four required keys are non-blank, and the keystore file itself exists |
| a release task fails loudly rather than silently | read the `gradle.taskGraph.whenReady` guard | **confirmed** — it throws `GradleException` for any `assemble*Release`, `bundle*Release`, `package*Release` or `install*Release` task, naming which task and *why* the config is missing. Debug and profile builds are unaffected, which is what keeps `flutter run` and `flutter test` working without a keystore |
| the guard is tested | `flutter test test/security/release_signing_test.dart` (inside the 1,367) | **passed** — four cases, including "no keystore path is hard-coded in the build file" and a self-check that the hard-coded-path detector finds one when it is there |
| `android/key.properties` is absent | `ls android/key.properties` | **absent**, which is correct |
| it is ignored, not merely absent | `git check-ignore -v android/key.properties` | **ignored** at `android/.gitignore:12` |
| no keystore is committed | `git ls-files \| grep -iE '\.(jks\|keystore\|p12\|pfx)$'` | **none tracked** |
| Crashlytics collection is off | read `AndroidManifest.xml:37-39` | `firebase_crashlytics_collection_enabled` = **`false`**, which satisfies the pairing requirement in `test/README.md`; the credential-logging guard it is paired with is green anyway |
| cleartext traffic is not enabled | grepped the manifest | **neither `usesCleartextTraffic` nor `networkSecurityConfig` is present**, so the Android 9+ platform default (cleartext blocked) applies and nothing weakens it |

---

## 3. What could NOT be done, with the exact unresolved dependency

**NOT PERFORMED — no APK or AAB was produced, and Android is NOT reported as verified.**

The blocker, quoted from the tools rather than paraphrased:

```
$ flutter build apk --release
[!] No Android SDK found. Try setting the ANDROID_HOME environment variable.

$ flutter doctor
[✗] Android toolchain - develop for Android devices
    ✗ Unable to locate Android SDK.
```

Measured alongside it: `ANDROID_HOME` and `ANDROID_SDK_ROOT` are both unset, there is no
`sdkmanager`, `adb` or `aapt` on `PATH`, and no SDK under `~/Android/sdk`, `/usr/lib/android-sdk`
or `/opt/android*`. Java 21 and the Gradle wrapper (8.14) **are** present, and the project targets
`compileSdk = flutter.compileSdkVersion`, `targetSdk = 36`, AGP 8.11.1, Kotlin 2.2.20, Java 17
source/target — so the only missing piece is the SDK itself.

### Why the SDK was not installed here

The Android command-line tools were downloaded, but installing a platform and build-tools requires
**accepting the Android SDK Terms and Conditions**, which is a **vendor licence decision** —
explicitly one of the items Directive E §11 reserves for the owner. Accepting it unilaterally on
the owner's behalf is not something this workstream does, so it was not done, and the tools were
left unused.

### The two blockers, stated separately, because they clear differently

1. **No Android SDK** → no compile, no APK, no AAB, not even a debug build. Clears with an owner
   decision to accept the Android SDK licence in this container, or by running the build in an
   approved environment that already has the SDK (a CI runner with `android-actions/setup-android`,
   or a developer machine with Android Studio).
2. **No release keystore** → even with the SDK, a *release* build would correctly abort on the
   guard above. This one must **not** be cleared by inventing anything: it needs the owner's real
   keystore and `key.properties`, supplied through the protected-credential path in
   `android/RELEASE_SIGNING.md`, and never committed.

**Device testing is a third, independent gap**: there is no physical device and no emulator (an
emulator additionally needs KVM). Nothing here proves behaviour on a handset — not a release APK's
logcat output, not Crashlytics breadcrumbs, not real keyboard or locale switching, not performance.
`test/README.md` §"What genuinely CANNOT be verified here" states this at source and is not
contradicted by anything in this file.

---

## 4. Bilingual and RTL coverage — executed, within the limits of a headless harness

Covered by suites inside the 1,367 that passed:

| area | suites |
|---|---|
| translation-key completeness, ambient direction, app-root wiring | `test/localization/` (3) |
| directional positioning and card direction under RTL | `test/rtl/` (2) |
| localization completeness as a ratchet over a measured baseline | `test/translations/` |
| icon-button accessible names, state announcements, two contrast suites | `test/a11y/` (4) |

**The limit, stated plainly:** these run on the Dart VM under `flutter_test`. There are no
`matchesGoldenFile` baselines, so **no test compares a rendered image**. The contrast suites that
can render do (`a11y/stale_notice_contrast_test.dart` pumps the widget in both themes and reads the
resolved colours off the rendered `Container`); the one that cannot says so in its own docblock,
because `Get.overlayContext` resolves to a `_Theater` with no `Overlay` ancestor under
`flutter_test`, so overlay-based toasts cannot be pumped.

---

## 5. API compatibility with the server

| check | where | result |
|---|---|---|
| endpoint shapes, newly gated endpoints, permission-gated endpoints | `test/contract/` (3 suites, inside the 1,367) | **passed** |
| the server side of the same contract | Laravel `--testsuite=Contract` | run separately at the integration gate; see the integration evidence |

The GATE F migrations in this change add **no** API surface and change **no** response shape: they
create tables that live server code already reads and writes. The Flutter app calls
`https://storesyria.com/api/admin`, and no route, parameter or response field was added, removed or
renamed by this work.

---

## 6. Status, in the directive's own words

| item | status |
|---|---|
| dependency verification | **DONE** — `flutter pub get` resolves with no failure and no override |
| static analysis | **VERIFIED** — 247 info, 0 warning, 0 error |
| automated tests | **VERIFIED** — 1,367 passed |
| release configuration review | **VERIFIED** — read end to end; no debug fallback, fails loudly, nothing committed |
| signing verification with real credentials | **NOT PERFORMED** — no keystore, and none was invented |
| Android build (APK/AAB) | **BLOCKED** — `No Android SDK found`, and installing one is a vendor-licence decision reserved to the owner |
| required device testing | **NOT PERFORMED** — no device and no emulator in this container |
| AR/EN and RTL/LTR | **VERIFIED** to the limit of a headless harness; no rendered-image comparison exists |
| API compatibility | **VERIFIED** — contract suites pass, and this change adds no API surface |
