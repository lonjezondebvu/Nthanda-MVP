<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\PortfolioEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Portfolio', [
            'canSubmit' => $user->role === 'learner',
            'canReview' => in_array($user->role, ['teacher', 'admin'], true),
            'lessons' => $user->role === 'learner' ? Lesson::query()->visibleTo($user)->orderBy('title')->get(['id', 'title']) : [],
            'entries' => PortfolioEntry::query()->visibleTo($user)->with(['lesson:id,title', 'learner:id,name', 'reviewer:id,name'])->latest('id')->paginate(20)->through(fn (PortfolioEntry $entry): array => [
                'id' => $entry->id, 'title' => $entry->title, 'reflection' => $entry->reflection, 'lesson_title' => $entry->lesson->title,
                'learner_name' => $entry->learner->name, 'status' => $entry->status, 'feedback' => $entry->feedback,
                'reviewer_name' => $entry->reviewer?->name, 'reviewed_at' => $entry->reviewed_at?->toIso8601String(),
                'created_at' => $entry->created_at->toIso8601String(), 'attachment_name' => $entry->attachment_name,
                'attachment_url' => $entry->attachment_path ? route('portfolio.attachment', $entry) : null,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'learner', 403);
        $data = $request->validate([
            'lesson_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:160'], 'reflection' => ['required', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,audio/mpeg,audio/mp4,audio/x-m4a,audio/ogg,audio/wav,audio/x-wav,application/pdf', 'extensions:jpg,jpeg,png,webp,mp3,m4a,ogg,wav,pdf'],
        ]);
        $lesson = Lesson::query()->visibleTo($request->user())->findOrFail($data['lesson_id']);
        $file = $request->file('attachment');
        $extensionsByMime = [
            'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'],
            'audio/mpeg' => ['mp3'], 'audio/mp4' => ['m4a'], 'audio/x-m4a' => ['m4a'],
            'audio/ogg' => ['ogg'], 'audio/wav' => ['wav'], 'audio/x-wav' => ['wav'], 'application/pdf' => ['pdf'],
        ];
        if ($file && ! in_array(strtolower($file->getClientOriginalExtension()), $extensionsByMime[$file->getMimeType()] ?? [], true)) {
            throw ValidationException::withMessages(['attachment' => 'The file contents must match its extension. Choose a photo, audio recording, or PDF.']);
        }
        $path = $file?->store('portfolio/'.$request->user()->id, 'local');
        abort_if($file !== null && $path === false, 503, 'Your attachment could not be stored. Please try again.');
        try {
            PortfolioEntry::create([
                'lesson_id' => $lesson->id, 'user_id' => $request->user()->id, 'school_class_id' => $request->user()->school_class_id,
                'title' => $data['title'], 'reflection' => $data['reflection'], 'attachment_path' => $path,
                'attachment_name' => $file ? 'evidence.'.$file->extension() : null,
            ]);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('message', 'Your work is saved and ready for teacher feedback.');
    }

    public function review(Request $request, PortfolioEntry $portfolioEntry): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin'], true), 403);
        abort_unless(PortfolioEntry::query()->visibleTo($request->user())->whereKey($portfolioEntry->id)->exists(), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['accepted', 'needs_another_try'])], 'feedback' => ['required', 'string', 'max:5000']]);
        $portfolioEntry->update([...$data, 'reviewer_id' => $request->user()->id, 'reviewed_at' => now()]);

        return back()->with('message', 'Your feedback has been shared with the learner.');
    }

    public function attachment(Request $request, PortfolioEntry $portfolioEntry): StreamedResponse
    {
        abort_unless(PortfolioEntry::query()->visibleTo($request->user())->whereKey($portfolioEntry->id)->exists(), 404);
        abort_unless($portfolioEntry->attachment_path && Storage::disk('local')->exists($portfolioEntry->attachment_path), 404);

        return Storage::disk('local')->download($portfolioEntry->attachment_path, $portfolioEntry->attachment_name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
