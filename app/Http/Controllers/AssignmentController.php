<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = in_array($user->role, ['teacher', 'admin'], true);
        $classes = $canManage ? ($user->role === 'admin' ? SchoolClass::query()->orderBy('position')->get() : $user->taughtClasses()->get()) : collect();

        return Inertia::render('Assignments', [
            'canManage' => $canManage,
            'classes' => $classes->map(fn (SchoolClass $class): array => ['id' => $class->id, 'name' => $class->name]),
            'lessons' => $canManage ? Lesson::query()->visibleTo($user)->with('unit.subject')->orderBy('title')->get()->map(fn (Lesson $lesson): array => ['id' => $lesson->id, 'title' => $lesson->title, 'school_class_id' => $lesson->unit?->subject?->school_class_id]) : [],
            'assignments' => Assignment::query()->visibleTo($user)->with('lesson:id,title', 'schoolClass:id,name')->orderByRaw('archived_at IS NOT NULL')->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->paginate(20)->through(fn (Assignment $assignment): array => [
                'id' => $assignment->id, 'title' => $assignment->title, 'instructions' => $assignment->instructions, 'due_at' => $assignment->due_at?->toIso8601String(), 'archived' => $assignment->archived_at !== null, 'lesson_id' => $assignment->lesson_id, 'lesson_title' => $assignment->lesson->title, 'class_name' => $assignment->schoolClass->name,
            ]),
        ]);
    }

    public function store(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorizeClass($request->user(), $schoolClass);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'instructions' => ['nullable', 'string', 'max:5000'], 'lesson_id' => ['required', 'integer'], 'due_at' => ['nullable', 'date', 'after:now']]);
        $lesson = Lesson::query()->whereKey($data['lesson_id'])->whereHas('unit.subject', fn ($query) => $query->where('school_class_id', $schoolClass->id))->first();
        if ($lesson === null) {
            throw ValidationException::withMessages(['lesson_id' => 'Choose a lesson in this class and workspace.']);
        }
        Assignment::create([...$data, 'school_class_id' => $schoolClass->id, 'teacher_id' => $request->user()->id]);

        return back()->with('message', 'Assignment shared with your class.');
    }

    public function update(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->authorizeClass($request->user(), $assignment->schoolClass);
        abort_unless(Assignment::query()->visibleTo($request->user())->whereKey($assignment->id)->exists(), 404);
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $assignment->update(['archived_at' => $data['archived'] ? now() : null]);

        return back()->with('message', $data['archived'] ? 'Assignment archived.' : 'Assignment restored.');
    }

    private function authorizeClass(User $user, SchoolClass $schoolClass): void
    {
        abort_unless($user->role === 'admin' || ($user->role === 'teacher' && $user->taughtClasses()->whereKey($schoolClass->id)->exists()), 403);
    }
}
