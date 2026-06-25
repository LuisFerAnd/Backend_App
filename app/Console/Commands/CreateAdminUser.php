<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

#[Signature('app:create-admin {email} {--name=} {--password=}')]
#[Description('Create or promote an admin user')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: Str::password(12));
        $name = (string) ($this->option('name') ?: 'Administrador');

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('doctor', 'web');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
            ]
        );

        if (! $user->wasRecentlyCreated && $this->option('name')) {
            $user->update(['name' => $name]);
        }

        $user->assignRole('admin');

        $this->info('Usuario admin listo.');
        $this->line('Email: '.$user->email);

        if ($user->wasRecentlyCreated) {
            $this->line('Password: '.$password);
        } else {
            $this->line('La cuenta ya existia; no se cambio la password.');
        }

        return self::SUCCESS;
    }
}
