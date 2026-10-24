# Nthanda Lesson Studio

A local Laravel + Inertia React prototype for importing a lesson ZIP, reviewing its contents, applying reversible settings, and previewing an interactive lesson. The supplied Plant Pilot has been imported into the local development database. Package data and demo records are ignored by Git.

## Start locally on Windows

Requirements: PHP 8.3+ with mbstring, zip, fileinfo, PDO SQLite and SQLite; Composer; Node 24 LTS; npm. This environment has SQLite DLLs available but disabled globally. The PowerShell launcher loads them through project-local `scripts/php/20-studio.ini`, inherited by Artisan child processes. It also sets 128 MiB upload and 130 MiB POST limits. If SQLite is already loaded globally, remove the two extension lines from that local INI to avoid duplicate-load notices.

```powershell
composer install
npm.cmd ci
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/studio.ps1 setup
npm.cmd run build
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/studio.ps1 serve
```

Open **http://127.0.0.1:8000**. Production assets are served by Laravel; Vite need not run after building. For frontend development, run `npm.cmd run dev -- --host 127.0.0.1` in a second terminal. `composer run dev` starts the Windows Laravel launcher. Setup seeds demo users; it does not automatically import a proprietary lesson package or erase existing lessons.

On other operating systems, enable the equivalent PHP extensions, copy `.env.example` to `.env`, create `database/database.sqlite`, then run `php artisan key:generate`, `php artisan migrate --seed`, `npm ci`, `npm run build`, and `php artisan serve`. Use `php artisan test` directly when SQLite is configured globally.

### Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Administrator | admin@nthanda.test | NthandaDemo!2026 |
| Learner | learner@nthanda.test | NthandaDemo!2026 |

These are local pilot accounts. No registration, password reset or production identity provisioning is implemented. Administrators can manage imports/settings and open learner previews. Learners have server-enforced read/progress/assessment access only.

## Import and try the workflow

1. Sign in as the administrator and choose **Import package**.
2. Upload `Nthanda Plant Pilot.zip` (maximum 128 MiB).
3. Review loaded counts, missing files, warnings and unsupported features on the management page.
4. Expand a section to toggle a block or media item. Use section switches, up/down controls, or reversible removal.
5. Open **Preview lesson**. Reload after changing settings in another tab.
6. Disable `JCE-BIO-U10-S008`: the active count changes from nine to eight. Restore it: nine. Reordering changes position while IDs and learner history remain stable.
7. Sign in as the learner to record reading, confidence, explored activities and assessment submissions separately.

An identical ZIP is detected by SHA-256 and opens the existing import with an explicit message. Different bytes with the same lesson ID/version are rejected; increment the package version. Different versions have separate settings, progress and assessment attempts.

