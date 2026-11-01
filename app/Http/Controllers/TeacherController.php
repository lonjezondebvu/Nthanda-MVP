<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeacherController extends Controller
{
    public function index(): Response
    {
        $teachers = User::query()->where('role', 'teacher')->with('taughtClasses:id,name')->orderBy('name')->get()->map(fn (User $teacher): array => [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'class_ids' => $teacher->taughtClasses->pluck('id')->all(),
            'deactivated' => $teacher->deactivated_at !== null,
        ]);

        return Inertia::render('Teachers', [
            'teachers' => $teachers,
            'classes' => SchoolClass::query()->orderBy('position')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'classes' => ['required', 'array', 'min:1'],
            'classes.*' => ['integer', 'distinct', Rule::exists('school_classes', 'id')],
        ]);
        $teacher = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $teacher->forceFill(['role' => 'teacher'])->save();
        $teacher->taughtClasses()->sync($data['classes']);

        return back()->with('message', 'Teacher account created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'teacher', 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'deactivated' => ['required', 'boolean'],
            'classes' => ['required', 'array', 'min:1'],
            'classes.*' => ['integer', 'distinct', Rule::exists('school_classes', 'id')],
        ]);
        $changes = [
            'name' => $data['name'],
            'deactivated_at' => $data['deactivated'] ? ($user->deactivated_at ?? now()) : null,
        ];
        if (is_string($data['password'] ?? null) && $data['password'] !== '') {
            $changes['password'] = $data['password'];
        }
        $user->forceFill($changes)->save();
        $user->taughtClasses()->sync($data['classes']);

        return back()->with('message', 'Teacher record updated.');
    }
}
