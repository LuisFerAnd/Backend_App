<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\PatientController;
use Illuminate\Support\Facades\Route;

Route::prefix('doctors')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('doctor.auth')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/doctors/logout', [AuthController::class, 'logout']);

    Route::apiResource('patients', PatientController::class)->except(['destroy']);
    Route::apiResource('consultations', ConsultationController::class)->except(['destroy']);
});
