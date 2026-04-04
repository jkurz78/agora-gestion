<?php

use App\Enums\Espace;
use App\Http\Controllers\AttestationPresencePdfController;
use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentPrevisionnelPdfController;
use App\Http\Controllers\DroitImagePdfController;
use App\Http\Controllers\FacturePdfController;
use App\Http\Controllers\FormulaireController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\ParticipantDocumentController;
use App\Http\Controllers\ParticipantExportController;
use App\Http\Controllers\ParticipantFichePdfController;
use App\Http\Controllers\ParticipantPdfController;
use App\Http\Controllers\RapportExportController;
use App\Http\Controllers\RapprochementPdfController;
use App\Http\Controllers\RemiseBancairePdfController;
use App\Http\Controllers\SeanceExportController;
use App\Http\Controllers\SeancePdfController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\CheckEspaceAccess;
use App\Http\Middleware\DetecteEspace;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use Illuminate\Support\Facades\Route;

// Root: redirect to user's last espace
Route::middleware('auth')->get('/', function () {
    $espace = auth()->user()->dernier_espace ?? Espace::Compta;

    return redirect("/{$espace->value}/dashboard");
})->name('home');

// ── Shared route registrar (parametres, helloasso-sync) ──
$registerParametres = function (): void {
    Route::prefix('parametres')->name('parametres.')->middleware(CheckEspaceAccess::class.':parametres')->group(function (): void {
        Route::view('/association', 'parametres.association')->name('association');
        Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::get('sous-categories', [SousCategorieController::class, 'index'])->name('sous-categories.index');
        Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
        Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::view('type-operations', 'parametres.type-operations.index')->name('type-operations.index');
    });
    Route::view('/helloasso-sync', 'banques.helloasso-sync')->name('helloasso-sync');

    // Factures (accessibles depuis les deux espaces)
    Route::view('/factures', 'gestion.factures.index')->name('factures');
    Route::get('/factures/{facture}/edit', function (Facture $facture) {
        return view('gestion.factures.edit', compact('facture'));
    })->name('factures.edit');
    Route::get('/factures/{facture}', function (Facture $facture) {
        return view('gestion.factures.show', compact('facture'));
    })->name('factures.show');
    Route::get('/factures/{facture}/pdf', FacturePdfController::class)
        ->name('factures.pdf');
};

// ── Espace Comptabilité ──
Route::middleware(['auth', 'verified', DetecteEspace::class.':compta'])
    ->prefix('compta')
    ->name('compta.')
    ->group(function () use ($registerParametres): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::view('/transactions', 'transactions.index')->name('transactions.index');
        Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
        Route::get('/transactions/import/template/{type}', [CsvImportController::class, 'template'])
            ->whereIn('type', ['depense', 'recette'])
            ->name('transactions.import.template');
        Route::view('/dons', 'dons.index')->name('dons.index');
        Route::view('/cotisations', 'cotisations.index')->name('cotisations.index');
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
        // Rapports — écrans dédiés
        Route::view('/rapports/compte-resultat', 'rapports.compte-resultat')->name('rapports.compte-resultat');
        Route::view('/rapports/operations', 'rapports.operations')->name('rapports.operations');
        Route::view('/rapports/flux-tresorerie', 'rapports.flux-tresorerie')->name('rapports.flux-tresorerie');
        Route::view('/rapports/analyse', 'rapports.analyse')->name('rapports.analyse');
        Route::redirect('/rapports', '/compta/rapports/compte-resultat', 301)->name('rapports.index');
        Route::get('/rapports/export/{rapport}/{format}', RapportExportController::class)->name('rapports.export');

        // Exercices
        Route::view('/exercices/cloture', 'exercices.cloture')->name('exercices.cloture');
        Route::view('/exercices/changer', 'exercices.changer')->name('exercices.changer');
        Route::view('/exercices/reouvrir', 'exercices.reouvrir')->name('exercices.reouvrir');
        Route::view('/exercices/audit', 'exercices.audit')->name('exercices.audit');

        // Operations
        Route::resource('operations', OperationController::class)->except(['destroy']);

        // Shared registrations
        $registerParametres();
    });

