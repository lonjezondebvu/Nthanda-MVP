<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_learners_land_on_their_daily_learning_dashboard(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        $this->post('/login', ['email' => $learner->email, 'password' => 'password'])->assertRedirect('/today');
    }

    public function test_password_changes_invalidate_existing_authenticated_sessions(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        $oldHash = $learner->password;
        $learner->forceFill(['password' => 'new password for this account'])->save();
        $this->actingAs($learner)->withSession(['password_hash_web' => $oldHash])->get('/today')->assertRedirect('/login');
        $this->assertGuest();
    }
}
