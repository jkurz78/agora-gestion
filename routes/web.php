<?php

use App\Http\Controllers\Api\NewsletterSubscriptionController;
use App\Http\Controllers\AttestationPresencePdfController;
use App\Http\Controllers\BackOffice\FacturePartenaireDepotPdfController;
use App\Http\Controllers\BackOffice\NoteDeFraisPieceJointeController;
use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevisManuelPdfController;
use App\Http\Controllers\DocumentPrevisionnelPdfController;
use App\Http\Controllers\DroitImagePdfController;
use App\Http\Controllers\EmailOptoutController;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\FacturePdfController;
use App\Http\Controllers\FormulaireController;
use App\Http\Controllers\IncomingDocumentsController;
use App\Http\Controllers\OnboardingBrandingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ParticipantDocumentController;
use App\Http\Controllers\ParticipantExportController;
use App\Http\Controllers\ParticipantFichePdfController;
use App\Http\Controllers\ParticipantImportTemplateController;
use App\Http\Controllers\ParticipantPdfController;
use App\Http\Controllers\RapportExportController;
use App\Http\Controllers\RapprochementPdfController;
use App\Http\Controllers\RapprochementPieceJointeController;
use App\Http\Controllers\RemiseBancairePdfController;
use App\Http\Controllers\SeanceExportController;
use App\Http\Controllers\SeanceFeuilleController;
use App\Http\Controllers\SeancePdfController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\SuperAdmin\SupportModeController;
use App\Http\Controllers\SwitchAssociationController;
use App\Http\Controllers\TenantAssetController;
use App\Http\Controllers\TiersExportController;
use App\Http\Controllers\TiersTemplateController;
use App\Http\Controllers\TransactionLignePieceJointeController;
use App\Http\Controllers\TransactionPieceJointeController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\CheckEspaceAccess;
use App\Http\Middleware\EnforceDemoReadOnly;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\VerifyTenantAsset;
use App\Livewire\Auth\AssociationSelector;
use App\Livewire\BackOffice\FacturePartenaire\Index as FpIndex;
use App\Livewire\BackOffice\NoteDeFrais\Index as NdfIndex;
use App\Livewire\BackOffice\NoteDeFrais\Show as NdfShow;
use App\Livewire\DevisManuel\DevisEdit;
use App\Livewire\DevisManuel\DevisList;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\TypeOperation;
use Illuminate\Support\Facades\Route;

// ── Installation (fresh-install wizard) ──
Route::view('/setup', 'setup')->name('setup');

// Root: redirect to dashboard
Route::middleware('auth')->get('/', function () {
    return redirect('/dashboard');
})->name('home');

// ── Paramètres ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class, CheckEspaceAccess::class.':parametres', EnforceDemoReadOnly::class])
    ->prefix('parametres')
    ->name('parametres.')
    ->group(function (): void {
        Route::view('/association', 'parametres.association')->name('association');
        Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
        Route::view('/reception-documents', 'parametres.reception-documents')->name('reception-documents');
        Route::view('/smtp', 'parametres.smtp')->name('smtp');
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::get('sous-categories', [SousCategorieController::class, 'index'])->name('sous-categories.index');
        Route::get('/comptabilite/usages', UsagesComptables::class)
            ->name('comptabilite.usages');
        Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });

Route::middleware(['auth'])->group(function (): void {
    Route::get('/association-selector', AssociationSelector::class)->name('association-selector');
    Route::post('/switch-association', SwitchAssociationController::class)->name('switch-association');
});

// ── Tenant Assets (signed URLs) ──
Route::get('/tenant-assets/{path}', TenantAssetController::class)
    ->where('path', '.*')
    ->middleware(['auth', 'signed', VerifyTenantAsset::class])
    ->name('tenant-assets');

// ── Profile (espace-agnostic) ──
Route::middleware(['auth'])->group(function (): void {
    Route::view('/profil', 'profil.index')->name('profil.index');
    Route::get('/transactions/{transaction}/piece-jointe', TransactionPieceJointeController::class)
        ->name('transactions.piece-jointe');
    Route::get('/rapprochements/{rapprochement}/piece-jointe', RapprochementPieceJointeController::class)
        ->name('rapprochements.piece-jointe');
});

