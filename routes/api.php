<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'check'])->middleware('throttle:30,1');
Route::get('/metrics', [MetricsController::class, 'index'])->middleware('throttle:30,1');
