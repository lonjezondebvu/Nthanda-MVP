<?php

namespace App\Http\Controllers;

use App\Models\GlossaryTerm;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GlossaryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'lesson_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $canManage = in_array($user->role, ['teacher', 'admin'], true);
        $search = trim($filters['search'] ?? '');
        $lessonId = $filters['lesson_id'] ?? null;

        return Inertia::render('Glossary', [
            'canManage' => $canManage,
            'filters' => ['search' => $search, 'lesson_id' => $lessonId],
            'lessons' => Lesson::query()->visibleTo($user)->orderBy('title')->get(['id', 'title']),
            'terms' => GlossaryTerm::query()->visibleTo($user)->with('lesson:id,title')
                ->when($lessonId !== null, fn (Builder $terms): Builder => $terms->where('lesson_id', $lessonId))
                ->when($search !== '', fn (Builder $terms): Builder => $terms->where('term', 'like', '%'.$search.'%'))
                ->orderBy('term')->orderBy('id')->paginate(24)->withQueryString()->through(fn (GlossaryTerm $term): array => [
                    'id' => $term->id, 'lesson_id' => $term->lesson_id, 'lesson_title' => $term->lesson->title, 'term' => $term->term,
                    'explanation_en' => $term->explanation_en, 'explanation_ny' => $term->explanation_ny, 'pronunciation_hint' => $term->pronunciation_hint,
                    'reviewed' => $term->reviewed_at !== null, 'reviewed_at' => $term->reviewed_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, Lesson $lesson): RedirectResponse
    {
        $this->authorizeLesson($request, $lesson);
        GlossaryTerm::create([...$this->validatedContent($request), 'lesson_id' => $lesson->id]);

        return back()->with('message', $request->boolean('reviewed') ? 'Reviewed term published to your learners.' : 'Glossary draft saved for review.');
    }

    public function update(Request $request, GlossaryTerm $glossaryTerm): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin'], true), 403);
        abort_unless(GlossaryTerm::query()->visibleTo($request->user())->whereKey($glossaryTerm->id)->exists(), 404);
        $glossaryTerm->update($this->validatedContent($request));

        return back()->with('message', $request->boolean('reviewed') ? 'Reviewed term published to your learners.' : 'Changes saved as a draft. Review before publishing again.');
    }

    private function authorizeLesson(Request $request, Lesson $lesson): void
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin'], true), 403);
        abort_unless(Lesson::query()->visibleTo($request->user())->whereKey($lesson->id)->exists(), 404);
    }

    /** @return array{term: string, explanation_en: string, explanation_ny: ?string, pronunciation_hint: ?string, reviewer_id: ?int, reviewed_at: mixed} */
    private function validatedContent(Request $request): array
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:160'],
            'explanation_en' => ['required', 'string', 'max:4000'],
            'explanation_ny' => ['nullable', 'string', 'max:4000'],
            'pronunciation_hint' => ['nullable', 'string', 'max:255'],
            'reviewed' => ['sometimes', 'boolean'],
        ]);

        return ['term' => $data['term'], 'explanation_en' => $data['explanation_en'], 'explanation_ny' => $data['explanation_ny'] ?? null, 'pronunciation_hint' => $data['pronunciation_hint'] ?? null,
            'reviewer_id' => $request->boolean('reviewed') ? $request->user()->id : null, 'reviewed_at' => $request->boolean('reviewed') ? now() : null];
    }
}
