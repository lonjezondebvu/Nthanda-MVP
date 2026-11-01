<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\ContentSetting;
use App\Models\Lesson;
use App\Models\PortfolioEntry;
use App\Models\ProgressRecord;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\AssessmentMarker;
use App\Services\LessonAssembler;
use App\Services\PackageImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LessonController extends Controller
{
    public function __construct(private LessonAssembler $assembler) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $lessons = Lesson::query()->visibleTo($user)->latest()->get()->map(function (Lesson $lesson) {
            $data = $this->assembler->assemble($lesson);

            return [...$lesson->only('id', 'manifest_id', 'title', 'version', 'created_at', 'unit_id'), 'active_count' => $data['active_count'], 'publication' => $data['publication'], 'logo_url' => $data['logo_url'], 'syllabus' => $data['syllabus']];
        });

        return Inertia::render('Library', [
            'lessons' => $lessons,
            'admin' => $request->is('admin/*'),
            'catalogue' => $this->catalogue($user, $lessons),
            'unplaced' => $lessons->filter(fn (array $lesson): bool => $lesson['unit_id'] === null)->values(),
            'needsClass' => $user->role === 'learner' && $user->school_class_id === null,
        ]);
    }

    public function importPage()
    {
        return Inertia::render('Import');
    }

    public function import(Request $request, PackageImporter $importer)
    {
        $request->validate(['package' => 'required|file|max:131072|extensions:zip']);
        $result = $importer->import($request->file('package')->getRealPath());

        return redirect('/admin/lessons/'.$result['lesson']->id)->with('message', $result['duplicate'] ? 'This exact package is already imported. Opened the existing version.' : 'Package imported. Review the report before previewing.');
    }

    private function publicData(Lesson $lesson, bool $admin = false): array
    {
        $data = $this->assembler->assemble($lesson, $admin);
        unset($data['assets']);

        return $data;
    }

    public function manage(Lesson $lesson)
    {
        return Inertia::render('Manage', ['lesson' => $this->publicData($lesson, true), 'report' => $lesson->report]);
    }

    public function settings(Request $request, Lesson $lesson)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['section', 'block', 'media'])], 'item_id' => 'required|string', 'enabled' => 'sometimes|boolean', 'deleted' => 'sometimes|boolean']);
        $source = $this->assembler->source($lesson);
        $known = match ($data['kind']) {
            'section' => array_column($source['sections'], 'id'),
            'block' => array_merge(...array_map(fn ($s) => array_column($s['blocks'], 'id'), $source['sections'])),
            'media' => array_column($source['media'], 'id'),
        };
        abort_unless(in_array($data['item_id'], $known), 404);
        if ($data['kind'] !== 'section') {
            unset($data['deleted']);
        }
        $key = ['lesson_id' => $lesson->id, 'kind' => $data['kind'], 'item_id' => $data['item_id']];
        ContentSetting::updateOrCreate($key, array_intersect_key($data, array_flip(['enabled', 'deleted'])));

        return back()->with('message', 'Content settings saved. Learner history is preserved.');
    }

    public function order(Request $request, Lesson $lesson)
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'required|string|distinct'])['ids'];
        $known = array_column($this->assembler->source($lesson)['sections'], 'id');
        $sorted = $ids;
        sort($known);
        sort($sorted);
        abort_unless($known === $sorted, 422, 'Reordering must contain every section ID exactly once.');
        DB::transaction(function () use ($lesson, $ids) {
            foreach ($ids as $position => $id) {
                ContentSetting::updateOrCreate(['lesson_id' => $lesson->id, 'kind' => 'section', 'item_id' => $id], ['position' => $position + 1]);
            }
        });

        return back()->with('message', 'Section order saved. Stable content IDs and progress are unchanged.');
    }

    public function place(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
        ]);
        $lesson->update(['title' => $data['title'], 'unit_id' => $data['unit_id']]);

        return back()->with('message', $data['unit_id'] ? 'Lesson saved in the catalogue.' : 'Lesson removed from its unit. Learner progress is unchanged.');
    }

    public function destroyPackage(Lesson $lesson)
    {
        $hasHistory = ProgressRecord::where('lesson_id', $lesson->id)->exists() || AssessmentAttempt::where('lesson_id', $lesson->id)->exists() || PortfolioEntry::where('lesson_id', $lesson->id)->exists();
        if ($hasHistory) {
            return back()->withErrors(['lesson' => 'This lesson has learner progress or portfolio evidence, so the package stays in the library.']);
        }
        $directory = $lesson->directory;
        $lesson->delete();
        Storage::disk('local')->deleteDirectory($directory);

        return redirect('/admin/lessons')->with('message', 'Lesson package deleted.');
    }

    public function overview(Request $request, Lesson $lesson)
    {
        $this->visible($request, $lesson);

        return Inertia::render('Overview', ['lesson' => $this->publicData($lesson), 'progress' => $this->progressData($request, $lesson)]);
    }

    public function section(Request $request, Lesson $lesson, string $section)
    {
        $this->visible($request, $lesson);
        $data = $this->publicData($lesson);
        $selected = collect($data['sections'])->firstWhere('id', $section);

        return Inertia::render('Player', ['lesson' => $data, 'section' => $selected, 'unavailable' => $selected === null, 'progress' => $this->progressData($request, $lesson)]);
    }

    public function assessment(Request $request, Lesson $lesson)
    {
        $this->visible($request, $lesson);

        return Inertia::render('Assessment', ['lesson' => $this->publicData($lesson), 'attempts' => AssessmentAttempt::where('lesson_id', $lesson->id)->where('user_id', $request->user()->id)->latest()->limit(10)->get(['id', 'kind', 'status', 'result', 'created_at'])]);
    }

    public function progress(Request $request, Lesson $lesson)
    {
        $this->visible($request, $lesson);
        $data = $request->validate(['section_id' => 'required|string', 'read' => 'sometimes|boolean', 'confidence' => 'sometimes|nullable|integer|between:1,3', 'activity_id' => 'sometimes|string']);
        $section = collect($this->assembler->assemble($lesson)['sections'])->firstWhere('id', $data['section_id']);
        abort_unless($section, 404);
        $record = ProgressRecord::firstOrNew(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'section_id' => $data['section_id']]);
        if (isset($data['activity_id'])) {
            abort_unless(in_array($data['activity_id'], array_column($section['activities'], 'id')), 422);
            $record->activities = array_values(array_unique([...($record->activities ?? []), $data['activity_id']]));
        }
        foreach (['read', 'confidence'] as $key) {
            if (array_key_exists($key, $data)) {
                $record->$key = $data[$key];
            }
        }
        $record->save();

        return back()->with('message', 'Your learning record has been saved.');
    }

    private function progressData(Request $request, Lesson $lesson): array
    {
        return ProgressRecord::where('user_id', $request->user()->id)->where('lesson_id', $lesson->id)->get()->keyBy('section_id')->toArray();
    }

    public function mark(Request $request, Lesson $lesson, AssessmentMarker $marker)
    {
        $this->visible($request, $lesson);
        $data = $request->validate(['responses' => 'required|array|max:200']);
        // Bound nested input as well as HTTP request size.
        abort_if(strlen(json_encode($data['responses'])) > 100000, 422);
        $result = $marker->mark($lesson, $data['responses']);
        AssessmentAttempt::create(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => $data['responses'], 'result' => $result]);

        return back()->with('message', 'Automatic assessment marked. Reading and confidence records are separate.');
    }

    public function written(Request $request, Lesson $lesson)
    {
        $this->visible($request, $lesson);
        $data = $request->validate(['response' => 'required|string|max:50000']);
        AssessmentAttempt::create(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => $data]);

        return back()->with('message', 'Written response saved. Awaiting teacher review.');
    }

    public function asset(Request $request, Lesson $lesson, string $key)
    {
        $this->visible($request, $lesson);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $key), 404);
        $asset = $this->assembler->assemble($lesson)['assets'][$key] ?? null;
        abort_unless($asset, 404);
        $path = Storage::disk('local')->path($lesson->directory.'/'.$asset['path']);
        abort_unless(is_file($path), 404);
        $headers = ['Content-Type' => $asset['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache', 'Referrer-Policy' => 'no-referrer'];
        if ($asset['mime'] === 'text/html') {
            $headers['Content-Security-Policy'] = "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'";
        }
        if ($asset['mime'] === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
        }

        return response()->file($path, $headers);
    }

    private function visible(Request $request, Lesson $lesson): void
    {
        abort_unless($lesson->visibleTo($request->user()), 403);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @return Collection<int, array<string, mixed>>
     */
    private function catalogue(User $user, Collection $lessons): Collection
    {
        $classes = SchoolClass::query()
            ->with(['subjects.units'])
            ->when($user->role === 'learner', fn ($query) => $query->whereKey($user->school_class_id ?? 0))
            ->when($user->role === 'teacher', fn ($query) => $query->whereIn('id', $user->taughtClasses()->select('school_classes.id')))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $byUnit = [];
        foreach ($lessons as $lesson) {
            if ($lesson['unit_id'] !== null) {
                $byUnit[$lesson['unit_id']][] = $lesson;
            }
        }

        return $classes->map(function (SchoolClass $class) use ($byUnit): array {
            return [
                'id' => $class->id,
                'name' => $class->name,
                'subjects' => $class->subjects->map(function (Subject $subject) use ($byUnit): array {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'units' => $subject->units->map(function (Unit $unit) use ($byUnit): array {
                            return [
                                'id' => $unit->id,
                                'title' => $unit->title,
                                'lessons' => $byUnit[$unit->id] ?? [],
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values();
    }
}
