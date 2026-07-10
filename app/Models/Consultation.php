<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Consultation extends Model
{
    protected $fillable = [
        'doctor_id',
        'patient_id',
        'consulted_at',
        'reason',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'vital_signs',
    ];

    protected function casts(): array
    {
        return [
            'consulted_at' => 'datetime',
            'vital_signs' => 'array',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function soapEvaluation(): HasOne
    {
        return $this->hasOne(SoapEvaluation::class);
    }
}
