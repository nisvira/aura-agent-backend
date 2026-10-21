<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-admin {email} {--name=} {--password=}', function (string $email) {
    $email = Str::lower($email);
    // Prompt by default so the password doesn't end up in shell history.
    $password = $this->option('password') ?? $this->secret('Password (min 8 characters)');

    if (mb_strlen((string) $password) < 8) {
        $this->error('Password must be at least 8 characters.');

        return 1;
    }

    $user = User::firstOrNew(['email' => $email]);
    $user->forceFill([
        'passport_id' => $user->passport_id ?? 'local:'.$email,
        'name' => $this->option('name') ?? $user->name ?? Str::before($email, '@'),
        'password' => $password,
        'is_admin' => true,
    ])->save();

    AuditLog::record('admin.granted', target: $user, metadata: ['via' => 'artisan']);

    $this->info("Admin {$email} is ready. Log in at ".url('/login'));
})->purpose('Create or update a local admin account for the test console');

// Deletes audit_logs / ai_requests rows past their retention (config/logging.php).
// Runs in the "scheduler" container (docker-compose.yml).
Schedule::command('model:prune')->dailyAt('03:00')->timezone('Asia/Bangkok');
