# Nthanda Lesson Studio
## Current features and step-by-step demonstration

Prepared 25 October 2026 · Pilot preview

## 1. Introduce the platform

Nthanda Lesson Studio brings lesson content, media, practice activities and assessments into one workspace. Administrators can import and organise curricula, while learners can read lessons, record progress and submit responses. The offline workspace lets users download lessons, create teaching drafts and choose what to synchronise when connectivity returns.

**Suggested introduction:** “Nthanda supports teaching and learning both online and offline. Today I will show how a lesson is imported, organised, explored and saved for use without a connection.”

## 2. What is currently available?

- **Account access:** sign-in, password visibility control, administrator and learner access, and logout.
- **Consistent workspace:** Nthanda branding, Poppins typography, responsive layouts, a collapsible sidebar and shared navigation across online and offline workspaces.
- **Lesson library:** browse imported lesson versions and open management or learner views. Administrators can start an empty trial workspace.
- **Curriculum import:** upload a supported ZIP, see import progress and review content counts, missing files and warnings.
- **Lesson management:** enable or disable sections, content blocks and media; reorder sections; remove and restore sections.
- **Editing and versions:** edit teaching text and section order, save as a new curriculum version and download a curriculum ZIP.
- **Learner experience:** lesson overview, section navigation, text, lists, tables, images and supported audio, video and animations where supplied by the package.
- **Progress and assessment:** reading status, confidence, practice activities, automatically marked supported questions and saved written submissions.
- **Offline workspace:** verified downloads, local teaching drafts, file imports, media attachments, backups and explicit sync review.

Media and activities depend on the lesson package. The platform does not automatically generate lesson content or media.

## 3. Prepare the demonstration

1. Start the application and open `http://127.0.0.1:8000/login` on the presentation machine.
2. Have an administrator account and a separate learner account ready. Keep passwords out of presentation slides.
3. Prepare a compatible curriculum ZIP or use a lesson already in the library. A random ZIP or PDF is not a supported curriculum package.
4. Before demonstrating offline access, open **Offline workspace** while connected and let preparation finish. Use HTTPS on a hosted site, or localhost for a local demonstration.
5. Download the chosen lesson and its required media before disconnecting. Keep a backup of any local draft you intend to use.

**If there is no curriculum package:** demonstrate **New local teacher workspace → Create lesson offline** instead. Explain that this shows local authoring; imported assessments require suitable lesson content.

## 4. Demonstrate the administrator workflow

### Step 1 — Sign in and show the library

1. Sign in as an administrator.
2. Open **Lesson library**.
3. Point out lesson titles, version numbers and active section counts.
4. Use the appbar menu button to show the collapsible sidebar.

**Explain:** “Each imported version has its own content, settings and learning records.”

### Step 2 — Import a curriculum

1. Choose **Import curriculum ZIP** or **Import package**.
2. Select the supported ZIP and click **Validate and import**.
3. Show the upload and import progress.
4. Select **Open imported curriculum** when it completes.
5. Review section, content, media and assessment counts, plus warnings and missing files.

The upload limit is 128 MiB. An identical ZIP opens the existing import. A changed package using the same lesson identity must have a new version number.

### Step 3 — Organise a lesson

1. Open **Manage lesson** from the library.
2. Expand a section to inspect its content and media.
3. Disable one section or content item, then open **Preview lesson** to show the effect.
4. Re-enable it and demonstrate moving a section up or down.
5. Explain that removed sections can be restored and the original imported package is preserved.

**Explain:** “Teachers can adjust the learning journey without deleting the original source content.”

### Step 4 — Edit and save a version

1. Select **Edit curriculum**.
2. Change a title, paragraph or another supported teaching block.
3. Enter a unique new version number.
4. Select **Save as new version**.
5. Show that the earlier version remains available. Use **Download ZIP** when a portable package is needed.

Learner records do not automatically migrate to a new version. The online editor focuses on teaching content; it is not a complete assessment-authoring tool.

## 5. Demonstrate the learner workflow