// ── Onboarding ──
Route::middleware(['auth', EnsureTwoFactor::class])->prefix('onboarding')->name('onboarding.')->group(function (): void {
    Route::get('/', [OnboardingController::class, 'index'])->name('index');
    Route::get('/branding/{kind}', [OnboardingBrandingController::class, 'show'])
        ->where('kind', 'logo|cachet')
        ->name('branding');
});

// ── Operations ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('operations')
    ->name('operations.')
    ->group(function (): void {
        Route::view('/', 'gestion.operations.index')->name('index');
        Route::view('/analyse', 'gestion.analyse.index')->name('analyse');
        Route::get('/{operation}/participants/export', ParticipantExportController::class)
            ->name('participants.export');
        Route::get('/{operation}/participants/import-template', ParticipantImportTemplateController::class)
            ->name('participants.import-template');
        Route::get('/{operation}/participants/pdf', ParticipantPdfController::class)
            ->name('participants.pdf');
        Route::get('/{operation}/participants/{participant}/pdf', ParticipantFichePdfController::class)
            ->name('participants.fiche-pdf');
        Route::get('/{operation}/participants/{participant}/droit-image-pdf', DroitImagePdfController::class)
            ->name('participants.droit-image-pdf');
        Route::get('/{operation}/participants/{participant}/attestation-recap-pdf', [AttestationPresencePdfController::class, 'recap'])
            ->name('participants.attestation-recap-pdf');
        Route::get('/{operation}/seances/matrice-pdf', [SeancePdfController::class, 'matrice'])
            ->name('seances.matrice-pdf');
        Route::get('/{operation}/seances/{seance}/emargement-pdf', [SeancePdfController::class, 'emargement'])
            ->name('seances.emargement-pdf');
        Route::get('/{operation}/seances/{seance}/feuille-signee/download', [SeanceFeuilleController::class, 'download'])
            ->name('seances.feuille-signee.download');
        Route::get('/{operation}/seances/{seance}/feuille-signee/view', [SeanceFeuilleController::class, 'view'])
            ->name('seances.feuille-signee.view');
        Route::get('/{operation}/seances/export', SeanceExportController::class)
            ->name('seances.export');
        Route::get('/{operation}/seances/{seance}/attestation-pdf', [AttestationPresencePdfController::class, 'seance'])
            ->name('seances.attestation-pdf');
        Route::get('/{operation}/participants/{participant}', function (Operation $operation, Participant $participant) {
            abort_unless((int) $participant->operation_id === (int) $operation->id, 404);

            return view('gestion.operations.participant', compact('operation', 'participant'));
        })->name('participants.show');
        // Participant documents
        Route::get('/participants/{participant}/documents/{filename}', ParticipantDocumentController::class)
            ->where('filename', '[A-Za-z0-9._-]+')
            ->name('participants.documents.download');
        // Documents prévisionnels (devis / pro forma)
        Route::get('/documents-previsionnels/{document}/pdf', DocumentPrevisionnelPdfController::class)
            ->name('documents-previsionnels.pdf');
        // ── Types d'opération (sous /operations/types-operation) ──
        Route::view('/types-operation', 'operations.types-operation.index')
            ->name('types-operation.index');
        Route::get('/types-operation/create', function () {
            return view('operations.types-operation.show');
        })->name('types-operation.create');
        Route::get('/types-operation/{typeOperation}', function (TypeOperation $typeOperation) {
            return view('operations.types-operation.show', compact('typeOperation'));
        })->name('types-operation.show');
        // ── Show (catch-all, doit rester en dernier) ──
        Route::get('/{operation}', function (Operation $operation) {
            return view('gestion.operations.show', compact('operation'));
        })->name('show');
    });

// ── Dashboard ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard');

// ── Comptabilité ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('comptabilite')
    ->name('comptabilite.')
    ->group(function (): void {
        Route::view('/transactions', 'transactions.index')->name('transactions');
        Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
        Route::get('/transactions/import/template/{type}', [CsvImportController::class, 'template'])
            ->whereIn('type', ['depense', 'recette'])
            ->name('transactions.import.template');
        Route::view('/budget', 'budget.index')->name('budget');
        Route::get('/budget/export', BudgetExportController::class)->name('budget.export');
        Route::get('/notes-de-frais', NdfIndex::class)->name('ndf.index');
        Route::get('/notes-de-frais/{noteDeFrais}', NdfShow::class)->name('ndf.show');
        Route::get('/notes-de-frais/{noteDeFrais}/lignes/{ligne}/piece-jointe', NoteDeFraisPieceJointeController::class)->name('ndf.piece-jointe');
        Route::get('/transactions/{transaction}/lignes/{ligne}/piece-jointe', TransactionLignePieceJointeController::class)->name('transactions.piece-jointe-ligne');
    });

