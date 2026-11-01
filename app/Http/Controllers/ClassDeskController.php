<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClassDeskController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'teacher'], true), 403);
        $classes = $user->role === 'admin'
            ? SchoolClass::query()->orderBy('position')->orderBy('id')->get()
            : $user->taughtClasses()->get();
        $ids = $classes->pluck('id');
        $students = User::query()->where('role', 'learner')->whereIn('school_class_id', $ids)->selectRaw('school_class_id, count(*) as total')->groupBy('school_class_id')->pluck('total', 'school_class_id');
        $pending = AssessmentAttempt::query()
            ->join('users', 'users.id', '=', 'assessment_attempts.user_id')
            ->where('kind', 'written')->where('status', 'pending')
            ->where('users.role', 'learner')->whereIn('users.school_class_id', $ids)
            ->whereHas('lesson.unit.subject', fn ($query) => $query->whereColumn('subjects.school_class_id', 'users.school_class_id'))
            ->selectRaw('users.school_class_id, count(*) as total')->groupBy('users.school_class_id')->pluck('total', 'school_class_id');

        return Inertia::render('Teach', [
            'classes' => $classes->map(fn (SchoolClass $class): array => [
                'id' => $class->id,
                'name' => $class->name,
                'students' => (int) ($students[$class->id] ?? 0),
                'pending' => (int) ($pending[$class->id] ?? 0),
            ])->values(),
        ]);
    }

    public function show(Request $request, SchoolClass $schoolClass): Response
    {
        $this->authorizeClass($request->user(), $schoolClass);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'pending' => ['nullable', 'boolean'], 'student' => ['nullable', 'integer']]);
        $lessons = Lesson::query()->whereHas('unit.subject', fn ($query) => $query->where('school_class_id', $schoolClass->id))->orderBy('title')->get(['id', 'title']);
        $lessonIds = $lessons->pluck('id');
        $classStudents = User::query()->where('role', 'learner')->where('school_class_id', $schoolClass->id);
        $pendingQuery = AssessmentAttempt::query()->where('kind', 'written')->where('status', 'pending')->whereIn('lesson_id', $lessonIds)->whereIn('user_id', (clone $classStudents)->select('id'));
        $summary = ['students' => (clone $classStudents)->count(), 'pending' => (clone $pendingQuery)->count(), 'lessons' => $lessons->count()];
        $studentsPage = $classStudents->when($filters['search'] ?? '', fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($request->boolean('pending'), fn ($query) => $query->whereIn('id', (clone $pendingQuery)->select('user_id')))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $students = $studentsPage->getCollection();
        $selectedId = $students->firstWhere('id', (int) ($filters['student'] ?? 0))?->id ?? $students->first()?->id;
        $pendingCounts = (clone $pendingQuery)->whereIn('user_id', $students->pluck('id'))->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $lastSavedProgress = ProgressRecord::query()->where('user_id', $selectedId)->whereIn('lesson_id', $lessonIds)->max('updated_at');
        $lastSavedAttempt = AssessmentAttempt::query()->where('user_id', $selectedId)->whereIn('lesson_id', $lessonIds)->max('created_at');
        $lastServerActivity = collect([$lastSavedProgress, $lastSavedAttempt])->filter()->max();
        $reads = ProgressRecord::query()->where('user_id', $selectedId)->whereIn('lesson_id', $lessonIds)->where('read', true)->get(['user_id', 'lesson_id']);
        $automatic = AssessmentAttempt::query()->where('kind', 'automatic')->where('user_id', $selectedId)->whereIn('lesson_id', $lessonIds)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))->from('assessment_attempts as newer')->whereColumn('newer.user_id', 'assessment_attempts.user_id')->whereColumn('newer.lesson_id', 'assessment_attempts.lesson_id')->where('newer.kind', 'automatic')->where(function ($query): void {
                    $query->whereColumn('newer.created_at', '>', 'assessment_attempts.created_at')->orWhere(function ($query): void {
                        $query->whereColumn('newer.created_at', 'assessment_attempts.created_at')->whereColumn('newer.id', '>', 'assessment_attempts.id');
                    });
                });
            })->get();
        $writtenPage = AssessmentAttempt::query()->where('kind', 'written')->where('user_id', $selectedId)->whereIn('lesson_id', $lessonIds)
            ->when($request->boolean('pending'), fn ($query) => $query->where('status', 'pending'))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest()->latest('id')->paginate(20, ['*'], 'work_page')->withQueryString();
        $written = $writtenPage->getCollection();

        return Inertia::render('ClassDesk', [
            'schoolClass' => ['id' => $schoolClass->id, 'name' => $schoolClass->name],
            'filters' => ['search' => $filters['search'] ?? '', 'pending' => $request->boolean('pending')],
            'selectedStudent' => $selectedId,
            'lastServerActivity' => $lastServerActivity === null ? null : Carbon::parse($lastServerActivity)->toIso8601String(),
            'summary' => $summary,
            'pagination' => ['previous' => $studentsPage->previousPageUrl(), 'next' => $studentsPage->nextPageUrl(), 'total' => $studentsPage->total()],
            'workPagination' => ['previous' => $writtenPage->previousPageUrl(), 'next' => $writtenPage->nextPageUrl(), 'total' => $writtenPage->total()],
            'students' => $students->map(function (User $student) use ($lessons, $reads, $automatic, $written, $pendingCounts, $selectedId): array {
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'pending' => (int) ($pendingCounts[$student->id] ?? 0),
                    'deactivated' => $student->deactivated_at !== null,
                    'lessons' => ($student->id === $selectedId ? $lessons : collect())->map(function (Lesson $lesson) use ($student, $reads, $automatic, $written): array {
                        $auto = $automatic->first(fn (AssessmentAttempt $attempt): bool => $attempt->user_id === $student->id && $attempt->lesson_id === $lesson->id);
                        $result = is_array($auto?->result) && array_is_list($auto->result) ? $auto->result : [];

                        return [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                            'sections_read' => $reads->where('user_id', $student->id)->where('lesson_id', $lesson->id)->count(),
                            'automatic' => $auto === null ? null : ($result !== [] && collect($result)->every(fn (array $row): bool => ($row['passed'] ?? false) === true) ? 'achieved' : 'not_yet'),
                            'written' => $written->filter(fn (AssessmentAttempt $attempt): bool => $attempt->user_id === $student->id && $attempt->lesson_id === $lesson->id)->map(fn (AssessmentAttempt $attempt): array => [
                                'id' => $attempt->id,
                                'response' => (string) ($attempt->responses['response'] ?? ''),
                                'status' => $attempt->status,
                                'comment' => is_array($attempt->result) ? ($attempt->result['comment'] ?? null) : null,
                                'created_at' => $attempt->created_at?->toIso8601String(),
                            ])->values(),
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }

    public function mark(Request $request, AssessmentAttempt $attempt): RedirectResponse
    {
        $attempt->load('learner', 'lesson.unit.subject');
        abort_unless($attempt->kind === 'written' && $attempt->learner?->role === 'learner', 404);
        $classId = $attempt->lesson?->unit?->subject?->school_class_id;
        abort_unless($classId, 404);
        $this->authorizeClass($request->user(), SchoolClass::query()->findOrFail($classId));
        abort_unless($attempt->learner->school_class_id === $classId, 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'needs_another_try'])],
            'comment' => ['required', 'string', 'max:2000'],
        ]);
        $attempt->update([
            'status' => $data['decision'],
            'result' => ['decision' => $data['decision'], 'comment' => trim($data['comment'])],
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('message', 'Written work reviewed. The student can read your comment.');
    }

    private function authorizeClass(User $user, SchoolClass $schoolClass): void
    {
        abort_unless(in_array($user->role, ['admin', 'teacher'], true), 403);
        if ($user->role === 'teacher') {
            abort_unless($user->taughtClasses()->whereKey($schoolClass->id)->exists(), 403);
        }
    }
}