CLI import:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/studio.ps1 import -Package 'C:\Users\USER\Downloads\Nthanda Plant Pilot.zip'
```

## Architecture

- `PackageImporter`: bounded ZIP extraction, safe paths, file allowlist, checksum verification, atomic activation and cleanup on failure. Limits: 1,500 entries, 64 MiB per member, 256 MiB expanded total, compression ratio 250:1 for members at least 1 MiB, JSON files at most 2 MiB. Executable legacy files are retained privately as source evidence and never executed or served.
- `ManifestParser`: application-owned schema, supported-version check, unique IDs, block payload types, required source hash, section/block/media/assessment cross-references. Unknown versions fail with validation feedback.
- `LegacyPackageAdapter`: explicit, versioned metadata for omitted introduction, goals, practical guidance, six formative activities, one shoot-system quick check and two observation images. It parses only the literal JSON assignment from `dist/data.js`; no JS evaluation.
- `MediaResolver`: controlled asset inventory, accessibility sidecars, video joining and MP4 top-level container validation.
- `LessonAssembler`: combines source data and database overrides; projects safe learner questions and active assets. Full manifest, marking paths, answer keys and rubrics are excluded from Inertia props.
- `ContentSetting`: section/block/media enablement, section soft-deletion and order. Source files are never edited by administration.
- `ProgressRecord`: user + imported-version + stable-section ID; separate reading, confidence and explored activity IDs.
- `AssessmentMarker`: server-only marking of multiple choice, multi-select, accepted short answers, matching and ordering. All required questions and package thresholds are retained. Written responses stay pending.
- React pages: library, import/report, management, overview, section player and assessments. Typed reusable renderers preserve content and media anchors. HTML animations have both iframe and response-header sandboxes without same-origin permission.

Storage is `storage/app/private/imports/<uuid>/`: `original.zip`, immutable extracted `source/`, and separate `derived/lesson.json` plus joined video. Only the authenticated asset controller can serve learner-allowlisted assets. Laravel's generic local-file serving is disabled. Hidden sections/media revoke their asset URLs on subsequent requests. Already downloaded/browser-buffered media cannot be recalled. Only the supplied branding image is intentionally public.

### Legacy package compatibility

Content and learner media resolve relative to `dist/`. Protected JSON must resolve under the private root `assessments/` directory. The original package schema is preserved. The application-owned pilot schema changes its sample-specific section minimum from nine to one; assembly does not assume a fixed count, subject, criterion or media position.

The four `*.mp4.part00` through `*.mp4.part03` members in `dist/media/finished/video/` are binary fragments of one MP4, joined in numeric suffix order. The logical media item retains `video-chunk-0`; all four original IDs remain in derived `chunk_ids`. This explicit four-part check is limited to this legacy lesson/version. Joined length: **47,787,640 bytes**. Validation checks complete top-level MP4 box lengths and presence of `ftyp`, `moov`, and `mdat`. Laravel BinaryFileResponse supplies byte-range playback/seeking. This is structural validation, not a full codec decode. Captions and transcript URLs accompany the player.

Assessment choices and unpaired matching terms are necessarily learner-visible; correct mappings, sequences, accepted short answers and marking guides remain private. Matching options and ordering items are sorted before delivery. Automatic marking requires every question for a criterion plus its unchanged threshold (the pilot has 16 questions and requires at least 12 correct per criterion). Reading or confidence never grants achievement. Removing teaching raises review flags, while assessment requirements remain intact.

Publication remains **preview-only** with the supplied blockers: educational/media review, caption listening review, and production schema/code agreement.

## Untitled UI and versions

Genuine MIT-licensed free source components were copied from the [official React repository](https://github.com/untitleduico/react), following the [manual installation guide](https://www.untitledui.com/react/docs/installation). Buttons, inputs, badges, toggles, textarea, dialogs, loading indicators, navigation, tables and empty states use those components. Lesson-specific cards, reports and notifications compose these foundations and tokens. No shadcn components or licensed Untitled UI assets were introduced. The original theme/typography files are retained; blue/gold overrides derive from the supplied Nthanda logo.

See [component provenance and license](docs/third-party/untitled-ui.md) and [installed versions](docs/versions.md). Dependency lockfiles are committed. Official compatibility references consulted: [Laravel releases](https://laravel.com/docs/13.x/releases), [Vite requirements](https://vite.dev/guide/), and Untitled UI's guide above. Inertia's published server-installation web page was unavailable to the browsing tool; the installed adapter source and compatible Composer constraints were inspected.

GitHub's API archive downloads failed authentication in this environment. Equivalent official `codeload.github.com` URLs in `composer.lock` preserve the same package versions and commit references; `scripts/composer-codeload.py` reproduces this URL-only mapping after a dependency update. No authentication tokens or environment secrets are committed.

## Verification

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/studio.ps1 test
node --experimental-strip-types --test tests/pronunciation.test.ts
npm.cmd run typecheck
npm.cmd run build
```

Integration tests use the actual supplied ZIP; set environment variable `PILOT_ZIP` to its location on another machine. Those tests explicitly skip when the separately supplied archive is absent. Tests use an isolated in-memory database and fake private disk, preserving local demo content. See [verification record](docs/verification.md) for the final evidence and limits.

## Known limitations and next step

This is a focused local pilot. Written responses are persisted pending teacher review; a teacher marking/review interface is the next functional step. No production publication, approval resolution, workbook-library UI, tutorial-video generation, queue-based import, offline mode, analytics, activity drafts, or progress migration between package versions is included. Formative reflections and unsubmitted automatic answers stay on the current page; submitted attempts are persisted. Concurrent import races are protected by database uniqueness but may produce a generic conflict response.

No connected browser was available, so desktop/mobile visual inspection and audible media playback could not be performed. Browser layout, animation interaction, caption timing and codec decoding require follow-up human QA. The source includes responsive layouts and native media error fallbacks; tests verify files, HTTP delivery, range responses and sandbox headers.
