<?php

declare(strict_types=1);

/**
 * Portail slug-less routes — mono-association mode only.
 *
 * Registered BEFORE auth.php so that `portail/login` is matched before
 * the `{association:slug}/login` route in auth.php can grab it.
 *
 * Route names: portail.mono.*
 * Active only when MonoAssociation::isActive() === true (RequireMono middleware).
 */

use App\Http\Controllers\Portail\AttestationPortailController;
use App\Http\Controllers\Portail\DemoLoginAsTierController;
use App\Http\Controllers\Portail\DocumentPortailController;
use App\Http\Controllers\Portail\FacturePartenaireDeposeePdfController;
use App\Http\Controllers\Portail\LogoController;
use App\Http\Controllers\Portail\LogoutController;
use App\Http\Controllers\Portail\RecuPortailController;
use App\Http\Controllers\Portail\TransactionPdfController;
use App\Http\Middleware\MonoAssociationResolver;
use App\Http\Middleware\Portail\Authenticate;
use App\Http\Middleware\Portail\EnforceSessionLifetime;
use App\Http\Middleware\Portail\EnsurePeutVoirNotesDeFrais;
use App\Http\Middleware\Portail\EnsurePourDepenses;
use App\Http\Middleware\Portail\EnsureTiersChosen;
use App\Http\Middleware\RequireMono;
use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\FacturePartenaire\AtraiterIndex;
use App\Livewire\Portail\FacturePartenaire\Depot;
use App\Livewire\Portail\HistoriqueDepenses\Index as HistoriqueDepensesIndex;
use App\Livewire\Portail\Login;
use App\Livewire\Portail\MesActivites;
use App\Livewire\Portail\MesAdhesions;
use App\Livewire\Portail\MesDons;
use App\Livewire\Portail\MonProfil;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Livewire\Portail\NoteDeFrais\Index;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Livewire\Portail\OtpVerify;
use App\Livewire\Portail\TableauDeBord;
use Illuminate\Support\Facades\Route;

Route::prefix('portail')
    ->middleware([MonoAssociationResolver::class, RequireMono::class])
    ->name('portail.mono.')
    ->group(function (): void {
        Route::get('/logo', LogoController::class)->name('logo');
        Route::get('/login', Login::class)->name('login');
        Route::get('/otp', OtpVerify::class)->name('otp');
        Route::get('/choisir', ChooseTiers::class)->name('choisir');
        Route::get('/demo/login-as/{tierId}', DemoLoginAsTierController::class)->name('demo.login-as');

        Route::middleware([EnsureTiersChosen::class, EnforceSessionLifetime::class, Authenticate::class])->group(function (): void {
            Route::get('/', TableauDeBord::class)->name('home');
            Route::get('/mon-profil', MonProfil::class)->name('mon-profil');
            Route::get('/mes-adhesions', MesAdhesions::class)->name('mes-adhesions');
            Route::get('/mes-dons', MesDons::class)->name('mes-dons');
            Route::get('/mes-activites', MesActivites::class)->name('mes-activites');
            Route::get('/documents/devis/{document}', [DocumentPortailController::class, 'devis'])->name('documents.devis');
            Route::get('/documents/facture/{facture}', [DocumentPortailController::class, 'facture'])->name('documents.facture');
            Route::get('/attestations/seance/{operation}/{seance}', [AttestationPortailController::class, 'seance'])->name('attestations.seance');
            Route::get('/attestations/recap/{operation}/{participant}', [AttestationPortailController::class, 'recap'])->name('attestations.recap');
            Route::get('/recus/cotisation/{adhesion}', [RecuPortailController::class, 'cotisation'])->name('recus.cotisation');
            Route::get('/recus/fiscal/{ligne}', [RecuPortailController::class, 'fiscalDon'])->name('recus.fiscal');
            Route::post('/logout', LogoutController::class)->name('logout');

            Route::prefix('notes-de-frais')->middleware(EnsurePeutVoirNotesDeFrais::class)->name('ndf.')->group(function (): void {
                Route::get('/', Index::class)->name('index');
                Route::get('/nouvelle', Form::class)->name('create');
                Route::get('/{noteDeFrais}/edit', Form::class)->name('edit');
                Route::get('/{noteDeFrais}', Show::class)->name('show');
            });

            Route::prefix('factures')->middleware(EnsurePourDepenses::class)->name('factures.')->group(function (): void {
                Route::get('/', AtraiterIndex::class)->name('index');
                Route::get('/depot', Depot::class)->name('create');
                Route::get('/{depot}/pdf', FacturePartenaireDeposeePdfController::class)
                    ->middleware('signed')
                    ->name('pdf');
            });

            Route::prefix('historique')->middleware(EnsurePourDepenses::class)->name('historique.')->group(function (): void {
                Route::get('/', HistoriqueDepensesIndex::class)->name('index');
                Route::get('/{transaction}/pdf', TransactionPdfController::class)
                    ->middleware('signed')
                    ->name('pdf');
            });
        });
    });
