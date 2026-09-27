<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateStaffAccount extends Command
{
    protected $signature = 'app:create-admin
        {email : Email address of the person who will own this account}
        {name : Full name of that person}
        {--role=Admin : Existing staff role to assign (Admin, Owner, Manager, Support)}';

    protected $description = 'Create a named staff account with a password entered interactively (never passed as an argument).';

    private const STAFF_ROLES = ['Admin', 'Owner', 'Manager', 'Support'];

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = trim($this->argument('name'));
        $roleName = $this->option('role');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'role' => $roleName],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'role' => ['required', 'in:'.implode(',', self::STAFF_ROLES)],
            ]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            $this->error("Role \"{$roleName}\" does not exist in this database.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min 12 chars, upper + lower case, a number)');
        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }
        $passwordCheck = Validator::make(
            ['password' => $password],
            ['password' => [Password::min(12)->mixedCase()->numbers()]]
        );
        if ($passwordCheck->fails()) {
            foreach ($passwordCheck->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($email, $name, $password, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->attach($role->id);

            AuditLog::create([
                'action' => 'created_staff_account',
                'description' => "Created {$role->name} account {$email} via console",
                'changes' => ['user_id' => $user->id, 'role' => $role->name],
            ]);

            return $user;
        });

        $this->info("Created {$role->name} account #{$user->id} for {$email}.");

        return self::SUCCESS;
    }
}
