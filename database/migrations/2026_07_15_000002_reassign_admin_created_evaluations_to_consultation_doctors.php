<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminEvaluations = DB::table('soap_evaluations as evaluations')
            ->join('consultations', 'consultations.id', '=', 'evaluations.consultation_id')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'evaluations.evaluator_id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->whereColumn('evaluations.evaluator_id', '!=', 'consultations.doctor_id')
            ->select('evaluations.id', 'consultations.doctor_id')
            ->get();

        foreach ($adminEvaluations as $evaluation) {
            $doctor = DB::table('users')->where('id', $evaluation->doctor_id)->first();
            if ($doctor === null) {
                continue;
            }

            DB::table('soap_evaluations')->where('id', $evaluation->id)->update([
                'evaluator_id' => $doctor->id,
                'evaluator_name' => $doctor->name,
                'evaluator_specialization' => $doctor->specialization,
            ]);
        }
    }

    public function down(): void
    {
        // La autoría original no puede reconstruirse de forma segura.
    }
};
