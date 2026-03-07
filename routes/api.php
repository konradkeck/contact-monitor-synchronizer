<?php

use App\Http\Controllers\Api\ConnectionsController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {

    // Connections CRUD
    Route::get('/connections',                        [ConnectionsController::class, 'index']);
    Route::post('/connections',                       [ConnectionsController::class, 'store']);
    Route::get('/connections/{connection}',           [ConnectionsController::class, 'show']);
    Route::put('/connections/{connection}',           [ConnectionsController::class, 'update']);
    Route::delete('/connections/{connection}',        [ConnectionsController::class, 'destroy']);
    Route::post('/connections/{connection}/duplicate',[ConnectionsController::class, 'duplicate']);

    // Run management
    Route::post('/connections/{connection}/run',      [ConnectionsController::class, 'run']);
    Route::post('/connections/{connection}/stop',     [ConnectionsController::class, 'stop']);
    Route::get('/connections/{connection}/runs',      [ConnectionsController::class, 'connectionRuns']);
    Route::post('/kill-all',                          [ConnectionsController::class, 'killAll']);

    // Test credentials
    Route::post('/connections/test',                  [ConnectionsController::class, 'test']);

    // Runs
    Route::get('/runs',                               [ConnectionsController::class, 'runsList']);
    Route::get('/runs/{runId}',                       [ConnectionsController::class, 'runStatus']);
    Route::get('/runs/{runId}/logs',                  [ConnectionsController::class, 'runLogs']);
    Route::get('/runs/{runId}/stream',                [ConnectionsController::class, 'stream']);
});
