<?php

use App\Http\Controllers\Api\ConnectionsController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

// Registration callback – no auth token required (token validated in body)
Route::post('/register', function (\Illuminate\Http\Request $req) {
    $token = $req->input('verify_token');
    if (!$token || $token !== env('CM_REGISTRATION_TOKEN')) {
        return response()->json(['ok' => false, 'error' => 'Invalid token'], 403);
    }

    $cmRegUrl = env('CM_REGISTRATION_URL');
    if ($cmRegUrl) {
        try {
            \Illuminate\Support\Facades\Http::timeout(5)->post($cmRegUrl, [
                'verify_token' => $token,
                'api_token'    => env('API_TOKEN'),
                'url'          => env('APP_URL'),
            ]);
        } catch (\Throwable) {}
    }

    return response()->json(['ok' => true]);
});

Route::middleware('api.token')->group(function () {

    // Settings
    Route::get('/settings',    [SettingsController::class, 'show']);
    Route::put('/settings',    [SettingsController::class, 'update']);
    Route::post('/run-all',    [SettingsController::class, 'runAll']);
    Route::post('/reset-runs', [SettingsController::class, 'resetRuns']);

    // Connections CRUD
    Route::get('/connections',                        [ConnectionsController::class, 'index']);
    Route::post('/connections/test',                  [ConnectionsController::class, 'test']);
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

    // Runs
    Route::get('/runs',                               [ConnectionsController::class, 'runsList']);
    Route::get('/runs/{runId}',                       [ConnectionsController::class, 'runStatus']);
    Route::get('/runs/{runId}/logs',                  [ConnectionsController::class, 'runLogs']);
    Route::get('/runs/{runId}/stream',                [ConnectionsController::class, 'stream']);
});
