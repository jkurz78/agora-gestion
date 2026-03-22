<?php

use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\RapprochementPdfController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\UserController;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Livewire full-page routes (just need route + view)
    Route::view('/transactions', 'transactions.index')->name('transactions.index');
    Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
    Route::view('/dons', 'dons.index')->name('dons.index');
    Route::view('/cotisations', 'cotisations.index')->name('cotisations.index');
    Route::view('/membres', 'membres.index')->name('membres.index');
    Route::view('/tiers', 'tiers.index')->name('tiers.index');
    Route::get('/tiers/{tiers}/transactions', function (Tiers $tiers) {
        return view('tiers.transactions', compact('tiers'));
    })->name('tiers.transactions');
    Route::view('/budget', 'budget.index')->name('budget.index');
    Route::get('/budget/export', BudgetExportController::class)->name('budget.export');
    Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
    Route::get('/rapprochement/{rapprochement}', function (RapprochementBancaire $rapprochement) {
        return view('rapprochement.detail', compact('rapprochement'));
    })->name('rapprochement.detail');
    Route::get('/rapprochement/{rapprochement}/pdf', RapprochementPdfController::class)
        ->name('rapprochement.pdf');
    Route::view('/virements', 'virements.index')->name('virements.index');
    Route::get('comptes-bancaires/{compte}/transactions', function (CompteBancaire $compte) {
        return view('comptes-bancaires.transactions', compact('compte'));
    })->name('comptes-bancaires.transactions');
    Route::view('/rapports', 'rapports.index')->name('rapports.index');
    Route::view('/profil', 'profil.index')->name('profil.index');

    // Changer d'exercice
    Route::post('/exercice/changer', function (Request $request) {
        $annee = (int) $request->input('annee');
        $available = app(ExerciceService::class)->available(10);
        if (in_array($annee, $available, true)) {
            session(['exercice_actif' => $annee]);
        }

        return redirect()->back();
    })->name('exercice.changer');

    // CSV import templates
    Route::get('/transactions/import/template/{type}', [CsvImportController::class, 'template'])
        ->whereIn('type', ['depense', 'recette'])
        ->name('transactions.import.template');

    // Resource controllers
    Route::resource('operations', OperationController::class)->except(['destroy']);

    // Parametres
    Route::prefix('parametres')->name('parametres.')->group(function () {
        Route::view('/association', 'parametres.association')->name('association');
        Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
        Route::post('sous-categories/{sousCategory}/toggle-flag', [SousCategorieController::class, 'toggleFlag'])->name('sous-categories.toggle-flag');
        Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
        Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

require __DIR__.'/auth.php';
