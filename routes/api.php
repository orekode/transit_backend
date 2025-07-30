<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get("/transit/modes", [TransitModeController::class, "index"]);
Route::post("/transit/modes", [TransitModeController::class, "store"]);
Route::post("/transit/modes/del/{transitMode}", [TransitModeController::class, "destroy"]);
Route::post("/transit/modes/{transitMode}", [TransitModeController::class, "update"]);


Route::prefix('trip-analysis')->group(function () {
    // Public routes (if needed)
    Route::post('/', [TripAnalysisController::class, 'store']);
    Route::get('/{id}', [TripAnalysisController::class, 'show']);
    
    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/', [TripAnalysisController::class, 'index']);
        Route::delete('/{id}', [TripAnalysisController::class, 'destroy']);
        Route::get('/statistics/overview', [TripAnalysisController::class, 'statistics']);
        Route::get('/patterns/suspicious', [TripAnalysisController::class, 'suspiciousPatterns']);
    });
});

Route::prefix('trip-data')->group(function() {
    Route::post('/', [TripController::class, "store"]);
    Route::get('/', [TripController::class, "index"]);
    Route::get('/{trip}', [TripController::class, "show"]);
});
Route::get('/profile', [TripController::class, "profile"]);