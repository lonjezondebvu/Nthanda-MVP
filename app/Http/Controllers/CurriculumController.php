<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Services\CurriculumPackages;
use App\Services\LessonAssembler;
use App\Services\PackageImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CurriculumController extends Controller
{
    public function workspace(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => 'required|in:new,main,resume']);
        if ($data['mode'] === 'new') {
            $request->session()->put('studio_trial', 'trial-'.Str::uuid());
        }
        $workspace = $data['mode'] === 'main' ? 'main' : $request->session()->get('studio_trial', 'main');
        $request->session()->put('studio_workspace', $workspace);

        return redirect('/admin/lessons')->with('message', $workspace === 'main' ? 'Returned to your main library.' : 'Trial workspace ready. Your main library is preserved.');
    }

    public function importStream(Request $request, PackageImporter $importer): StreamedResponse
    {
        $request->validate(['package' => 'required|file|max:131072|extensions:zip']);
        $path = $request->file('package')->getRealPath();

        return response()->stream(function () use ($path, $importer): void {
            set_time_limit(300);
            $emit = static function (array $event): void {
                echo json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                if (! app()->runningUnitTests()) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            };
            try {
                $result = $importer->import($path, $emit);
                $emit(['stage' => 'complete', 'percent' => 100, 'message' => $result['duplicate'] ? 'This exact package is already imported. Opening its curriculum.' : 'Curriculum imported successfully.', 'url' => '/admin/lessons/'.$result['lesson']->id, 'report' => $result['lesson']->report]);
            } catch (ValidationException $exception) {
                $emit(['stage' => 'error', 'message' => collect($exception->errors())->flatten()->implode(' ')]);
            } catch (\Throwable $exception) {
                report($exception);
                $emit(['stage' => 'error', 'message' => 'The import could not finish. No partial curriculum was saved. Please retry or check the server log.']);
            }
        }, 200, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function edit(Lesson $lesson, LessonAssembler $assembler): Response
    {
        $data = $assembler->assemble($lesson, true);
        unset($data['assets']);

        return Inertia::render('EditCurriculum', ['lesson' => $data]);
    }

    public function storeVersion(Request $request, Lesson $lesson, CurriculumPackages $packages): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:250',
            'version' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'],
            'sections' => 'required|array|max:1000',
            'sections.*.id' => 'required|string|max:180',
            'sections.*.title' => 'required|string|max:250',
            'sections.*.blocks' => 'required|array|max:1500',
            'sections.*.blocks.*.id' => 'required|string|max:180',
            'sections.*.blocks.*.type' => 'required|in:heading,paragraph,list,table',
            'sections.*.blocks.*.text' => 'sometimes|nullable|string|max:50000',
            'sections.*.blocks.*.items' => 'sometimes|array|max:1000',
            'sections.*.blocks.*.items.*' => 'nullable|string|max:10000',
            'sections.*.blocks.*.headers' => 'sometimes|array|max:100',
            'sections.*.blocks.*.headers.*' => 'nullable|string|max:1000',
            'sections.*.blocks.*.rows' => 'sometimes|array|max:1000',
            'sections.*.blocks.*.rows.*' => 'array|max:100',
            'sections.*.blocks.*.rows.*.*' => 'nullable|string|max:10000',
        ]);
        $updated = $packages->createVersion($lesson, $data);

        return redirect('/admin/lessons/'.$updated->id)->with('message', 'New curriculum version saved. The previous version and learner records are preserved.');
    }

    public function download(Request $request, Lesson $lesson, CurriculumPackages $packages): BinaryFileResponse
    {
        $path = $packages->export($lesson);

        clearstatcache(true, $path);

        $headers = ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('inline')) {
            return response()->file($path, $headers)->setPrivate()->deleteFileAfterSend(true);
        }

        return response()->download($path, Str::slug($lesson->manifest_id.'-'.$lesson->version).'.zip', $headers)->setPrivate()->deleteFileAfterSend(true);
    }
}
