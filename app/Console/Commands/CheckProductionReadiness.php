<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckProductionReadiness extends Command
{
    protected $signature = 'nthanda:check-production';

    protected $description = 'Read-only deployment checks; does not certify security or external service delivery';

    public function handle(): int
    {
        $checks = [
            'Production environment' => app()->environment('production'),
            'Debug mode disabled' => config('app.debug') === false,
            'Unreviewed preview lessons disabled' => config('learning.allow_preview') === false,
            'Valid application encryption key and cipher' => $this->hasValidEncryptionKey(),
            'HTTPS application URL' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https',
            'Secure HTTP-only session cookies' => config('session.secure') === true && config('session.http_only') === true,
            'Persistent server-side sessions' => in_array(config('session.driver'), ['database', 'file', 'redis', 'memcached', 'dynamodb'], true),
            'Production mail transport' => $this->hasDeliveryTransport((string) config('mail.default')),
            'Mail sender configured' => filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && ! str_ends_with((string) config('mail.from.address'), '@example.com'),
            'Built frontend assets' => File::exists(public_path('build/manifest.json')) && ! File::exists(public_path('hot')),
            'Writable application storage' => is_writable(storage_path('app')) && is_writable(storage_path('logs')) && is_writable(storage_path('framework')) && is_writable(base_path('bootstrap/cache')),
        ];

        try {
            DB::connection()->getPdo();
            $checks['Database reachable'] = true;
            $checks['Required application tables'] = collect(['users', 'lessons', 'password_reset_tokens', 'school_classes', 'subjects', 'units', 'class_teacher', 'progress_records', 'assessment_attempts', 'content_settings', 'offline_receipts', 'assignments', 'portfolio_entries', 'glossary_terms', 'lesson_publication_events'])
                ->every(fn (string $table): bool => Schema::hasTable($table));
            $migrator = app(Migrator::class);
            $checks['All application migrations applied'] = $migrator->repositoryExists()
                && array_diff(array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')])), $migrator->getRepository()->getRan()) === [];
            $checks['Required publication and account columns'] = Schema::hasColumns('lessons', ['workspace', 'unit_id', 'published_at', 'publication_review'])
                && Schema::hasColumns('users', ['school_class_id', 'deactivated_at']);
            $checks['No demo accounts'] = ! DB::table('users')->whereIn('email', ['admin@nthanda.test', 'learner@nthanda.test', 'teacher@nthanda.test'])->exists();
        } catch (Throwable) {
            $checks['Database and schema reachable'] = false;
        }

        $this->table(['Check', 'Result'], collect($checks)->map(fn (bool $passed, string $label): array => [$label, $passed ? 'PASS' : 'FAIL'])->values()->all());
        $this->line('These checks do not send mail or verify backups, TLS routing, monitoring, capacity, or accessibility. Validate those in the deployment environment.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function hasValidEncryptionKey(): bool
    {
        $key = config('app.key');
        $cipher = config('app.cipher');
        if (! is_string($key) || ! is_string($cipher)) {
            return false;
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && Encrypter::supported($key, $cipher);
    }

    /** @param list<string> $visited */
    private function hasDeliveryTransport(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $settings = config('mail.mailers.'.$mailer, []);
        $transport = $settings['transport'] ?? '';
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $settings['mailers'] ?? [];
            if ($children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (! $this->hasDeliveryTransport($child, [...$visited, $mailer])) {
                    return false;
                }
            }

            return true;
        }

        return in_array($transport, ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true);
    }
}
