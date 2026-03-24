<?php

use App\Http\Controllers\HelloAssoCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/helloasso/callback/{token}', HelloAssoCallbackController::class)
    ->name('api.helloasso.callback');
