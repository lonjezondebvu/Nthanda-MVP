<?php

namespace Tests\Feature;

use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\ProgressRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_requires_an_assigned_teacher_or_administrator(): void
    {
        $assignment = Assignment::factory()->create();
        $url = '/teach/classes/'.$assignment->school_class_id.'/report';
        $this->get($url)->assertRedirect('/login');
        foreach (['learner', 'teacher'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url)->assertOk();
    }

    public function test_report_exports_only_current_class_workspace_and_latest_evidence_safely(): void
    {
        $assignment = Assignment::factory()->create();
        $lesson = $assignment->lesson;
        $lesson->update(['title' => '  =HYPERLINK("bad")']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($assignment->school_class_id);
        $learner = User::factory()->create(['role' => 'learner', 'name' => "\t+command", 'school_class_id' => $assignment->school_class_id, 'email' => 'private@example.com', 'disabilities' => ['seeing']]);
        User::factory()->create(['role' => 'learner', 'name' => 'Other class']);
        $hidden = Assignment::factory()->create(['school_class_id' => $assignment->school_class_id]);
        $hidden->lesson->update(['unit_id' => $lesson->unit_id, 'workspace' => 'trial', 'title' => 'Private workspace lesson']);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => true, 'updated_at' => '2026-01-02 12:00:00']);
        foreach ([true, false] as $passed) {
            AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => [], 'result' => [['passed' => $passed]], 'created_at' => '2026-01-01 12:00:00']);
        }
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => [], 'result' => [], 'created_at' => '2026-01-03 12:00:00']);
        $response = $this->actingAs($teacher)->get('/teach/classes/'.$assignment->school_class_id.'/report')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $rows);
        $this->assertSame("'\t+command", $rows[1][0]);
        $this->assertSame("'  =HYPERLINK(\"bad\")", $rows[1][1]);
        $this->assertSame(['1', 'not_yet', '1', '2026-01-03 12:00:00'], array_slice($rows[1], 2));
        $this->assertStringNotContainsString('private@example.com', $csv);
        $this->assertStringNotContainsString('seeing', $csv);
        $this->assertStringNotContainsString('Private workspace lesson', $csv);
        $this->assertStringNotContainsString('Other class', $csv);
    }
}
