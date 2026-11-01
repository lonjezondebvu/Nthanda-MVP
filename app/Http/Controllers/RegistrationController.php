<?php

namespace App\Http\Controllers;

use App\Enums\Disability;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\StudentProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Register', [
            'classes' => SchoolClass::query()->orderBy('position')->orderBy('id')->get(['id', 'name']),
            'disabilities' => Disability::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'disability_note' => $request->filled('disability_note') ? trim($request->string('disability_note')->toString()) : null,
        ]);
        $validator = validator($request->all(), [
            ...StudentProfile::rules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $validator->after(fn (Validator $validator) => StudentProfile::after($validator));
        $data = $validator->validate();
        $user = User::create([
            ...StudentProfile::normalize($data),
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/lessons');
    }
}
