<?php

use App\Http\Controllers\HelloAssoCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/helloasso/callback/{token}', HelloAssoCallbackController::class)
    ->middleware('throttle:60,1')
    ->name('api.helloasso.callback');
