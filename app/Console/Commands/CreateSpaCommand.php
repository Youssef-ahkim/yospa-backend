<?php

namespace App\Console\Commands;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSpaCommand extends Command
{
    protected $signature = 'yospa:create-spa
                            {--name= : The name of the spa}
                            {--slug= : The slug of the spa}
                            {--owner-name= : The name of the owner}
                            {--owner-email= : The email of the owner}';

    protected $description = 'Create a new spa and its owner account';

    public function handle(): int
    {
        $name = $this->option('name');
        while (empty($name)) {
            $name = $this->ask('Spa Name');
            if (empty($name)) {
                $this->error('The spa name is required.');
            }
        }

        $slugInput = $this->option('slug');
        $baseSlug = ! empty($slugInput) ? Str::slug($slugInput) : Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;
        while (Spa::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $ownerName = $this->option('owner-name');
        while (empty($ownerName)) {
            $ownerName = $this->ask('Owner Name');
            if (empty($ownerName)) {
                $this->error('The owner name is required.');
            }
        }

        $ownerEmail = $this->option('owner-email');
        while (empty($ownerEmail) || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            if (! empty($ownerEmail) && ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('The email address is invalid.');
            }
            $ownerEmail = $this->ask('Owner Email');
        }

        // Global case-insensitive uniqueness check for email
        if (User::whereRaw('LOWER(email) = ?', [strtolower($ownerEmail)])->exists()) {
            $this->error("A user with email '{$ownerEmail}' already exists.");
            return 1;
        }

        // Password input with confirmation via secret (never CLI option)
        $password = null;
        while (empty($password)) {
            $password = $this->secret('Owner Password (min 8 characters)');
            if (strlen((string) $password) < 8) {
                $this->error('The password must be at least 8 characters.');
                $password = null;
                continue;
            }

            $confirm = $this->secret('Confirm Owner Password');
            if ($password !== $confirm) {
                $this->error('Passwords do not match.');
                $password = null;
            }
        }

        try {
            DB::transaction(function () use ($name, $slug, $ownerName, $ownerEmail, $password, &$spa, &$owner) {
                $spa = Spa::create([
                    'name'   => $name,
                    'slug'   => $slug,
                    'status' => 'active',
                ]);

                $owner = User::create([
                    'name'      => $ownerName,
                    'email'     => $ownerEmail,
                    'password'  => Hash::make($password),
                    'spa_id'    => $spa->id,
                    'role'      => 'owner',
                    'is_active' => true,
                ]);
            });

            $this->info('Spa and owner account created successfully!');
            $this->line("Spa ID: {$spa->id}");
            $this->line("Spa Name: {$spa->name}");
            $this->line("Spa Slug: {$spa->slug}");
            $this->line("Owner Email: {$owner->email}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to create spa: {$e->getMessage()}");
            return 1;
        }
    }
}
