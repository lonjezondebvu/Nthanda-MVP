# Nthanda Lesson Studio — Development Progress

Read-aloud presentation script · Approximately 9–10 minutes · 25 October 2026

Today, I would like to share the progress we have made in developing Nthanda Lesson Studio, and explain what the platform can currently do.

Nthanda Lesson Studio is a learning platform that brings teaching content, learning activities and assessments into one organised workspace. Its purpose is to help administrators prepare and manage lessons, while giving learners a clear way to access content and continue learning, including when an internet connection is unavailable.

At this stage, we have developed a working pilot with both online and offline features. I will walk through the experience from signing in, to managing a lesson, to using it as a learner, and finally to working offline.

The experience begins with the login page. Users can sign in with their email address and password, show or hide their password while entering it, and log out when they finish. The application distinguishes administrator access from learner access. Administrators can manage curriculum content, while learners can access lessons, record their progress and submit responses.

We have also developed a consistent visual identity across the application. The interface uses Nthanda branding, colours drawn from the logo, and the Poppins font. The login page includes a digital-learning illustration, while the main workspace has a shared appbar, footer and sidebar. The sidebar can be collapsed to give more space to the content. Layouts also adapt to smaller screens.

After signing in, users can access the lesson library. This is where imported lesson versions are listed, together with information such as the lesson title, version and number of active sections. Administrators can open a lesson to manage it or preview the learner experience. Learners can open the lessons available to them and begin exploring the content.

One of the main features developed so far is curriculum import. An administrator can upload a compatible curriculum ZIP package. The application checks the package, reads its structure and connects its sections, teaching content, assessments and media.

During the import, the administrator can see progress rather than waiting without feedback. Once it finishes, the application provides a report showing what was imported, together with missing files, warnings and items that require review. It also recognises an identical package that has already been imported and opens the existing version instead of creating another copy.

After importing a lesson, the administrator can organise how it appears to learners. Sections can be moved up or down, enabled or disabled, and removed from the learner journey. Removed sections can also be restored. Within a section, individual content blocks and media items can be enabled or disabled.

These controls allow the learning journey to be adjusted while preserving the original imported source. For example, an administrator can temporarily hide a section, preview the result, and then restore it when needed.

We have also developed curriculum editing and version management. Administrators can update supported teaching content, including titles, paragraphs, lists and table content, and change section order. They can then save those changes as a new curriculum version. The previous version remains available, and a curriculum ZIP can be downloaded for use outside the application.

This means changes do not have to overwrite the earlier lesson. However, learner progress remains associated with its particular lesson version. Moving progress automatically between versions is not currently part of the system.

From the learner’s perspective, a lesson begins with an overview and a list of sections. Learners can move through the sections and view the content provided by the curriculum package. This may include text, images, audio, video, animations and practice activities. The available media depends on what the package contains; the application does not generate those learning materials automatically.

As learners work through a lesson, they can mark a section as read and record their confidence. The application keeps these learning indicators separate from assessment results. Reading a section or reporting confidence does not, by itself, mean that a learner has demonstrated achievement.

Assessment functionality is also implemented. The application supports automatic marking for question types such as multiple choice, questions with more than one correct selection, short answers, matching and ordering. Learners can submit responses and receive results for supported automatic assessments.

Written responses can also be submitted and saved. At present, those responses remain pending review. The teacher-facing screen for reviewing and grading written work has not yet been developed, so that is an area for further work.

Another major part of the development is the offline workspace. This is intended to support situations where connectivity is limited or interrupted. While connected, a user can prepare the offline application and download a lesson to their device. They can choose whether to include audio and video, and the application checks the selected downloaded files before marking the lesson ready.

Once that preparation is complete, the learner can open the downloaded lesson without a connection. They can continue reading and recording progress, and save assessment or written responses locally. Assessment responses saved offline are not marked immediately. They need to be submitted to the server when the user reconnects.

Offline functionality also supports teaching preparation. A user can create a local teacher workspace and start a lesson draft without signing in to an online account. They can enter lesson details, add sections and supported content, attach media and preview the draft. The application saves this work on the device.

There are also options to create drafts from files. Users can import a compatible curriculum ZIP, select a package folder, or build a draft from text, Markdown and media files. Word and PDF documents need to be converted first; direct conversion of those documents is not currently available.

To help users keep a separate copy of their work, we have implemented offline backup and restore options. A full offline backup can be exported, and a smaller state file can also be saved. Because local work is stored in the browser on that device, keeping an exported backup is important. Clearing browser data can remove downloaded content and drafts.

When connectivity returns, the user decides what to synchronise. Local teaching drafts can be copied into the signed-in account’s workspace. Teaching changes can then be reviewed against the server version. If there are conflicting changes, the user can resolve them before confirming the sync. The reviewed content can be saved as a new version or as a separate lesson copy.

Learner work is reviewed separately. Users can select which saved progress updates and responses to submit, while leaving other work on the device. Simply reconnecting does not automatically publish teaching changes or submit every saved response.

It is important to be clear about the current stage of development. This is a pilot preview. The core workflows have been implemented, but it is not yet a complete production learning management system. Self-registration, password-reset screens, teacher grading of written responses, advanced analytics and certificates are not currently available. Production publication and approval workflows also remain outside the current scope.

The next stage is to validate the implemented workflows with real users and actual curriculum packages, review content and media quality, and prioritise the remaining features based on feedback.

What we can demonstrate today is a connected journey: signing in, importing a curriculum, organising and editing a lesson, accessing learning content, recording progress, submitting supported assessments, and continuing selected work offline. We also have the ability to back up local work and synchronise reviewed changes when a connection becomes available.

That is the development progress achieved so far with Nthanda Lesson Studio. Thank you, and I welcome your questions and feedback.
