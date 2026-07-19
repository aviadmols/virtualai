<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * One-time helper to reset a user's password from the CLI, without needing
 * direct database access or artisan tinker. Usage:
 *   php artisan reset-user-password user@example.com "NewPassword123$"
 */
class ResetUserPassword extends Command
{
    // === CONSTANTS ===
    protected $signature = 'reset-user-password {email : The email of the user} {password : The new password}';

    protected $description = 'Reset a user\'s password by email.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->argument('password');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email: {$email}");

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password reset successfully for user: {$email}");

        return self::SUCCESS;
    }
}
