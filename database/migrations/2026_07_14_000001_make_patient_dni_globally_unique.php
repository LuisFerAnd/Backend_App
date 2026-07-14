<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $patients = DB::table('patients')->select(['id', 'dni'])->orderBy('id')->get();
        $normalizedById = [];
        $ownerByDni = [];

        foreach ($patients as $patient) {
            $normalized = preg_replace('/[\s-]+/', '', strtoupper(trim((string) $patient->dni)));
            if (isset($ownerByDni[$normalized])) {
                throw new RuntimeException(
                    "No se puede activar el DNI único: los pacientes {$ownerByDni[$normalized]} y {$patient->id} comparten el DNI {$normalized}."
                );
            }
            $ownerByDni[$normalized] = $patient->id;
            $normalizedById[$patient->id] = $normalized;
        }

        foreach ($normalizedById as $id => $dni) {
            DB::table('patients')->where('id', $id)->update(['dni' => $dni]);
        }

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique('patients_doctor_id_dni_unique');
            $table->unique('dni', 'patients_dni_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique('patients_dni_unique');
            $table->unique(['doctor_id', 'dni'], 'patients_doctor_id_dni_unique');
        });
    }
};