1. Log out and sign in as a learner, or use the administrator’s **Preview lesson** for a shorter walkthrough.
2. Open a lesson and show its overview and section list.
3. Read a section and open an available image, audio or video item.
4. Explore a practice activity if the package includes one.
5. Mark the section as read and record a confidence level.
6. Open assessments and submit a supported automatic assessment.
7. Show the result and explain that a written response is saved as pending review.

**Explain:** “Reading progress and confidence are separate from assessed achievement. Practice activities do not award assessment marks.”

Supported automatic question types include multiple choice, multi-select, short answers, matching and ordering. Written responses are stored, but a teacher marking interface is not currently available. Administrator previews use that administrator’s records; use a separate learner account to demonstrate separate learner history.

## 6. Demonstrate offline learning and creation

### Step 1 — Save a lesson for offline use

1. Open **Offline workspace** and select the correct **Device workspace**.
2. Use **Connect account** if needed.
3. Under **Download from your library**, choose whether to include audio and video.
4. Click **Download for offline use** and wait for verification to finish.
5. Disconnect from the network and open the lesson from **Available on this device**.
6. Read content and record progress. Assessment and written responses can be queued locally.

Offline assessment responses remain unmarked until submitted to the server after reconnection. Only downloaded media is available offline.

### Step 2 — Create a local teaching draft

1. Select **New local teacher workspace**.
2. Choose **Create lesson offline**.
3. Enter lesson details, add sections and supported content blocks, and attach media if needed.
4. Check the **Saved on this device** message and use **Read** to preview the draft.

Alternatively, use **Import ZIP / restore backup**, **Select package folder**, or **Build from files**. Building from files supports text, Markdown and media. PDF and Word documents need conversion first.

### Step 3 — Back up and synchronise

1. Use **Export offline backup** to download a portable backup of the draft and device state. **Export state file** is a smaller state export, not a replacement for a full media backup.
2. Reconnect and sign in to the appropriate account.
3. For a local teacher workspace, choose **Copy local drafts to signed-in account** before syncing. The original local drafts remain available.
4. Open the draft and select **Review teaching sync**.
5. Review changes, resolve any conflicts, enter a new version and select **Sync reviewed lesson**. A separate-copy option is also available.
6. To submit learner progress or responses, choose **Review saved work**, select the entries and click **Submit selected work**.

**Explain:** “Sync is deliberate. Teaching changes and learner work are reviewed separately; reconnecting does not automatically publish local edits.”

The sync review sends draft information to the server for comparison, but saving the new lesson version happens only when the reviewed sync is confirmed.

## 7. Current boundaries to explain honestly

- This is a **pilot preview**, not a production publication or approval system.
- Self-registration and password-reset screens are not implemented. Account assistance is handled by an administrator.
- Written responses can be submitted, but teacher grading and review screens are not yet implemented.
- Advanced analytics, certificates and automatic progress migration between curriculum versions are not part of the current workflow.
- Offline storage belongs to the current device and browser. Clearing browser data can remove local work; export backups. A persistent-storage request may be declined by the browser.
- Offline use must be prepared while connected. App installation depends on browser support. Online logout does not erase downloaded lessons or drafts.
- Content quality, media playback and accessibility need review with the actual package and presentation device. Do not present sample-package counts as guaranteed content in every installation.

## 8. Suggested 10-minute presentation

1. **1 minute:** introduce the purpose and show sign-in and navigation.
2. **2 minutes:** show a prepared import report and demonstrate one reversible lesson change.
3. **2 minutes:** walk through a lesson, progress and an assessment.
4. **3 minutes:** open a downloaded lesson offline, show a local draft and explain backup and sync review.
5. **2 minutes:** explain the pilot boundaries and invite feedback.

**Suggested closing:** “The current pilot demonstrates the core journey: import, organise, learn, work offline and synchronise reviewed changes. The next stage is to validate this workflow with real users and prioritise the remaining teaching and production requirements.”

## About this guide

This guide describes the current routes, screens and implementation reviewed on 25 October 2026. It is a presentation walkthrough, not a claim that every workflow or media package was retested for this document. Older repository notes describe an earlier scope; in particular, offline workflows and curriculum editing are now implemented.
