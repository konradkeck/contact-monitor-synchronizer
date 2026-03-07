<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\ConnectionController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

// Admin auth (no middleware)
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');

// Admin panel (requires auth)
Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn() => redirect()->route('admin.connections.index'));

    // Connections CRUD
    Route::get('/connections',                          [ConnectionController::class, 'index'])->name('connections.index');
    Route::get('/connections/create',                   [ConnectionController::class, 'create'])->name('connections.create');
    Route::post('/connections',                         [ConnectionController::class, 'store'])->name('connections.store');
    Route::get('/connections/{connection}/edit',        [ConnectionController::class, 'edit'])->name('connections.edit');
    Route::put('/connections/{connection}',             [ConnectionController::class, 'update'])->name('connections.update');
    Route::delete('/connections/{connection}',          [ConnectionController::class, 'destroy'])->name('connections.destroy');

    // Run management — static routes BEFORE {connection} wildcard
    Route::post('/connections/test',                    [ConnectionController::class, 'test'])->name('connections.test');
    Route::post('/connections/kill-all',                [ConnectionController::class, 'killAll'])->name('connections.kill-all');
    Route::get('/connections/runs',                     [ConnectionController::class, 'runsList'])->name('connections.runs-list');
    Route::get('/connections/runs/{runId}/stream',      [ConnectionController::class, 'stream'])->name('connections.stream');
    Route::get('/connections/runs/{runId}/status',      [ConnectionController::class, 'runStatus'])->name('connections.run-status');
    Route::get('/connections/runs/{runId}/logs',        [ConnectionController::class, 'runLogs'])->name('connections.run-logs');
    Route::get('/connections/{connection}/runs',        [ConnectionController::class, 'connectionRuns'])->name('connections.runs');
    Route::post('/connections/{connection}/run',        [ConnectionController::class, 'run'])->name('connections.run');
    Route::post('/connections/{connection}/stop',       [ConnectionController::class, 'stop'])->name('connections.stop');
    Route::post('/connections/{connection}/duplicate',  [ConnectionController::class, 'duplicate'])->name('connections.duplicate');

    // Stats
    Route::get('/stats',                                [StatsController::class, 'index'])->name('stats');
});

// Google OAuth (outside admin middleware — callback comes from Google)
Route::get('/google/auth/{system}', [GoogleOAuthController::class, 'auth']);
Route::get('/google/callback/{system}', [GoogleOAuthController::class, 'callback']);
