# Nthanda Lesson Studio

The supplied request is the implementation specification. Work locally in this empty repository; no publication or remote push. The user explicitly delegates implementation decisions and asks to avoid questions unless blocked.

## Architecture and decisions

Laravel owns sessions, role checks, routing, validation, private storage, assembly, progress and marking. Inertia delivers typed React pages. SQLite stores imported versions, overrides and learner records. Immutable original ZIPs and extracted source stay outside public/. Derived compatibility metadata and joined video are separate from source. Every learner asset is allowlisted through an authenticated controller.

Use the official Laravel skeleton with manual React/Inertia integration so no shadcn components are introduced. Install free Untitled UI source from its official repository and retain its license and theme. Brand with the supplied logo, green accents and warm neutral surfaces.

Alternatives considered: adapting the bundled player would retain fixed positions and expose bundled assessment data; extracting a standalone frontend would lose Laravel delivery. Manifest assembly in Laravel satisfies the requested boundaries.

## Execution

- [x] Scaffold supported dependencies and lock versions; configure SQLite and Inertia.
- [x] Verify real-package import, duplicates, source integrity, nine sections, media and answer protection; implement private validation and compatibility.
- [x] Verify administrator authorization, overrides, reorder identity, learner history and unavailable links; implement controllers and assembly.
- [x] Verify marking and progress; preserve server policies and pending written submissions.
- [x] Build all requested pages with official free Untitled UI foundations.
- [x] Run Laravel tests, pronunciation test, TypeScript and production build; record unavailable browser inspection; document versions, setup and limitations.
- [x] Commit reviewed source and dependency lockfiles locally.

## Review focus

Reject archive traversal, symlinks, duplicate paths, unsupported files, bombs and broken required references without an active partial lesson. Reject unknown schema versions. Reject conflicting bytes for an existing version; identical SHA is an explicit duplicate result. Hide answer keys and legacy scripts from asset URLs and props. Preserve all assessment questions and thresholds when teaching is disabled. Keep progress keyed to user, imported version and stable IDs; never infer achievement from reading or confidence.

## Execution ledger

Environment: PHP 8.3.31, Composer 2.10.3, Node 24.19.0, npm 11.17.0. PHP SQLite DLLs exist; use per-command extensions without changing system configuration. Official Laravel 13 supports PHP 8.3. Official Untitled UI manual installation supports React + Tailwind.

Composer archive scaffold retrieval failed GitHub authentication; official Git clone succeeded. No supplied Python or launcher has been executed.

Final evidence: see verification.md. Equivalent official codeload URLs resolved dependency delivery without changing versions/references. SQLite configuration uses an inherited PHP_INI_SCAN_DIR, since Artisan child processes do not inherit command-line -d flags. No system PHP configuration changed.

Review fixes: rejected private marking aliases, malformed consumed data and Windows path aliases; changed pronunciation to term-adjacent controls with aliases and fallback recordings when the reference term is absent. Genuine free navigation/table/empty-state components are included alongside buttons, inputs, dialogs and tokens. Supplied Nthanda blue/gold logo drives brand accents; botanical illustration remains green.
