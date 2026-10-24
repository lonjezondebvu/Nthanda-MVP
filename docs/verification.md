# Verification record

24 October 2026, Africa/Johannesburg (work began on 23 October). Executed against `Nthanda Plant Pilot.zip` supplied in the user's Downloads directory.

## Results

- Laravel suite: **8 tests passed, 167 assertions**, no skipped tests in this environment. Includes the administrator sign-in/upload/report workflow, actual ZIP import, duplicates, immutable original hash, nine sections, asset inventory, overrides, reordering, history, access control, unavailable links, server marking, pending written work and malicious/malformed imports.
- Node pronunciation test: **1 passed**. Whole-word terms and aliases receive adjacent controls while the text remains unchanged.
- TypeScript: `npm.cmd run typecheck` passed.
- Production frontend: `npm.cmd run build` passed.
- PHP formatting: Laravel Pint completed; Composer configuration validates.
- Live Laravel `/login` returned HTTP **200** at `http://127.0.0.1:8000` using the project PHP configuration.
- Independent read-only review found and prompted fixes for protected-reference aliasing, malformed content and Windows path aliases. Follow-up found no remaining P1/P2 blockers in the supplied-package workflow; reviewer did not independently rerun tests.

## Package evidence

Original SHA-256: `a5ca8e735395d7e051ffc9f71d93d355de3026b1cd2b857c70970887cfe9526a`.

Imported lesson `JCE-BIO-U10`, version `2.1.0-pilot`: **9 sections, 45 content blocks, 51 logical media items, 54 resolved assets, 32 automatic questions, 7 formative activities**. No missing referenced files.

All image, narration, pronunciation, animation, caption and transcript allowlist paths exist on disk. The joined MP4 is 47,787,640 bytes and passes top-level box-boundary checks with `ftyp`, `moov`, `mdat`. An authenticated video request with `Range: bytes=0-1023` returns **206**, `Accept-Ranges: bytes`, and `Content-Length: 1024`.

The override test reads Section 8 as a learner, disables it as an administrator, checks **8 active sections** and a useful unavailable response, restores it to **9**, reverses section order, and confirms the same saved section ID/read/confidence record. It then hides one block and one image, checks the affected item is excluded/revoked, confirms all 32 assessment questions remain, and exercises reversible section deletion.

Protected marking references and raw legacy scripts are absent from learner asset routes. Learner props contain no `answer` field or protected marking reference. Marking tests submit known answers directly from private server test data; correct responses pass, insufficient marks fail, and written responses stay pending.

Malformed paragraph/table/written content and a crafted protected-file transcript alias are rejected before activation, with no remaining import files. Traversal, absolute paths, PHP files, Windows device names and trailing-dot/space paths are rejected. Archive limits, entry checksums, symlink checks and version conflict handling are implemented.

## Verification limits

No connected browser was listed. Attempting an in-app browser returned `Browser is not available: iab`. Therefore no desktop/mobile screenshot or browser interaction pass was possible. Video codec playback, seeking in a real player, sound, animation interaction and caption synchronisation remain unverified in a browser. Range serving and file/container validation are not claimed as decoded playback evidence.

The package remains preview-only. Educational review, media approval, caption listening review and production schema/code agreement remain unresolved. Teacher review UI and production publication were intentionally not implemented.

On another machine, real-package tests explicitly skip if the separately supplied archive is unavailable; set `PILOT_ZIP` to run them. Private package files are not committed to source control.
