<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/locale/{locale}', LocaleController::class)
    ->middleware('auth')
    ->name('locale.switch');
