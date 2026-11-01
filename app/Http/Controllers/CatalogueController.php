<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogueController extends Controller
{
    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('school_classes', 'name')],
        ]);
        SchoolClass::create([...$data, 'position' => $this->nextPosition(SchoolClass::query())]);

        return back()->with('message', 'Class added.');
    }

    public function updateClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('school_classes', 'name')->ignore($schoolClass->id)],
        ]);
        $schoolClass->update($data);

        return back()->with('message', 'Class updated.');
    }

    public function destroyClass(SchoolClass $schoolClass): RedirectResponse
    {
        if ($schoolClass->subjects()->exists() || $schoolClass->learners()->exists()) {
            return back()->withErrors(['catalogue' => 'Move or remove subjects and students in this class before deleting it.']);
        }
        $schoolClass->delete();

        return back()->with('message', 'Class deleted.');
    }

    public function storeSubject(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('subjects', 'name')->where('school_class_id', $schoolClass->id)],
        ]);
        $schoolClass->subjects()->create([...$data, 'position' => $this->nextPosition($schoolClass->subjects()->getQuery())]);

        return back()->with('message', 'Subject added.');
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('subjects', 'name')->where('school_class_id', $subject->school_class_id)->ignore($subject->id)],
        ]);
        $subject->update($data);

        return back()->with('message', 'Subject updated.');
    }

    public function destroySubject(Subject $subject): RedirectResponse
    {
        if ($subject->units()->exists()) {
            return back()->withErrors(['catalogue' => 'Remove every unit in this subject before deleting it.']);
        }
        $subject->delete();

        return back()->with('message', 'Subject deleted.');
    }

    public function storeUnit(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160', Rule::unique('units', 'title')->where('subject_id', $subject->id)],
        ]);
        $subject->units()->create([...$data, 'position' => $this->nextPosition($subject->units()->getQuery())]);

        return back()->with('message', 'Unit added.');
    }

    public function updateUnit(Request $request, Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160', Rule::unique('units', 'title')->where('subject_id', $unit->subject_id)->ignore($unit->id)],
        ]);
        $unit->update($data);

        return back()->with('message', 'Unit updated.');
    }

    public function destroyUnit(Unit $unit): RedirectResponse
    {
        if (Lesson::withoutGlobalScope('workspace')->where('unit_id', $unit->id)->exists()) {
            return back()->withErrors(['catalogue' => 'Remove every lesson from this unit before deleting it.']);
        }
        $unit->delete();

        return back()->with('message', 'Unit deleted.');
    }

    /**
     * @param  Builder<SchoolClass>|Builder<Subject>|Builder<Unit>  $query
     */
    private function nextPosition(Builder $query): int
    {
        return ((int) $query->max('position')) + 1;
    }
}
