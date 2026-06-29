<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or refresh a platform super-admin (global: account_id NULL +
 * is_super_admin = true) for the /platform panel. Idempotent (updateOrCreate by
 * email) and safe to call on every deploy: it no-ops when no credentials are
 * provided. Credentials come from options or env so a password never lands in
 * git. Run via predeploy (env) or manually: trayon:make-super-admin --email= --password=.
 */
class MakeSuperAdmin extends Command
{
    // === CONSTANTS ===
    protected $signature = 'trayon:make-super-admin
        {--email= : Email; falls back to TRAYON_SUPERADMIN_EMAIL.}
        {--password= : Password; falls back to TRAYON_SUPERADMIN_PASSWORD.}
        {--name= : Display name; falls back to TRAYON_SUPERADMIN_NAME.}';

    protected $description = 'Create or refresh the platform super-admin from options or env (idempotent).';

    private const ENV_EMAIL = 'TRAYON_SUPERADMIN_EMAIL';
    private const ENV_PASSWORD = 'TRAYON_SUPERADMIN_PASSWORD';
    private const ENV_NAME = 'TRAYON_SUPERADMIN_NAME';
    private const DEFAULT_NAME = 'Platform Admin';
    private const MIN_PASSWORD_LENGTH = 8;

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: env(self::ENV_EMAIL, ''));
        $password = (string) ($this->option('password') ?: env(self::ENV_PASSWORD, ''));
        $name = (string) ($this->option('name') ?: env(self::ENV_NAME, '') ?: self::DEFAULT_NAME);

        // No credentials -> skip silently (keeps predeploy green when unset).
        if (blank($email) || blank($password)) {
            $this->info('trayon:make-super-admin: no email/password provided — skipping.');

            return self::SUCCESS;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("invalid email: {$email}");

            return self::FAILURE;
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'account_id' => null,
            ],
        );

        $this->info(sprintf('Super-admin ready: %s (id=%d). Sign in at /platform/login.', $user->email, $user->getKey()));

        return self::SUCCESS;
    }
}
