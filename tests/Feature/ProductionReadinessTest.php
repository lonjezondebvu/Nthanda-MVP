<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_configuration_passes_without_exposing_secrets(): void
    {
        $this->configureProduction();
        $this->artisan('nthanda:check-production')->expectsOutputToContain('PASS')->doesntExpectOutputToContain(config('app.key'))->assertSuccessful();
    }

    public function test_unsafe_configuration_and_demo_accounts_fail_readiness_checks(): void
    {
        $this->configureProduction();
        config(['app.debug' => true, 'app.url' => 'http://school.example', 'session.secure' => false, 'mail.default' => 'log']);
        User::factory()->create(['email' => 'admin@nthanda.test']);
        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();
    }

    public function test_mail_failover_to_logs_is_not_accepted_as_production_delivery(): void
    {
        $this->configureProduction();
        config(['mail.default' => 'failover']);
        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();
    }

    #[DataProvider('invalidEncryptionSettings')]
    public function test_invalid_encryption_settings_fail_without_exposing_the_key(string $key, string $cipher): void
    {
        $this->configureProduction();
        config(['app.key' => $key, 'app.cipher' => $cipher]);

        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->doesntExpectOutputToContain($key)->assertFailed();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidEncryptionSettings(): array
    {
        return [
            'wrong key length' => ['private-key-value', 'AES-256-CBC'],
            'invalid base64 key' => ['base64:invalid%%', 'AES-256-CBC'],
            'unsupported cipher' => [str_repeat('x', 32), 'NOT-A-CIPHER'],
        ];
    }

    public function test_pending_application_migrations_fail_without_running_them(): void
    {
        $this->configureProduction();
        $migration = '2026_11_01_120406_create_glossary_terms_table';
        DB::table('migrations')->where('migration', $migration)->delete();

        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();

        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
    }

    public function test_missing_feature_table_fails_even_when_migrations_are_recorded(): void
    {
        $this->configureProduction();
        Schema::drop('glossary_terms');

        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();

        $this->assertFalse(Schema::hasTable('glossary_terms'));
    }

    public function test_missing_publication_columns_fail_even_when_migrations_are_recorded(): void
    {
        $this->configureProduction();
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('publication_review');
        });

        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();

        $this->assertFalse(Schema::hasColumn('lessons', 'publication_review'));
    }

    public function test_preview_lessons_are_rejected_in_production(): void
    {
        $this->configureProduction();
        config(['learning.allow_preview' => true]);

        $this->artisan('nthanda:check-production')->expectsOutputToContain('FAIL')->assertFailed();
    }

    public function test_demo_seeder_refuses_production_without_creating_accounts(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->app->make(DatabaseSeeder::class)->run();
            $this->fail('The demo seeder should refuse production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Demo accounts must not be seeded', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
    }

    private function configureProduction(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false,
            'learning.allow_preview' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.cipher' => 'AES-256-CBC',
            'app.url' => 'https://school.example',
            'session.secure' => true,
            'session.http_only' => true,
            'session.driver' => 'database',
            'mail.default' => 'smtp',
            'mail.from.address' => 'learning@school.example',
        ]);
        File::partialMock();
        File::shouldReceive('exists')->with(public_path('build/manifest.json'))->andReturn(true);
        File::shouldReceive('exists')->with(public_path('hot'))->andReturn(false);
    }
}