// ── Espace Gestion ──
Route::middleware(['auth', 'verified', DetecteEspace::class.':gestion'])
    ->prefix('gestion')
    ->name('gestion.')
    ->group(function () use ($registerParametres): void {
        Route::view('/dashboard', 'gestion.dashboard')->name('dashboard');
        Route::view('/adherents', 'gestion.adherents')->name('adherents');
        Route::view('/analyse', 'gestion.analyse.index')->name('analyse');
        Route::view('/operations', 'gestion.operations.index')->name('operations');
        Route::get('/operations/{operation}/participants/export', ParticipantExportController::class)
            ->name('operations.participants.export');
        Route::get('/operations/{operation}/participants/pdf', ParticipantPdfController::class)
            ->name('operations.participants.pdf');
        Route::get('/operations/{operation}/participants/{participant}/pdf', ParticipantFichePdfController::class)
            ->name('operations.participants.fiche-pdf');
        Route::get('/operations/{operation}/participants/{participant}/droit-image-pdf', DroitImagePdfController::class)
            ->name('operations.participants.droit-image-pdf');
        Route::get('/operations/{operation}/participants/{participant}/attestation-recap-pdf', [AttestationPresencePdfController::class, 'recap'])
            ->name('operations.participants.attestation-recap-pdf');
        Route::get('/operations/{operation}/seances/matrice-pdf', [SeancePdfController::class, 'matrice'])
            ->name('operations.seances.matrice-pdf');
        Route::get('/operations/{operation}/seances/{seance}/emargement-pdf', [SeancePdfController::class, 'emargement'])
            ->name('operations.seances.emargement-pdf');
        Route::get('/operations/{operation}/seances/export', SeanceExportController::class)
            ->name('operations.seances.export');
        Route::get('/operations/{operation}/seances/{seance}/attestation-pdf', [AttestationPresencePdfController::class, 'seance'])
            ->name('operations.seances.attestation-pdf');
        Route::get('/operations/{operation}/participants/{participant}', function (Operation $operation, Participant $participant) {
            abort_unless((int) $participant->operation_id === (int) $operation->id, 404);

            return view('gestion.operations.participant', compact('operation', 'participant'));
        })->name('operations.participants.show');
        Route::get('/operations/{operation}', function (Operation $operation) {
            return view('gestion.operations.show', compact('operation'));
        })->name('operations.show');

        // Participant documents
        Route::get('/participants/{participant}/documents/{filename}', ParticipantDocumentController::class)
            ->name('participants.documents.download');

        // Remises en banque
        Route::view('/remises-bancaires', 'gestion.remises-bancaires.index')->name('remises-bancaires');
        Route::get('/remises-bancaires/{remise}', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.show', compact('remise'));
        })->name('remises-bancaires.show');
        Route::get('/remises-bancaires/{remise}/selection', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.selection', compact('remise'));
        })->name('remises-bancaires.selection');
        Route::get('/remises-bancaires/{remise}/validation', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.validation', compact('remise'));
        })->name('remises-bancaires.validation');
        Route::get('/remises-bancaires/{remise}/pdf', RemiseBancairePdfController::class)
            ->name('remises-bancaires.pdf');

        // Documents prévisionnels (devis / pro forma)
        Route::get('/documents-previsionnels/{document}/pdf', DocumentPrevisionnelPdfController::class)
            ->name('documents-previsionnels.pdf');

        // Shared registrations
        $registerParametres();
    });

// ── Profile (espace-agnostic) ──
Route::middleware('auth')->group(function (): void {
    Route::view('/profil', 'profil.index')->name('profil.index');
});

// ── Legacy redirects (301) ──
Route::middleware('auth')->group(function (): void {
    Route::permanentRedirect('/dashboard', '/compta/dashboard');
    Route::permanentRedirect('/transactions', '/compta/transactions');
    Route::permanentRedirect('/transactions/all', '/compta/transactions/all');
    Route::permanentRedirect('/dons', '/compta/dons');
    Route::permanentRedirect('/cotisations', '/compta/cotisations');
    Route::permanentRedirect('/tiers', '/compta/tiers');
    Route::permanentRedirect('/budget', '/compta/budget');
    Route::permanentRedirect('/rapprochement', '/compta/rapprochement');
    Route::permanentRedirect('/virements', '/compta/virements');
    Route::permanentRedirect('/rapports', '/compta/rapports/compte-resultat');
    Route::permanentRedirect('/membres', '/gestion/adherents');
    Route::permanentRedirect('/banques/helloasso-sync', '/compta/helloasso-sync');
    Route::permanentRedirect('/exercices/cloture', '/compta/exercices/cloture');
    Route::permanentRedirect('/exercices/changer', '/compta/exercices/changer');
    Route::permanentRedirect('/exercices/reouvrir', '/compta/exercices/reouvrir');
    Route::permanentRedirect('/exercices/audit', '/compta/exercices/audit');
});

// Public formulaire (no auth required)
Route::prefix('formulaire')->middleware('throttle:10,1')->group(function (): void {
    Route::get('/', [FormulaireController::class, 'index'])->name('formulaire.index');
    Route::get('/remplir', [FormulaireController::class, 'show'])->name('formulaire.show');
    Route::post('/remplir', [FormulaireController::class, 'store'])->name('formulaire.store');
    Route::get('/merci', [FormulaireController::class, 'merci'])->name('formulaire.merci');
});

require __DIR__.'/auth.php';
