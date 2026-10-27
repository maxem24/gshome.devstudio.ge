<?php

use App\Http\Controllers\ArchiveToggleController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::post('/locale/{locale}', LocaleController::class)
    ->middleware('auth')
    ->name('locale.switch');

Route::post('/archive-toggle', ArchiveToggleController::class)
    ->middleware('auth')
    ->name('archive.toggle');
