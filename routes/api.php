<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SchemaController;
use App\Http\Controllers\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/db-config', [SchemaController::class, 'storeConfig']);
    Route::post('/generate', [SchemaController::class, 'generateMigration']);
    Route::post('/migrate', [SchemaController::class, 'runMigration']);
    Route::post('/rollback', [SchemaController::class, 'rollbackMigration']);
    Route::post('/logout', [AuthController::class, 'logout']);
});