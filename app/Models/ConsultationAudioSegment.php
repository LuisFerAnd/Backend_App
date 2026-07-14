<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationAudioSegment extends Model
{
    protected $fillable = [
        'consultation_id',
        'session_uuid',
        'segment_number',
        'original_filename',
        'storage_path',
        'duration_seconds',
        'file_size',
        'checksum',
        'upload_status',
        'transcription_status',
        'transcription_text',
        'retry_count',
        'error_message',
        'is_final',
    ];

    protected function casts(): array
    {
        return [
            'segment_number' => 'integer',
            'duration_seconds' => 'integer',
            'file_size' => 'integer',
            'retry_count' => 'integer',
            'is_final' => 'boolean',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
