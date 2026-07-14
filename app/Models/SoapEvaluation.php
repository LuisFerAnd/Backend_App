<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoapEvaluation extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['error_scale_version'];

    public function getErrorScaleVersionAttribute(): int
    {
        return 2;
    }

    protected function casts(): array
    {
        return [
            'test_date' => 'date',
            'last_saved_at' => 'datetime',
            'completed_at' => 'datetime',
            'soap_percentage' => 'decimal:2',
            'utility_average' => 'decimal:2',
            'ease_average' => 'decimal:2',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function processingAttempt(): BelongsTo
    {
        return $this->belongsTo(ConsultationProcessingAttempt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
