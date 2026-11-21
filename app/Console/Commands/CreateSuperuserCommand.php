<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperuserCommand extends Command
{
    protected $signature = 'yospa:create-superuser
                            {--name= : The name of the superuser}
                            {--email= : The email of the superuser}';

    protected $description = 'Create the platform superuser account';

    public function handle(): int
    {
        // Enforce single superuser rule
        if (User::where('role', 'superuser')->exists()) {
            $this->error('A platform superuser already exists.');
            return 1;
        }

        $name = $this->option('name');
        while (empty($name)) {
            $name = $this->ask('Superuser Name');
            if (empty($name)) {
                $this->error('The superuser name is required.');
            }
        }

        $email = $this->option('email');
        while (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (! empty($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('The email address is invalid.');
            }
            $email = $this->ask('Superuser Email');
        }

        // Global case-insensitive uniqueness check for email
        if (User::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            $this->error("A user with email '{$email}' already exists.");
            return 1;
        }

        // Hidden password prompt with confirmation, min 12 characters (never CLI option or printed)
        $password = null;
        while (empty($password)) {
            $password = $this->secret('Superuser Password (min 12 characters)');
            if (strlen((string) $password) < 12) {
                $this->error('The password must be at least 12 characters.');
                $password = null;
                continue;
            }

            $confirm = $this->secret('Confirm Superuser Password');
            if ($password !== $confirm) {
                $this->error('Passwords do not match.');
                $password = null;
            }
        }

        try {
            $superuser = User::create([
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make($password),
                'spa_id'    => null,
                'role'      => 'superuser',
                'is_active' => true,
            ]);

            $this->info('Platform superuser created successfully!');
            $this->line("Superuser ID: {$superuser->id}");
            $this->line("Superuser Name: {$superuser->name}");
            $this->line("Superuser Email: {$superuser->email}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to create superuser: {$e->getMessage()}");
            return 1;
        }
    }
}
