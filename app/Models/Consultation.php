<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Consultation extends Model
{
    protected $fillable = [
        'doctor_id',
        'patient_id',
        'consultation_code',
        'local_consultation_code',
        'session_uuid',
        'recording_status',
        'processing_status',
        'expected_segments',
        'received_segments',
        'transcribed_segments',
        'recording_finished_at',
        'transcription_text',
        'soap_status',
        'soap_error',
        'started_at', 'finished_at', 'recording_duration_seconds',
        'upload_status', 'transcription_status', 'transcription_strategy',
        'consolidated_audio_path', 'consolidated_audio_size', 'pdf_status', 'evaluation_status',
        'overall_status', 'failure_stage', 'failure_code', 'failure_message',
        'user_friendly_error_message', 'failure_occurred_at', 'is_evaluable',
        'last_processing_attempt', 'created_offline', 'synced_at',
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
            'recording_finished_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failure_occurred_at' => 'datetime',
            'synced_at' => 'datetime',
            'is_evaluable' => 'boolean',
            'created_offline' => 'boolean',
            'vital_signs' => 'array',
            'expected_segments' => 'integer',
            'received_segments' => 'integer',
            'transcribed_segments' => 'integer',
            'consolidated_audio_size' => 'integer',
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

    public function audioSegments(): HasMany
    {
        return $this->hasMany(ConsultationAudioSegment::class)
            ->orderBy('segment_number');
    }

    public function processingAttempts(): HasMany
    {
        return $this->hasMany(ConsultationProcessingAttempt::class)->orderBy('attempt_number');
    }

    public function currentProcessingAttempt(): HasOne
    {
        return $this->hasOne(ConsultationProcessingAttempt::class)->latestOfMany('attempt_number');
    }
}
