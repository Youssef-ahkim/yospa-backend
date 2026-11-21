<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetOwnerPasswordCommand extends Command
{
    protected $signature = 'yospa:reset-owner-password {email : The email address of the owner or admin}';

    protected $description = 'Reset password for an owner or admin and revoke their active tokens';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");
            return 1;
        }

        if (! in_array($user->role, ['owner', 'admin'], true)) {
            $this->error("User '{$email}' has role '{$user->role}'. Password reset via this command is restricted to owners and admins.");
            return 1;
        }

        $password = null;
        while (empty($password)) {
            $password = $this->secret('New Password (min 8 characters)');
            if (strlen((string) $password) < 8) {
                $this->error('The password must be at least 8 characters.');
                $password = null;
                continue;
            }

            $confirm = $this->secret('Confirm New Password');
            if ($password !== $confirm) {
                $this->error('Passwords do not match.');
                $password = null;
            }
        }

        $user->password = Hash::make($password);
        $user->save();

        // Revoke all active tokens
        $user->tokens()->delete();

        $this->info("Password for {$user->role} '{$user->email}' reset successfully. All active tokens have been revoked.");

        return 0;
    }
}
