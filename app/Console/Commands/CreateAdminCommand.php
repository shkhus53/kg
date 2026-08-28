<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\ValidItsNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    /**
     * Interactive-only: never runs unattended, never accepts a password as a
     * plain CLI argument (it would land in shell history / process list).
     */
    protected $signature = 'app:create-admin';

    protected $description = 'Create a production Admin account (ITS + password), entered interactively. Never seeds a demo account.';

    public function handle(): int
    {
        $this->info('Create Admin — ITS number and password are entered interactively and never logged.');

        $itsNumber = $this->ask('ITS Number (8 digits)');

        $validator = Validator::make(['its_number' => $itsNumber], [
            'its_number' => ['required', 'string', new ValidItsNumber],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('its_number'));

            return self::FAILURE;
        }

        if (User::where('its_number', $itsNumber)->exists()) {
            $this->error("A user with ITS {$itsNumber} already exists. Refusing to overwrite.");

            return self::FAILURE;
        }

        $name = $this->ask('Full name');

        if (! is_string($name) || trim($name) === '') {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 8 characters, not echoed)');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        if (! is_string($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'its_number' => $itsNumber,
            // Login is ITS-based; email has no real use here but the column is
            // NOT NULL/unique, so a synthetic, non-guessable-as-contact placeholder
            // keeps the schema constraint satisfied without implying a real inbox.
            'email' => "its{$itsNumber}@no-email.local",
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        $password = null;
        $confirm = null;

        $this->info("Admin created: ITS {$user->its_number}, id {$user->id}. Password was not stored, logged, or displayed.");

        return self::SUCCESS;
    }
}
