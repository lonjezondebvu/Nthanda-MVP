<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClassReportController extends Controller
{
    public function __invoke(Request $request, SchoolClass $schoolClass): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'admin' || ($user->role === 'teacher' && $user->taughtClasses()->whereKey($schoolClass->id)->exists()), 403);
        $workspace = Lesson::currentWorkspace();

        return response()->streamDownload(function () use ($schoolClass, $workspace): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Learner name', 'Lesson title', 'Recorded sections read', 'Latest automatic status', 'Pending written count', 'Most recent server activity (UTC)'], ',', '"', '');
            User::query()->where('role', 'learner')->where('school_class_id', $schoolClass->id)->select(['id', 'name'])->chunkById(50, function (Collection $learners) use ($schoolClass, $workspace, $stream): void {
                $learnerIds = $learners->modelKeys();
                $readColumn = (new ProgressRecord)->getConnection()->getQueryGrammar()->wrap('read');
                $lessons = Lesson::query()->where('workspace', $workspace)->whereHas('unit.subject', fn ($query) => $query->where('school_class_id', $schoolClass->id))->select(['id', 'title']);
                foreach ($lessons->lazyById(50) as $lesson) {
                    $progress = ProgressRecord::query()->where('lesson_id', $lesson->id)->whereIn('user_id', $learnerIds)
                        ->selectRaw('user_id, SUM(CASE WHEN '.$readColumn.' = true THEN 1 ELSE 0 END) as read_count, MAX(updated_at) as last_activity')->groupBy('user_id')->get()->keyBy('user_id');
                    $activity = AssessmentAttempt::query()->where('lesson_id', $lesson->id)->whereIn('user_id', $learnerIds)
                        ->selectRaw("user_id, SUM(CASE WHEN kind = 'written' AND status = 'pending' THEN 1 ELSE 0 END) as pending_count, MAX(created_at) as last_activity")->groupBy('user_id')->get()->keyBy('user_id');
                    $latest = AssessmentAttempt::query()->where('lesson_id', $lesson->id)->whereIn('user_id', $learnerIds)->where('kind', 'automatic')
                        ->where('id', '=', function (Builder $query): void {
                            $query->select('latest.id')->from('assessment_attempts as latest')->whereColumn('latest.user_id', 'assessment_attempts.user_id')->whereColumn('latest.lesson_id', 'assessment_attempts.lesson_id')
                                ->where('latest.kind', 'automatic')->orderByDesc('latest.created_at')->orderByDesc('latest.id')->limit(1);
                        })->get(['user_id', 'result'])->keyBy('user_id');
                    foreach ($learners as $learner) {
                        $attempt = $latest->get($learner->id);
                        $results = is_array($attempt?->result) && array_is_list($attempt->result) ? $attempt->result : [];
                        $status = $attempt === null ? 'not_assessed' : ($results !== [] && collect($results)->every(fn (array $result): bool => ($result['passed'] ?? false) === true) ? 'achieved' : 'not_yet');
                        $read = $progress->get($learner->id);
                        $assessment = $activity->get($learner->id);
                        $lastActivity = collect([$read?->last_activity, $assessment?->last_activity])->filter()->max();
                        fputcsv($stream, [$this->safeCell($learner->name), $this->safeCell($lesson->title), (int) ($read?->read_count ?? 0), $status, (int) ($assessment?->pending_count ?? 0), $lastActivity ?? ''], ',', '"', '');
                    }
                }
            });
            fclose($stream);
        }, 'class-'.$schoolClass->id.'-progress.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+@\-\s\x00-\x20]/u', $value) === 1 ? "'".$value : $value;
    }
}
