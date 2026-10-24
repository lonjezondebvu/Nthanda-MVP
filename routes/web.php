<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LessonController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/lessons'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth');
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/lessons', [LessonController::class, 'index']);
    Route::get('/import', [LessonController::class, 'importPage']);
    Route::post('/import', [LessonController::class, 'import'])->middleware('throttle:10,1');
    Route::get('/lessons/{lesson}', [LessonController::class, 'manage']);
    Route::patch('/lessons/{lesson}/settings', [LessonController::class, 'settings']);
    Route::post('/lessons/{lesson}/order', [LessonController::class, 'order']);
});
Route::middleware('auth')->group(function () {
    Route::get('/lessons', [LessonController::class, 'index']);
    Route::get('/lessons/{lesson}', [LessonController::class, 'overview']);
    Route::get('/lessons/{lesson}/sections/{section}', [LessonController::class, 'section']);
    Route::get('/lessons/{lesson}/assessment', [LessonController::class, 'assessment']);
    Route::post('/lessons/{lesson}/progress', [LessonController::class, 'progress']);
    Route::post('/lessons/{lesson}/assessment', [LessonController::class, 'mark'])->middleware('throttle:20,1');
    Route::post('/lessons/{lesson}/written', [LessonController::class, 'written']);
    Route::get('/lessons/{lesson}/assets/{key}',[LessonController::class, 'asset']);
});
