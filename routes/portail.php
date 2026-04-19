<?php

declare(strict_types=1);

use App\Http\Controllers\Portail\LogoutController;
use App\Http\Middleware\Portail\Authenticate;
use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Http\Middleware\Portail\EnforceSessionLifetime;
use App\Http\Middleware\Portail\EnsureTiersChosen;
use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\Home;
use App\Livewire\Portail\Login;
use App\Livewire\Portail\OtpVerify;
use Illuminate\Support\Facades\Route;

Route::prefix('portail/{association:slug}')
    ->middleware(['web', BootTenantFromSlug::class])
    ->name('portail.')
    ->group(function () {
        Route::get('/login', Login::class)->name('login');
        Route::get('/otp', OtpVerify::class)->name('otp');
        Route::get('/choisir', ChooseTiers::class)->name('choisir');
        Route::middleware([EnsureTiersChosen::class, EnforceSessionLifetime::class, Authenticate::class])->group(function () {
            Route::get('/', Home::class)->name('home');
            Route::post('/logout', LogoutController::class)->name('logout');
        });
    });
