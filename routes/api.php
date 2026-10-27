<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TournamentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/session', [AuthController::class, 'session']);

// Lecture publique : les visiteurs voient tout, sans compte.
Route::get('/tournaments', [TournamentController::class, 'index']);
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
Route::get('/players', [PlayerController::class, 'index']);
Route::get('/players/{player}', [PlayerController::class, 'show']);
Route::get('/stats', [StatsController::class, 'index']);
Route::get('/teams/{team}/logo', [TeamController::class, 'showLogo'])->name('teams.logo');

// Écriture : réservée à l'admin (seul compte existant).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::post('/tournaments', [TournamentController::class, 'store']);
    Route::patch('/tournaments/{tournament}', [TournamentController::class, 'update']);
    Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);
    Route::post('/tournaments/{tournament}/draw', [TournamentController::class, 'draw']);
    Route::delete('/tournaments/{tournament}/draw', [TournamentController::class, 'resetDraw']);

    Route::post('/tournaments/{tournament}/teams', [TeamController::class, 'store']);
    Route::patch('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
    Route::post('/teams/{team}/logo', [TeamController::class, 'uploadLogo']);
    Route::delete('/teams/{team}/logo', [TeamController::class, 'deleteLogo']);

    Route::patch('/games/{game}', [GameController::class, 'update']);

    Route::post('/players', [PlayerController::class, 'store']);
    Route::patch('/players/{player}', [PlayerController::class, 'update']);
    Route::delete('/players/{player}', [PlayerController::class, 'destroy']);

});
