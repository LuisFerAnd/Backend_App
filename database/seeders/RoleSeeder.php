<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = collect([
            'evaluations.view_own',
            'evaluations.create',
            'evaluations.update_own',
            'evaluations.view_all',
            'evaluations.export',
        ])->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name, 'web')]);

        Role::findOrCreate('doctor', 'web')->syncPermissions([
            $permissions['evaluations.view_own'],
            $permissions['evaluations.create'],
            $permissions['evaluations.update_own'],
        ]);
        Role::findOrCreate('admin', 'web')->syncPermissions($permissions->values());
    }
}
