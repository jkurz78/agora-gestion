<?php

declare(strict_types=1);

use App\Http\Controllers\Portail\FacturePartenaireDeposeePdfController;
use App\Http\Controllers\Portail\LogoController;
use App\Http\Controllers\Portail\LogoutController;
use App\Http\Controllers\Portail\TransactionPdfController;
use App\Http\Middleware\Portail\Authenticate;
use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Http\Middleware\Portail\EnforceSessionLifetime;
use App\Http\Middleware\Portail\EnsurePourDepenses;
use App\Http\Middleware\Portail\EnsureTiersChosen;
use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\FacturePartenaire\AtraiterIndex;
use App\Livewire\Portail\FacturePartenaire\Depot;
use App\Livewire\Portail\Home;
use App\Livewire\Portail\Login;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Livewire\Portail\NoteDeFrais\Index;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Livewire\Portail\OtpVerify;
use Illuminate\Support\Facades\Route;

// Slug-first (toujours valide, noms portail.*)
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

            Route::prefix('notes-de-frais')->middleware(EnsurePourDepenses::class)->name('ndf.')->group(function () {
                Route::get('/', Index::class)->name('index');
                Route::get('/nouvelle', Form::class)->name('create');
                Route::get('/{noteDeFrais}/edit', Form::class)->name('edit');
                Route::get('/{noteDeFrais}', Show::class)->name('show');
            });

            Route::prefix('factures')->middleware(EnsurePourDepenses::class)->name('factures.')->group(function () {
                Route::get('/', AtraiterIndex::class)->name('index');
                Route::get('/depot', Depot::class)->name('create');
                Route::get('/{depot}/pdf', FacturePartenaireDeposeePdfController::class)
                    ->middleware('signed')
                    ->name('pdf');
            });

            Route::prefix('historique')->middleware(EnsurePourDepenses::class)->name('historique.')->group(function () {
                Route::get('/{transaction}/pdf', TransactionPdfController::class)
                    ->middleware('signed')
                    ->name('pdf');
            });
        });
    });