// ── Comptabilité — Factures fournisseurs ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->group(function (): void {
        Route::get('/comptabilite/factures-fournisseurs', FpIndex::class)
            ->name('comptabilite.factures-fournisseurs.index');
        Route::get('/comptabilite/factures-fournisseurs/{depot}/pdf', FacturePartenaireDepotPdfController::class)
            ->middleware(['can:treat,depot'])
            ->name('comptabilite.factures-fournisseurs.pdf');
    });

// ── Redirections 301 (anciennes URLs) ──
Route::permanentRedirect('/factures-partenaires/a-comptabiliser', '/comptabilite/factures-fournisseurs');

// ── Banques ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('banques')
    ->name('banques.')
    ->group(function (): void {
        Route::resource('comptes', CompteBancaireController::class)->except(['show'])->parameters(['comptes' => 'comptesBancaire']);
        Route::get('comptes/{compte}/transactions', function (CompteBancaire $compte) {
            return view('comptes-bancaires.transactions', compact('compte'));
        })->name('comptes.transactions');

        Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
        Route::get('/rapprochement/{rapprochement}', function (RapprochementBancaire $rapprochement) {
            return view('rapprochement.detail', compact('rapprochement'));
        })->name('rapprochement.detail');
        Route::get('/rapprochement/{rapprochement}/pdf', RapprochementPdfController::class)
            ->name('rapprochement.pdf');

        Route::view('/virements', 'virements.index')->name('virements.index');

        Route::view('/remises', 'gestion.remises-bancaires.index')->name('remises.index');
        Route::get('/remises/{remise}', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.show', compact('remise'));
        })->name('remises.show');
        Route::get('/remises/{remise}/selection', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.selection', compact('remise'));
        })->name('remises.selection');
        Route::get('/remises/{remise}/validation', function (RemiseBancaire $remise) {
            return view('gestion.remises-bancaires.validation', compact('remise'));
        })->name('remises.validation');
        Route::get('/remises/{remise}/pdf', RemiseBancairePdfController::class)
            ->name('remises.pdf');

        Route::view('/helloasso-sync', 'banques.helloasso-sync')->name('helloasso-sync');
    });

// ── Tiers ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('tiers')
    ->name('tiers.')
    ->group(function (): void {
        Route::view('/', 'tiers.index')->name('index');
        Route::get('/template/csv', [TiersTemplateController::class, 'csv'])->name('template.csv');
        Route::get('/template/xlsx', [TiersTemplateController::class, 'xlsx'])->name('template.xlsx');
        Route::get('/export', TiersExportController::class)->name('export');
        Route::get('/{tiers}/transactions', function (Tiers $tiers) {
            return view('tiers.transactions', compact('tiers'));
        })->name('transactions');
        Route::view('/adherents', 'gestion.adherents')->name('adherents');
        Route::view('/dons', 'dons.index')->name('dons');
        Route::view('/cotisations', 'cotisations.index')->name('cotisations');
        Route::view('/communication', 'tiers.communication')->name('communication');
    });

// ── Devis libres ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class, CheckEspaceAccess::class.':comptabilite'])
    ->group(function (): void {
        Route::get('/devis-manuels', DevisList::class)->name('devis-manuels.index');
        Route::get('/devis-manuels/{devis}', DevisEdit::class)->name('devis-manuels.show');
        Route::get('/devis-manuels/{devis}/pdf', DevisManuelPdfController::class)->name('devis-manuels.pdf');
    });

// ── Facturation ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('facturation')
    ->name('facturation.')
    ->group(function (): void {
        Route::view('/factures', 'gestion.factures.index')->name('factures');
        Route::get('/factures/{facture}/edit', function (Facture $facture) {
            return view('gestion.factures.edit', compact('facture'));
        })->name('factures.edit');
        Route::get('/factures/{facture}', function (Facture $facture) {
            return view('gestion.factures.show', compact('facture'));
        })->name('factures.show');
        Route::get('/factures/{facture}/pdf', FacturePdfController::class)
            ->name('factures.pdf');
        Route::get('/documents-en-attente', function () {
            return view('incoming-documents.index');
        })->name('documents-en-attente');
        Route::get('/documents-en-attente/{document}/download', [IncomingDocumentsController::class, 'download'])
            ->name('documents-en-attente.download');
    });

