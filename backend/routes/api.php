<?php

use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::post('/photos', [PhotoController::class, 'store'])
    ->middleware('throttle:photo-uploads')
    ->name('photos.store');

Route::get('/photos', [PhotoController::class, 'index'])->name('photos.index');

Route::get('/photos/updates', [PhotoController::class, 'updates'])->name('photos.updates');

Route::get('/photos/{uuid}', [PhotoController::class, 'show'])
    ->whereUuid('uuid')
    ->name('photos.show');
