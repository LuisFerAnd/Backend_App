<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique('patients_dni_unique');
            $table->unique(['doctor_id', 'dni'], 'patients_doctor_id_dni_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique('patients_doctor_id_dni_unique');
            $table->unique('dni');
        });
    }
};
