<?php

namespace App\Http\Middleware;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role')],
            'workspace' => ['is_trial' => Lesson::currentWorkspace() !== 'main', 'can_resume' => $request->session()->has('studio_trial')],
            'flash' => ['message' => fn () => $request->session()->get('message')]];
    }
}
