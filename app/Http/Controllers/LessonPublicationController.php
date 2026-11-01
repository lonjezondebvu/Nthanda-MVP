<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonPublicationEvent;
use App\Services\LessonAssembler;
use App\Services\PublicationReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LessonPublicationController extends Controller
{
    public function show(Request $request, Lesson $lesson, LessonAssembler $assembler, PublicationReview $reviews): Response
    {
        abort_unless($request->user()->role === 'admin', 403);
        try {
            $assembled = $assembler->assemble($lesson, true);
            $review = ['fingerprint' => $reviews->fingerprint($lesson), 'current' => $reviews->isCurrent($lesson), 'previous' => $lesson->publication_review, 'blockers' => $this->blockers($lesson, $assembler, $assembled), 'missing_files' => $lesson->report['missing_files'] ?? [], 'active_count' => $assembled['active_count'], 'placed' => $lesson->unit_id !== null, 'warnings' => $lesson->report['warnings'] ?? []];
        } catch (Throwable $exception) {
            report($exception);
            $review = ['fingerprint' => '', 'current' => false, 'previous' => $lesson->publication_review, 'blockers' => [], 'missing_files' => ['Lesson package could not be read. Restore or repair it before publishing. Existing approval can still be revoked.'], 'active_count' => 0, 'placed' => $lesson->unit_id !== null, 'warnings' => []];
        }

        return Inertia::render('Publication', [
            'lesson' => ['id' => $lesson->id, 'title' => $lesson->title, 'version' => $lesson->version, 'sha256' => $lesson->sha256],
            'review' => $review,
            'events' => LessonPublicationEvent::query()->where('lesson_id', $lesson->id)->latest('id')->paginate(10)->through(fn (LessonPublicationEvent $event): array => ['id' => $event->id, 'action' => $event->action, 'note' => $event->note, 'fingerprint' => $event->fingerprint, 'fingerprint_source' => $event->snapshot['fingerprint_source'] ?? 'current_content', 'reviewer' => $event->snapshot['reviewer_name'], 'created_at' => $event->created_at->toIso8601String()]),
        ]);
    }

    public function store(Request $request, Lesson $lesson, LessonAssembler $assembler, PublicationReview $reviews): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $action = $request->validate(['action' => ['required', Rule::in(['publish', 'revoke'])], 'note' => ['required', 'string', 'min:10', 'max:5000']]);
        $data = $action['action'] === 'publish' ? $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'curriculum' => ['required', 'accepted'], 'media' => ['required', 'accepted'], 'accessibility' => ['required', 'accepted'], 'assessment' => ['required', 'accepted'],
            'resolved_blockers' => ['present', 'array'], 'resolved_blockers.*' => ['string', 'max:4000'],
        ]) : [];
        DB::transaction(function () use ($request, $lesson, $assembler, $reviews, $action, $data): void {
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);
            $previousReview = $locked->publication_review;
            $fingerprintSource = 'current_content';
            if ($action['action'] === 'publish') {
                $fingerprint = $reviews->fingerprint($locked);
                if (! hash_equals($fingerprint, $data['fingerprint'])) {
                    throw ValidationException::withMessages(['fingerprint' => 'The lesson changed during review. Reload and review the latest content.']);
                }
                $assembled = $assembler->assemble($locked, true);
                if ($locked->unit_id === null || ($locked->report['missing_files'] ?? []) !== [] || $assembled['active_count'] < 1) {
                    throw ValidationException::withMessages(['publication' => 'Assign a curriculum unit, resolve all missing files, and enable at least one section before publishing.']);
                }
                $blockers = $this->blockers($locked, $assembler, $assembled);
                $resolved = array_values(array_unique($data['resolved_blockers']));
                sort($blockers);
                sort($resolved);
                if ($blockers !== $resolved) {
                    throw ValidationException::withMessages(['resolved_blockers' => 'Explicitly confirm resolution of every listed blocker and content review flag.']);
                }
                $locked->update(['published_at' => now(), 'publication_review' => ['fingerprint' => $fingerprint, 'reviewer_id' => $request->user()->id, 'note' => $action['note'], 'resolved_blockers' => $resolved, 'acknowledgments' => ['curriculum' => true, 'media' => true, 'accessibility' => true, 'assessment' => true], 'reviewed_at' => now()->toIso8601String()]]);
            } else {
                $previousFingerprint = $previousReview['fingerprint'] ?? null;
                $hasPreviousFingerprint = is_string($previousFingerprint) && preg_match('/^[a-f0-9]{64}$/', $previousFingerprint) === 1;
                $fingerprint = $hasPreviousFingerprint ? $previousFingerprint : str_repeat('0', 64);
                $fingerprintSource = $hasPreviousFingerprint ? 'previous_approval' : 'unavailable';
                $locked->update(['published_at' => null, 'publication_review' => null]);
            }
            LessonPublicationEvent::create(['lesson_id' => $locked->id, 'reviewer_id' => $request->user()->id, 'action' => $action['action'] === 'publish' ? 'published' : 'revoked', 'note' => $action['note'], 'fingerprint' => $fingerprint, 'snapshot' => ['manifest_id' => $locked->manifest_id, 'version' => $locked->version, 'title' => $locked->title, 'workspace' => $locked->workspace, 'reviewer_name' => $request->user()->name, 'review' => $locked->publication_review, 'previous_review' => $previousReview, 'fingerprint_source' => $fingerprintSource], 'created_at' => now()]);
        });

        return back()->with('message', $action['action'] === 'publish' ? 'Lesson approved for publication. Content changes require a new review.' : 'Publication approval revoked. The lesson is in preview.');
    }

    /** @param array<string, mixed> $assembled
     * @return array<int, string>
     */
    private function blockers(Lesson $lesson, LessonAssembler $assembler, array $assembled): array
    {
        return array_values(array_unique([...($assembler->source($lesson)['manifest']['publication']['blockers'] ?? []), ...$assembled['review_flags']]));
    }
}
