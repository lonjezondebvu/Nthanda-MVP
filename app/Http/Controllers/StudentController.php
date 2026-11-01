<?php

namespace App\Http\Controllers;

use App\Enums\Disability;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(): Response
    {
        $students = User::query()->where('role', 'learner')->with('schoolClass')->orderBy('name')->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'school_class_id' => $user->school_class_id,
            'disabilities' => collect($user->disabilities ?? [])->map(fn (string $value): string => Disability::from($value)->label())->values(),
            'disability_note' => $user->disability_note,
            'deactivated' => $user->deactivated_at !== null,
        ]);

        return Inertia::render('Students', [
            'students' => $students,
            'classes' => SchoolClass::query()->orderBy('position')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'learner', 404);
        $data = $request->validate([
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'deactivated' => ['required', 'boolean'],
        ]);
        $user->forceFill([
            'school_class_id' => $data['school_class_id'],
            'deactivated_at' => $data['deactivated'] ? ($user->deactivated_at ?? now()) : null,
        ])->save();

        return back()->with('message', 'Student record updated.');
    }
}
