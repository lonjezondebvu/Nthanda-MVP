<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Services\LessonAssembler;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use League\Flysystem\UnableToReadFile;

class LearningDashboardController extends Controller
{
    public function __construct(private LessonAssembler $assembler) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $lessons = Lesson::query()->visibleTo($user)->with('unit.subject')->orderBy('title')->orderBy('id')->get();
        $progress = ProgressRecord::query()->where('user_id', $user->id)->whereIn('lesson_id', $lessons->modelKeys())->orderByDesc('updated_at')->orderByDesc('id')->get();
        $attempts = AssessmentAttempt::query()->where('user_id', $user->id)->whereIn('lesson_id', $lessons->modelKeys())
            ->where('id', '=', function (Builder $latest): void {
                $latest->select('latest.id')->from('assessment_attempts as latest')
                    ->whereColumn('latest.user_id', 'assessment_attempts.user_id')
                    ->whereColumn('latest.lesson_id', 'assessment_attempts.lesson_id')
                    ->where('latest.kind', 'automatic')->where('latest.status', 'marked')
                    ->orderByDesc('latest.created_at')->orderByDesc('latest.id')->limit(1);
            })->get(['id', 'lesson_id', 'result', 'created_at'])->keyBy('lesson_id');
        $unavailableLessons = 0;
        $mastery = [];
        $recommendations = [];
        $spacedReviews = [];
        $reviewCutoff = now()->subDays(7);
        $continueOptions = [];
        $summary = ['lessons' => $lessons->count(), 'sections_read' => 0, 'demonstrated' => 0, 'needs_practice' => 0];
        foreach ($lessons as $lesson) {
            try {
                $data = $this->assembler->assemble($lesson);
            } catch (UnableToReadFile|JsonException $exception) {
                report($exception);
                $unavailableLessons++;

                continue;
            }
            $sections = collect($data['sections']);
            $records = $progress->where('lesson_id', $lesson->id)->whereIn('section_id', $sections->pluck('id'))->keyBy('section_id');
            $read = $records->where('read', true)->count();
            $summary['sections_read'] += $read;
            $next = $sections->first(fn (array $section): bool => ! ($records->get($section['id'])?->read ?? false));
            if ($next !== null) {
                $continueOptions[] = ['lesson_id' => $lesson->id, 'title' => $lesson->title, 'section_title' => $next['title'], 'href' => $this->sectionUrl($lesson, $next['id']), 'read' => $read, 'total' => $sections->count(), 'last_activity' => $records->first()?->updated_at?->getTimestamp() ?? 0];
            }
            $attempt = $attempts->get($lesson->id);
            $results = collect($attempt?->result ?? [])->keyBy('criterion_id');
            $criterionIds = $sections->pluck('criterion_ids')->flatten()->merge(collect($data['questions'])->pluck('criterionId'))->filter()->unique()->values();
            $criteria = [];
            foreach ($criterionIds as $criterionId) {
                $evidence = $results->get($criterionId);
                $status = $evidence === null ? 'not_assessed' : (($evidence['passed'] ?? false) ? 'demonstrated' : 'needs_practice');
                if ($status !== 'not_assessed') {
                    $summary[$status]++;
                }
                $related = $sections->filter(fn (array $section): bool => in_array($criterionId, $section['criterion_ids'], true));
                $criteria[] = ['id' => $criterionId, 'status' => $status, 'sections' => $related->pluck('title')->values()->all(), 'evidence' => $evidence === null ? null : ['score' => $evidence['score'] ?? 0, 'max_marks' => $evidence['max_marks'] ?? 0, 'assessed_at' => $attempt->created_at->toIso8601String()]];
                if ($status === 'needs_practice' && $related->isNotEmpty()) {
                    $section = $related->first();
                    $recommendations[$lesson->id.':'.$section['id']] = ['lesson_title' => $lesson->title, 'section_title' => $section['title'], 'reason' => 'assessment', 'description' => 'Your latest assessment suggests practising '.$criterionId.'.', 'href' => $this->sectionUrl($lesson, $section['id'])];
                }
            }
            foreach ($sections as $section) {
                $key = $lesson->id.':'.$section['id'];
                if ($records->get($section['id'])?->confidence === 1 && ! isset($recommendations[$key])) {
                    $recommendations[$key] = ['lesson_title' => $lesson->title, 'section_title' => $section['title'], 'reason' => 'confidence', 'description' => 'You asked for more support with this section.', 'href' => $this->sectionUrl($lesson, $section['id'])];
                }
                $record = $records->get($section['id']);
                $sectionResults = $results->only($section['criterion_ids']);
                $hasPassedCriterion = $sectionResults->contains(fn (array $result): bool => ($result['passed'] ?? false) === true);
                $hasFailedCriterion = $sectionResults->contains(fn (array $result): bool => ($result['passed'] ?? false) !== true);
                if ($record?->read && $hasPassedCriterion && ! $hasFailedCriterion && ! isset($recommendations[$key])) {
                    $lastPractice = $attempt->created_at->greaterThan($record->updated_at) ? $attempt->created_at : $record->updated_at;
                    if ($lastPractice->lessThanOrEqualTo($reviewCutoff)) {
                        $spacedReviews[$key] = ['lesson_title' => $lesson->title, 'section_title' => $section['title'], 'reason' => 'spaced_review', 'description' => 'A seven-day review: you demonstrated a criterion here, and your latest assessment or section check-in was on '.$lastPractice->toDateString().'. Revisit it to keep practising.', 'href' => $this->sectionUrl($lesson, $section['id'])];
                    }
                }
            }
            $mastery[] = ['lesson_id' => $lesson->id, 'title' => $lesson->title, 'subject' => $lesson->unit?->subject?->name, 'criteria' => $criteria, 'assessment_href' => '/lessons/'.$lesson->id.'/assessment'];
        }
        usort($continueOptions, fn (array $left, array $right): int => $right['last_activity'] <=> $left['last_activity']);
        $feedback = AssessmentAttempt::query()->where('user_id', $user->id)->whereIn('lesson_id', $lessons->modelKeys())->where('kind', 'written')->whereIn('status', ['accepted', 'needs_another_try'])->orderByDesc('reviewed_at')->orderByDesc('id')->limit(6)->get()->map(fn (AssessmentAttempt $attempt): array => [
            'id' => $attempt->id, 'lesson_title' => $lessons->find($attempt->lesson_id)->title, 'status' => $attempt->status, 'comment' => $attempt->result['comment'] ?? '', 'reviewed_at' => $attempt->reviewed_at?->toIso8601String(), 'href' => '/lessons/'.$attempt->lesson_id.'/assessment',
        ]);

        $assignments = Assignment::query()->visibleTo($user)->whereNull('archived_at')->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->limit(12)->get()->map(fn (Assignment $assignment): array => [
            'id' => $assignment->id, 'title' => $assignment->title, 'instructions' => $assignment->instructions, 'due_at' => $assignment->due_at?->toIso8601String(), 'href' => '/lessons/'.$assignment->lesson_id,
        ]);

        return Inertia::render('Today', ['unavailableLessons' => $unavailableLessons, 'assignments' => $assignments, 'needsClass' => $user->role === 'learner' && $user->school_class_id === null, 'continueLearning' => $continueOptions[0] ?? null, 'summary' => $summary, 'mastery' => $mastery, 'recommendations' => array_slice(array_values($recommendations + $spacedReviews), 0, 6), 'feedback' => $feedback]);
    }

    private function sectionUrl(Lesson $lesson, string $sectionId): string
    {
        return '/lessons/'.$lesson->id.'/sections/'.rawurlencode($sectionId);
    }
}
