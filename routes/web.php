<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/lessons'));
Route::get('/offline', [PwaController::class, 'shell'])->name('offline');
Route::get('/offline-assets', [PwaController::class, 'assets']);
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);
Route::middleware('auth')->prefix('offline-api')->group(function () {
    Route::get('/session', [OfflineController::class, 'session']);
    Route::get('/library', [OfflineController::class, 'library']);
    Route::get('/lessons/{lesson}', [OfflineController::class, 'lesson']);
    Route::post('/review', [OfflineController::class, 'review'])->middleware('throttle:60,1');
    Route::post('/sync', [OfflineController::class, 'sync'])->middleware('throttle:20,1');
    Route::post('/state', [OfflineController::class, 'state'])->middleware('throttle:60,1');
});
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth');
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::post('/workspace', [CurriculumController::class, 'workspace'])->name('workspace.switch');
    Route::post('/import-stream', [CurriculumController::class, 'importStream'])->middleware('throttle:10,1')->name('curriculum.import');
    Route::get('/lessons/{lesson}/download', [CurriculumController::class, 'download'])->name('curriculum.download');
    Route::get('/lessons/{lesson}/edit', [CurriculumController::class, 'edit'])->name('curriculum.edit');
    Route::post('/lessons/{lesson}/versions', [CurriculumController::class, 'storeVersion'])->name('curriculum.versions');
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
    Route::get('/lessons/{lesson}/assets/{key}', [LessonController::class, 'asset']);
});