// ── Rapports ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('rapports')
    ->name('rapports.')
    ->group(function (): void {
        Route::view('/compte-resultat', 'rapports.compte-resultat')->name('compte-resultat');
        Route::view('/operations', 'rapports.operations')->name('operations');
        Route::view('/flux-tresorerie', 'rapports.flux-tresorerie')->name('flux-tresorerie');
        Route::view('/analyse', 'rapports.analyse')->name('analyse');
        Route::redirect('/', '/rapports/compte-resultat', 301)->name('index');
        Route::get('/export/{rapport}/{format}', RapportExportController::class)->name('export');
    });

// ── Exercices ──
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->prefix('exercices')
    ->name('exercices.')
    ->group(function (): void {
        Route::view('/cloture', 'exercices.cloture')->name('cloture');
        Route::view('/changer', 'exercices.changer')->name('changer');
        Route::view('/reouvrir', 'exercices.reouvrir')->name('reouvrir');
        Route::view('/audit', 'exercices.audit')->name('audit');
        Route::view('/provisions', 'exercices.provisions')->name('provisions');
    });

// ── Legacy redirects (301) ──
Route::middleware('auth')->group(function (): void {
    Route::permanentRedirect('/compta/dashboard', '/dashboard');
    Route::permanentRedirect('/gestion/dashboard', '/dashboard');
    Route::permanentRedirect('/compta/transactions', '/comptabilite/transactions');
    Route::permanentRedirect('/compta/transactions/all', '/comptabilite/transactions/all');
    Route::permanentRedirect('/compta/budget', '/comptabilite/budget');
    Route::permanentRedirect('/transactions', '/comptabilite/transactions');
    Route::permanentRedirect('/transactions/all', '/comptabilite/transactions/all');
    Route::permanentRedirect('/dons', '/tiers/dons');
    Route::permanentRedirect('/cotisations', '/tiers/cotisations');
    Route::permanentRedirect('/budget', '/comptabilite/budget');
    Route::permanentRedirect('/rapprochement', '/banques/rapprochement');
    Route::permanentRedirect('/virements', '/banques/virements');
    Route::permanentRedirect('/compta/rapports/compte-resultat', '/rapports/compte-resultat');
    Route::permanentRedirect('/compta/rapports/operations', '/rapports/operations');
    Route::permanentRedirect('/compta/rapports/flux-tresorerie', '/rapports/flux-tresorerie');
    Route::permanentRedirect('/compta/rapports/analyse', '/rapports/analyse');
    Route::permanentRedirect('/compta/rapports', '/rapports/compte-resultat');
    // Note: /compta/rapports/export/{rapport}/{format} cannot be permanently redirected
    // with path parameters via permanentRedirect; callers must use the new rapports.export route.
    Route::permanentRedirect('/membres', '/tiers/adherents');
    Route::permanentRedirect('/compta/tiers', '/tiers');
    Route::permanentRedirect('/gestion/adherents', '/tiers/adherents');
    Route::permanentRedirect('/compta/dons', '/tiers/dons');
    Route::permanentRedirect('/compta/cotisations', '/tiers/cotisations');
    Route::permanentRedirect('/compta/banques/comptes', '/banques/comptes');
    Route::permanentRedirect('/compta/banques/rapprochement', '/banques/rapprochement');
    Route::permanentRedirect('/compta/banques/virements', '/banques/virements');
    Route::permanentRedirect('/compta/banques/remises', '/banques/remises');
    Route::permanentRedirect('/compta/banques/helloasso-sync', '/banques/helloasso-sync');
    Route::permanentRedirect('/compta/exercices/cloture', '/exercices/cloture');
    Route::permanentRedirect('/compta/exercices/changer', '/exercices/changer');
    Route::permanentRedirect('/compta/exercices/reouvrir', '/exercices/reouvrir');
    Route::permanentRedirect('/compta/exercices/audit', '/exercices/audit');
    Route::permanentRedirect('/compta/exercices/provisions', '/exercices/provisions');
    Route::permanentRedirect('/compta/parametres/type-operations', '/operations/types-operation');
    Route::permanentRedirect('/gestion/parametres/type-operations', '/operations/types-operation');
    Route::permanentRedirect('/compta/parametres/association', '/parametres/association');
    Route::permanentRedirect('/gestion/parametres/association', '/parametres/association');
    Route::permanentRedirect('/compta/parametres/helloasso', '/parametres/helloasso');
    Route::permanentRedirect('/gestion/parametres/helloasso', '/parametres/helloasso');
    Route::permanentRedirect('/compta/parametres/reception-documents', '/parametres/reception-documents');
    Route::permanentRedirect('/gestion/parametres/reception-documents', '/parametres/reception-documents');
    Route::permanentRedirect('/compta/parametres/categories', '/parametres/categories');
    Route::permanentRedirect('/gestion/parametres/categories', '/parametres/categories');
    Route::permanentRedirect('/compta/parametres/sous-categories', '/parametres/sous-categories');
    Route::permanentRedirect('/gestion/parametres/sous-categories', '/parametres/sous-categories');
    Route::permanentRedirect('/compta/parametres/utilisateurs', '/parametres/utilisateurs');
    Route::permanentRedirect('/gestion/parametres/utilisateurs', '/parametres/utilisateurs');
    Route::permanentRedirect('/gestion/operations', '/operations');
    Route::permanentRedirect('/gestion/analyse', '/operations/analyse');
    Route::permanentRedirect('/compta/factures', '/facturation/factures');
    Route::permanentRedirect('/gestion/factures', '/facturation/factures');
    Route::permanentRedirect('/compta/documents-en-attente', '/facturation/documents-en-attente');
    Route::permanentRedirect('/gestion/documents-en-attente', '/facturation/documents-en-attente');
});

