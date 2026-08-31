<?php

use App\Http\Controllers\Api\EdboController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [EdboController::class, 'health']);
Route::get('/projects', [EdboController::class, 'projects']);
Route::post('/optimize', [EdboController::class, 'run']);
Route::get('/runs/{uuid}', [EdboController::class, 'show']);
