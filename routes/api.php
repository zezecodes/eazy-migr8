<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchemaController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/db-config', [SchemaController::class, 'storeConfig']);
    Route::post('/generate', [SchemaController::class, 'generateMigration']);
    Route::post('/migrate', [SchemaController::class, 'runMigration']);
    Route::post('/columns', [SchemaController::class, 'addColumnsToTable']);
    Route::post('/drop-column', [SchemaController::class, 'removeColumnFromTable']);
    Route::post('/drop-table', [SchemaController::class, 'dropTable']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
