<?php

use App\Services\UploadService\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/files')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('initiate', [FileController::class, 'initiate']);
    Route::post('complete', [FileController::class, 'complete']);
    Route::get('{id}', [FileController::class, 'show']);
    Route::delete('{id}', [FileController::class, 'destroy']);

    // Viewer management
    Route::post('{id}/viewers', [FileController::class, 'addViewer']);
    Route::delete('{id}/viewers/{userId}', [FileController::class, 'removeViewer']);
});

