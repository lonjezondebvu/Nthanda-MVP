<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_pages_are_available_to_guests(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ForgotPassword'));
        $this->get('/reset-password/token?email=learner@example.com')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ResetPassword')->where('token', 'token')->where('email', 'learner@example.com'));
    }

    public function test_recovery_sends_only_to_active_accounts_and_uses_a_generic_response(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['deactivated_at' => now()]);
        $message = 'If an active account matches that email, a password reset link will arrive shortly. Check your spam folder too.';
        foreach ([$active->email, $inactive->email, 'missing@example.com'] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])->assertRedirect('/forgot-password')->assertSessionHas('message', $message);
        }
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_changes_password_rotates_remember_token_and_revokes_database_sessions(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $token = Password::createToken($user);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'previous-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'A unique new passphrase', 'password_confirmation' => 'A unique new passphrase'];

        $this->post('/reset-password', $payload)->assertRedirect('/login');

        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'previous-session']);
        $this->assertGuest();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_expired_tokens_and_deactivated_accounts_cannot_reset_passwords(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'Another new passphrase', 'password_confirmation' => 'Another new passphrase'];
        $user->forceFill(['deactivated_at' => now()])->save();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $user->forceFill(['deactivated_at' => null])->save();
        $this->travel(61)->minutes();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_recovery_validates_input_and_throttles_requests(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'invalid'])->assertSessionHasErrors('email');
        $this->post('/reset-password', [])->assertSessionHasErrors(['email', 'token', 'password']);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertTooManyRequests();
        Notification::assertNothingSent();
    }
}