// Public formulaire (no auth required)
Route::prefix('formulaire')->middleware('throttle:10,1')->group(function (): void {
    Route::get('/', [FormulaireController::class, 'index'])->name('formulaire.index');
    Route::get('/remplir', [FormulaireController::class, 'show'])->name('formulaire.show');
    Route::post('/remplir', [FormulaireController::class, 'store'])->name('formulaire.store');
    Route::get('/merci', [FormulaireController::class, 'merci'])->name('formulaire.merci');
});

// Email tracking pixel (no auth, no throttle — called by mail clients)
Route::get('/t/{token}.gif', EmailTrackingController::class)->name('email.tracking');

// Email opt-out / resubscribe (no auth — called from email footer link)
Route::get('/email/optout/{token}', [EmailOptoutController::class, 'showOptout'])->name('email.optout');
Route::post('/email/optout/{token}', [EmailOptoutController::class, 'optout'])->name('email.optout.confirm');
Route::get('/email/resubscribe/{token}', [EmailOptoutController::class, 'resubscribe'])->name('email.resubscribe');

Route::middleware(['auth', 'super-admin'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function (): void {
        Route::get('/', fn () => view('super-admin.dashboard'))->name('dashboard');
        Route::prefix('associations')->name('associations.')->group(function (): void {
            Route::view('/', 'super-admin.associations.index')->name('index');
            // Stubs — remplacés dans Tasks 3/4/5
            Route::view('/create', 'super-admin.associations.create')->name('create');
            Route::get('/{association:slug}', fn (Association $association) => view('super-admin.associations.show', compact('association')))->name('show');
            Route::post('/{association:slug}/support/enter', [SupportModeController::class, 'enter'])
                ->name('support.enter');
        });
        Route::post('/support/exit', [SupportModeController::class, 'exit'])
            ->name('support.exit');
    });

// ── Newsletter public (no auth, no tenant middleware — token embeds tenant context) ──
Route::get('/newsletter/confirm/{token}', [NewsletterSubscriptionController::class, 'confirm'])
    ->middleware('throttle:30,1')
    ->name('newsletter.confirm');

Route::get('/newsletter/unsubscribe/{token}', [NewsletterSubscriptionController::class, 'unsubscribe'])
    ->middleware('throttle:30,1')
    ->name('newsletter.unsubscribe');

// Portail slug-less routes — must be registered BEFORE auth.php's
// {association:slug}/login to avoid collision on /portail/login.
require __DIR__.'/portail-mono.php';

require __DIR__.'/auth.php';
