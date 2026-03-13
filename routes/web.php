<?php

use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MembreController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\UserController;
use App\Models\RapprochementBancaire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Livewire full-page routes (just need route + view)
    Route::view('/depenses', 'depenses.index')->name('depenses.index');
    Route::view('/recettes', 'recettes.index')->name('recettes.index');
    Route::view('/dons', 'dons.index')->name('dons.index');
    Route::view('/budget', 'budget.index')->name('budget.index');
    Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
    Route::get('/rapprochement/{rapprochement}', function (RapprochementBancaire $rapprochement) {
        return view('rapprochement.detail', compact('rapprochement'));
    })->name('rapprochement.detail');
    Route::view('/virements', 'virements.index')->name('virements.index');
    Route::view('/rapports', 'rapports.index')->name('rapports.index');

    // Resource controllers
    Route::resource('membres', MembreController::class);
    Route::resource('operations', OperationController::class)->except(['destroy']);

    // Parametres
    Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
    Route::prefix('parametres')->name('parametres.')->group(function () {
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
        Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
        Route::resource('utilisateurs', UserController::class)->only(['store', 'destroy']);
    });
});

require __DIR__.'/auth.php';
