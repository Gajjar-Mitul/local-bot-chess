<?php

use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\MoveController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

Route::prefix('games')->group(function (): void {
    Route::post('/', [GameController::class, 'store']);
    Route::get('/{game}', [GameController::class, 'show']);
    Route::get('/{game}/moves', [GameController::class, 'moves']);
    Route::get('/{game}/legal-moves', [GameController::class, 'legalMoves']);
    Route::post('/{game}/moves', [MoveController::class, 'store']);
    Route::post('/{game}/reset', [GameController::class, 'reset']);
    Route::post('/{game}/resign', [GameController::class, 'resign']);
});

Route::prefix('stats')->group(function (): void {
    Route::get('/leaderboard', [StatsController::class, 'leaderboard']);
});

Route::prefix('players')->group(function (): void {
    Route::get('/{player}/history', [StatsController::class, 'history']);
});
