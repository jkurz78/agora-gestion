<?php

declare(strict_types=1);

use App\Http\Controllers\Portail\LogoController;
use App\Http\Controllers\Portail\LogoutController;
use App\Http\Middleware\Portail\Authenticate;
use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Http\Middleware\Portail\EnforceSessionLifetime;
use App\Http\Middleware\Portail\EnsureTiersChosen;
use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\Home;
use App\Livewire\Portail\Login;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Livewire\Portail\NoteDeFrais\Index;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Livewire\Portail\OtpVerify;
use Illuminate\Support\Facades\Route;

Route::prefix('{association:slug}/portail')
    ->where(['association' => '[A-Za-z0-9-]+'])
    ->middleware(['web', BootTenantFromSlug::class])
    ->name('portail.')
    ->group(function () {
        Route::get('/logo', LogoController::class)->name('logo');
        Route::get('/login', Login::class)->name('login');
        Route::get('/otp', OtpVerify::class)->name('otp');
        Route::get('/choisir', ChooseTiers::class)->name('choisir');
        Route::middleware([EnsureTiersChosen::class, EnforceSessionLifetime::class, Authenticate::class])->group(function () {
            Route::get('/', Home::class)->name('home');
            Route::post('/logout', LogoutController::class)->name('logout');

            Route::prefix('notes-de-frais')->name('ndf.')->group(function () {
                Route::get('/', Index::class)->name('index');
                Route::get('/nouvelle', Form::class)->name('create');
                Route::get('/{noteDeFrais}/edit', Form::class)->name('edit');
                Route::get('/{noteDeFrais}', Show::class)->name('show');
            });
        });
    });
