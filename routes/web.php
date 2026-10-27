<?php

use App\Http\Controllers\KeeperController;
use App\Http\Middleware\EnsureLocalRequest;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureLocalRequest::class)->group(function (): void {
    Route::get('/', [KeeperController::class, 'index'])->name('home');
    Route::post('/folders', [KeeperController::class, 'storeFolder'])->name('folders.store');
    Route::delete('/folders/{folder}', [KeeperController::class, 'destroyFolder'])->name('folders.destroy');
    Route::post('/folders/{folder}/preview', [KeeperController::class, 'previewFolder'])->name('folders.preview');
    Route::post('/preview/run', [KeeperController::class, 'runPreview'])->name('preview.run');
    Route::post('/rules', [KeeperController::class, 'storeRule'])->name('rules.store');
    Route::delete('/rules/{rule}', [KeeperController::class, 'destroyRule'])->name('rules.destroy');
    Route::patch('/rules/{rule}/toggle', [KeeperController::class, 'toggleRule'])->name('rules.toggle');
    Route::patch('/rules/{rule}/move', [KeeperController::class, 'moveRule'])->name('rules.move');
    Route::post('/activity/{entry}/undo', [KeeperController::class, 'undoEntry'])->name('activity.undo');
    Route::post('/activity/sweep/{sweep}/undo', [KeeperController::class, 'undoSweep'])->name('activity.sweep.undo');
    Route::post('/activity/{execution}/retry', [KeeperController::class, 'retryExecution'])->name('activity.retry');
    Route::post('/trash/empty', [KeeperController::class, 'emptyTrash'])->name('trash.empty');
});
