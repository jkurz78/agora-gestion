<?php

declare(strict_types=1);

use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\Home;
use App\Livewire\Portail\Login;
use App\Livewire\Portail\OtpVerify;
use Illuminate\Support\Facades\Route;

Route::prefix('portail/{association:slug}')
    ->middleware(BootTenantFromSlug::class)
    ->name('portail.')
    ->group(function () {
        Route::get('/login', Login::class)->name('login');
        Route::get('/otp', OtpVerify::class)->name('otp');
        Route::get('/choisir', ChooseTiers::class)->name('choisir');
        Route::get('/', Home::class)->name('home');
    });
