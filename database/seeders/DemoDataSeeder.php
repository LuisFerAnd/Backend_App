<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@sanare.test'],
            [
                'name' => 'Admin Sanare',
                'password' => 'Admin12345',
            ]
        );

        $admin->assignRole('admin');

        $doctor = User::query()->updateOrCreate(
            ['email' => 'doctor@sanare.test'],
            [
                'name' => 'Dra. Ana Lopez',
                'password' => 'Doctor12345',
            ]
        );

        $doctor->assignRole('doctor');

        $patient = Patient::query()->updateOrCreate(
            ['dni' => '0801199012345'],
            [
                'doctor_id' => $doctor->id,
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
            ]
        );

    }
}
