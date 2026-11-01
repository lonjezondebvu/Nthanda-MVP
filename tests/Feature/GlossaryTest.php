<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GlossaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_learners_see_only_reviewed_terms_in_their_class_and_cannot_publish(): void
    {
        $lesson = $this->lesson();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $lesson->unit->subject->school_class_id]);
        $published = GlossaryTerm::factory()->create(['lesson_id' => $lesson->id, 'term' => 'Leaf', 'reviewed_at' => now()]);
        GlossaryTerm::factory()->create(['lesson_id' => $lesson->id, 'term' => 'Private draft', 'reviewed_at' => null]);
        GlossaryTerm::factory()->create(['term' => 'Other class', 'reviewed_at' => now()]);

        $this->actingAs($learner)->get('/glossary')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Glossary')->where('canManage', false)->has('terms.data', 1)->where('terms.data.0.term', 'Leaf'));
        $this->post('/lessons/'.$lesson->id.'/glossary', $this->payload())->assertForbidden();
        $this->patch('/glossary/'.$published->id, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('glossary_terms', 3);
    }

    public function test_teacher_edits_unpublish_content_until_explicitly_reviewed_again(): void
    {
        $lesson = $this->lesson();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($lesson->unit->subject->school_class_id);
        $this->actingAs($teacher)->post('/lessons/'.$lesson->id.'/glossary', $this->payload(['reviewed' => true]))->assertRedirect()->assertSessionHasNoErrors();
        $term = GlossaryTerm::sole();
        $this->assertSame($teacher->id, $term->reviewer_id);
        $this->assertNotNull($term->reviewed_at);

        $this->patch('/glossary/'.$term->id, $this->payload(['explanation_en' => 'A revised explanation.']))->assertRedirect()->assertSessionHasNoErrors();
        $term->refresh();
        $this->assertNull($term->reviewed_at);
        $this->assertNull($term->reviewer_id);
        $this->assertSame('A revised explanation.', $term->explanation_en);
        $this->get('/glossary?search=Leaf')->assertInertia(fn (AssertableInertia $page) => $page->has('terms.data', 1)->where('terms.data.0.reviewed', false));

        $reviewer = User::factory()->create(['role' => 'teacher']);
        $reviewer->taughtClasses()->attach($lesson->unit->subject->school_class_id);
        $this->actingAs($reviewer)->patch('/glossary/'.$term->id, $this->payload(['reviewed' => true]))->assertRedirect();
        $this->assertSame($reviewer->id, $term->refresh()->reviewer_id);
    }

    public function test_cross_class_and_cross_workspace_updates_are_refused(): void
    {
        $lesson = $this->lesson();
        $term = GlossaryTerm::factory()->create(['lesson_id' => $lesson->id]);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->patch('/glossary/'.$term->id, $this->payload())->assertNotFound();
        $this->post('/lessons/'.$lesson->id.'/glossary', $this->payload())->assertNotFound();
        $teacher->taughtClasses()->attach($lesson->unit->subject->school_class_id);
        $this->withSession(['studio_workspace' => 'other-workspace'])->patch('/glossary/'.$term->id, $this->payload())->assertNotFound();
        $this->assertDatabaseHas('glossary_terms', ['id' => $term->id, 'term' => $term->term]);
    }

    public function test_invalid_content_is_rejected_and_filters_do_not_reveal_hidden_lessons(): void
    {
        $lesson = $this->lesson();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($lesson->unit->subject->school_class_id);
        $this->actingAs($teacher)->post('/lessons/'.$lesson->id.'/glossary', $this->payload(['term' => str_repeat('a', 161), 'explanation_en' => '', 'explanation_ny' => str_repeat('b', 4001)]))->assertSessionHasErrors(['term', 'explanation_en', 'explanation_ny']);
        $this->assertDatabaseCount('glossary_terms', 0);
        $hidden = GlossaryTerm::factory()->create(['reviewed_at' => now()]);

        $this->get('/glossary?lesson_id='.$hidden->lesson_id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('terms.data', 0));
    }

    public function test_term_search_and_lesson_filter_return_only_matching_visible_content(): void
    {
        $lesson = $this->lesson();
        $admin = User::factory()->create(['role' => 'admin']);
        GlossaryTerm::factory()->create(['lesson_id' => $lesson->id, 'term' => 'Leaf', 'reviewed_at' => now()]);
        GlossaryTerm::factory()->create(['lesson_id' => $lesson->id, 'term' => 'Roots', 'reviewed_at' => now()]);
        GlossaryTerm::factory()->create(['term' => 'Leaf', 'reviewed_at' => now()]);

        $this->actingAs($admin)->get('/glossary?search=Leaf&lesson_id='.$lesson->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('terms.data', 1)->where('terms.data.0.term', 'Leaf')->where('terms.data.0.lesson_id', $lesson->id));
    }

    public function test_glossary_requires_authentication(): void
    {
        $this->get('/glossary')->assertRedirect('/login');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace(['term' => 'Leaf', 'explanation_en' => 'A part of a plant.', 'explanation_ny' => null, 'pronunciation_hint' => null], $overrides);
    }

    private function lesson(): Lesson
    {
        $title = fake()->uuid();

        return Lesson::create(['manifest_id' => $title, 'title' => $title, 'version' => '1', 'sha256' => hash('sha256', $title), 'directory' => 'imports/'.$title, 'unit_id' => Unit::factory()->create()->id, 'report' => []]);
    }
}
