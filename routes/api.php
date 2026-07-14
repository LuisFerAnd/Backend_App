<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiConsultationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\SegmentedConsultationController;
use App\Http\Controllers\Api\SoapEvaluationController;
use App\Http\Controllers\Api\SoapEvaluationExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('doctors')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('doctor.auth')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/doctors/logout', [AuthController::class, 'logout']);

    Route::apiResource('patients', PatientController::class)->except(['destroy']);
    Route::get('/consultations/costs/export', [ConsultationController::class, 'exportCosts'])
        ->middleware('admin');
    Route::post('/consultations/start', [SegmentedConsultationController::class, 'start']);
    Route::post('/consultations/{consultation}/segments', [SegmentedConsultationController::class, 'uploadSegment']);
    Route::post('/consultations/{consultation}/finalize', [SegmentedConsultationController::class, 'finalize']);
    Route::get('/consultations/{consultation}/processing-status', [SegmentedConsultationController::class, 'status']);
    Route::get('/consultations/{consultation}/missing-segments', [SegmentedConsultationController::class, 'missingSegments']);
    Route::post('/consultations/{consultation}/segments/{segment}/retry-transcription', [SegmentedConsultationController::class, 'retryTranscription']);
    Route::post('/consultations/{consultation}/retry-processing', [SegmentedConsultationController::class, 'retryProcessing']);
    Route::post('/consultations/{consultation}/cancel-processing', [SegmentedConsultationController::class, 'cancelProcessing']);
    Route::post('/consultations/{consultation}/failure', [SegmentedConsultationController::class, 'reportFailure']);
    Route::apiResource('consultations', ConsultationController::class)->except(['destroy']);
    Route::post('/ai/transcriptions', [AiConsultationController::class, 'transcribe']);
    Route::post('/ai/consultation-draft', [AiConsultationController::class, 'draft']);
    Route::get('/consultations/{consultation}/soap-evaluation', [SoapEvaluationController::class, 'forConsultation']);
    Route::put('/soap-evaluations/{evaluation}', [SoapEvaluationController::class, 'update']);
    Route::post('/soap-evaluations/{evaluation}/complete', [SoapEvaluationController::class, 'complete']);
    Route::get('/soap-evaluations/{evaluation}', [SoapEvaluationController::class, 'show']);

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/summary', [AdminController::class, 'summary']);
        Route::get('/doctors', [AdminController::class, 'doctors']);
        Route::get('/patients', [AdminController::class, 'patients']);
        Route::get('/consultations', [AdminController::class, 'consultations']);
        Route::get('/soap-evaluations', [SoapEvaluationController::class, 'index']);
        Route::get('/soap-evaluations/export/{format}', SoapEvaluationExportController::class)
            ->whereIn('format', ['csv', 'xlsx', 'sav']);
    });
});
