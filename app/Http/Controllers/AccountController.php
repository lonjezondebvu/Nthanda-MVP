<?php

namespace App\Http\Controllers;

use App\Enums\Disability;
use App\Models\SchoolClass;
use App\Support\StudentProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->role === 'learner', 403);
        $user = $request->user();

        return Inertia::render('Account', [
            'classes' => SchoolClass::query()->orderBy('position')->orderBy('id')->get(['id', 'name']),
            'disabilities' => Disability::options(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'school_class_id' => $user->school_class_id,
                'disabilities' => $user->disabilities ?? [],
                'disability_note' => $user->disability_note,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'learner', 403);
        if (! $request->filled('password')) {
            $request->merge(['password' => null, 'password_confirmation' => null]);
        }
        $request->merge([
            'disability_note' => $request->filled('disability_note') ? trim($request->string('disability_note')->toString()) : null,
        ]);
        $validator = validator($request->all(), [
            ...StudentProfile::rules(),
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
        $validator->after(fn (Validator $validator) => StudentProfile::after($validator));
        $data = $validator->validate();
        $profile = StudentProfile::normalize($data);
        if (is_string($data['password'] ?? null) && $data['password'] !== '') {
            $profile['password'] = $data['password'];
        }
        $request->user()->update($profile);

        return back()->with('message', 'Your account has been updated.');
    }
}
