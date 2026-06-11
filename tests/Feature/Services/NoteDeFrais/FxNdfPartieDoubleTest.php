<?php

declare(strict_types=1);

/**
 * FX-NDF — Tests PD du flux notes de frais.
 *
 * [A] NDF valider() normal → T1 à crédit seul (pas de T2), statut EnAttente
 * [B] NDF valider() normal → lignes PD correctes (6xx D / 401 C)
 * [C] Abandon de créance → OD (401 D / 75x C), pas de ligne 512X
 * [D] Abandon de créance → lettrage 401 entre T1 et OD
 * [E] Abandon de créance → statut dérivé Recu sur les deux transactions
 * [F] Non-régression : mode_paiement reste stocké sur la Transaction pour affichage
 */

use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['compta.use_partie_double' => true]);
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    // Compte bancaire + Compte 512X
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) $this->asso->id,
    ]);
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '512_MAIN',
        'intitule' => 'Banque principale',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
        'compte_bancaire_id' => (int) $this->compteBancaire->id,
    ]);

    // Sous-catégorie Dépense avec code_cerfa → compte 606
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '606',
        'intitule' => 'Achats non stockés',
        'classe' => 6,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $this->catDepense = Categorie::factory()->create([
        'association_id' => (int) $this->asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $this->scDepense = SousCategorie::factory()->create([
        'association_id' => (int) $this->asso->id,
        'categorie_id' => (int) $this->catDepense->id,
        'nom' => 'Frais divers',
        'code_cerfa' => '606',
    ]);

    // Sous-catégorie AbandonCreance avec code_cerfa → compte 754
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '754',
        'intitule' => 'Abandon de créance',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $this->catRecette = Categorie::factory()->create([
        'association_id' => (int) $this->asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $this->scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => (int) $this->asso->id,
        'categorie_id' => (int) $this->catRecette->id,
        'nom' => 'Abandon de créance',
        'code_cerfa' => '754',
    ]);

    $this->tiers = Tiers::factory()->create(['association_id' => (int) $this->asso->id]);

    $this->data = new ValidationData(
        compte_id: (int) $this->compteBancaire->id,
        mode_paiement: ModePaiement::Virement,
        date: '2025-10-15',
    );

    $this->service = app(NoteDeFraisValidationService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * Helper : crée une NDF Soumise avec N lignes standard.
 */
function makeNdfSoumisePd(
    Association $asso,
    Tiers $tiers,
    SousCategorie $sc,
    int $count = 1,
    float $montantParLigne = 100.0,
): NoteDeFrais {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => (int) $asso->id,
        'tiers_id' => (int) $tiers->id,
        'date' => '2025-10-01',
        'libelle' => 'Frais mission',
    ]);

    for ($i = 0; $i < $count; $i++) {
        NoteDeFraisLigne::factory()->create([
            'note_de_frais_id' => (int) $ndf->id,
            'type' => NoteDeFraisLigneType::Standard->value,
            'sous_categorie_id' => (int) $sc->id,
            'libelle' => "Ligne $i",
            'montant' => $montantParLigne,
            'piece_jointe_path' => null,
        ]);
    }

    return $ndf;
}

// ---------------------------------------------------------------------------
// [A] NDF valider() normal → T1 seul, pas de T2
// ---------------------------------------------------------------------------

test('[A] NDF valider() en PD : crée T1 à crédit seul (pas de T2), statut EnAttente', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense, montantParLigne: 150.0);

    $tx = $this->service->valider($ndf, $this->data);

    // T1 seule — pas de T2
    expect(Transaction::count())->toBe(1);

    // Statut dérivé = EnAttente (dette ouverte, pas de T2)
    $tx->refresh();
    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tx->type)->toBe(TypeTransaction::Depense);
})->group('fx_ndf');

// ---------------------------------------------------------------------------
// [B] NDF valider() normal → lignes PD correctes
// ---------------------------------------------------------------------------

test('[B] NDF valider() en PD : lignes PD correctes (606 D / 401 C)', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense, montantParLigne: 200.0);

    $tx = $this->service->valider($ndf, $this->data);

    $lignesPd = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->whereNotNull('compte_id')
        ->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))
        ->get();

    // 2 lignes PD : 606 D + 401 C
    expect($lignesPd->count())->toBe(2);

    // Vérifier 606 D (charge)
    $compte606 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '606')
        ->first();
    $ligne606 = $lignesPd->firstWhere('compte_id', (int) $compte606->id);
    expect($ligne606)->not->toBeNull();
    expect((float) $ligne606->debit)->toBe(200.0);

    // Vérifier 401 C (dette fournisseur)
    $compte401 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '401')
        ->first();
    $ligne401 = $lignesPd->firstWhere('compte_id', (int) $compte401->id);
    expect($ligne401)->not->toBeNull();
    expect((float) $ligne401->credit)->toBe(200.0);

    // Pas de ligne 512X
    $compte512 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '512_MAIN')
        ->first();
    $ligne512 = $lignesPd->firstWhere('compte_id', (int) $compte512->id);
    expect($ligne512)->toBeNull();
})->group('fx_ndf');

// ---------------------------------------------------------------------------
// [C] Abandon de créance → 2 T1 + 2 OD compensation via 467, pas de 512X
// ---------------------------------------------------------------------------

test('[C] abandon de créance : T1-don est une recette avec tiers + sous-cat, pas de 512X', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense, montantParLigne: 120.0);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    // T1-Don est une vraie recette avec tiers et sous_cat
    expect($txDon->type)->toBe(TypeTransaction::Recette);
    expect((int) $txDon->tiers_id)->toBe((int) $this->tiers->id);

    // Lignes métier du don utilisent la sous-cat AbandonCreance
    $ligneDon = $txDon->lignes()->whereNotNull('sous_categorie_id')->first();
    expect($ligneDon)->not->toBeNull();
    expect((int) $ligneDon->sous_categorie_id)->toBe((int) $this->scAbandon->id);

    // Aucune ligne 512X nulle part (pas de mouvement bancaire)
    $compte512 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '512_MAIN')
        ->first();
    $lignes512 = TransactionLigne::where('compte_id', (int) $compte512->id)->count();
    expect($lignes512)->toBe(0, 'Aucune ligne 512X ne doit exister pour un abandon de créance');

    // Les OD de compensation utilisent le 467
    $compte467 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '467')
        ->first();
    $lignes467 = TransactionLigne::where('compte_id', (int) $compte467->id)->count();
    expect($lignes467)->toBe(2, 'Deux lignes 467 (compensation) : 467C sur T2-dep + 467D sur T2-don');
})->group('fx_ndf');

// ---------------------------------------------------------------------------
// [D] Abandon de créance → lettrage 401, 411 et 467
// ---------------------------------------------------------------------------

test('[D] abandon de créance : lettrage 401+411+467 entre T1 et T2 compensation', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense, montantParLigne: 80.0);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::findOrFail($ndf->transaction_id);

    $compte401 = Compte::where('association_id', (int) $this->asso->id)->where('numero_pcg', '401')->first();
    $compte411 = Compte::where('association_id', (int) $this->asso->id)->where('numero_pcg', '411')->first();
    $compte467 = Compte::where('association_id', (int) $this->asso->id)->where('numero_pcg', '467')->first();

    // 401 : T1-dep (401C) lettré avec T2-dep (401D)
    $ligne401C = TransactionLigne::where('transaction_id', (int) $txDepense->id)
        ->where('compte_id', (int) $compte401->id)->where('credit', '>', 0)->first();
    $ligne401D = TransactionLigne::where('compte_id', (int) $compte401->id)
        ->where('debit', '>', 0)->where('transaction_id', '!=', (int) $txDepense->id)->first();
    expect($ligne401C)->not->toBeNull();
    expect($ligne401D)->not->toBeNull();
    expect($ligne401C->lettrage_code)->not->toBeNull();
    expect($ligne401D->lettrage_code)->toBe($ligne401C->lettrage_code);

    // 411 : T1-don (411D) lettré avec T2-don (411C)
    $ligne411D = TransactionLigne::where('transaction_id', (int) $txDon->id)
        ->where('compte_id', (int) $compte411->id)->where('debit', '>', 0)->first();
    $ligne411C = TransactionLigne::where('compte_id', (int) $compte411->id)
        ->where('credit', '>', 0)->where('transaction_id', '!=', (int) $txDon->id)->first();
    expect($ligne411D)->not->toBeNull();
    expect($ligne411C)->not->toBeNull();
    expect($ligne411D->lettrage_code)->not->toBeNull();
    expect($ligne411C->lettrage_code)->toBe($ligne411D->lettrage_code);

    // 467 : T2-dep (467C) lettré avec T2-don (467D)
    $ligne467C = TransactionLigne::where('compte_id', (int) $compte467->id)->where('credit', '>', 0)->first();
    $ligne467D = TransactionLigne::where('compte_id', (int) $compte467->id)->where('debit', '>', 0)->first();
    expect($ligne467C)->not->toBeNull();
    expect($ligne467D)->not->toBeNull();
    expect($ligne467C->lettrage_code)->not->toBeNull();
    expect($ligne467D->lettrage_code)->toBe($ligne467C->lettrage_code);
})->group('fx_ndf');

// ---------------------------------------------------------------------------
// [E] Abandon de créance → statut dérivé Recu sur les deux T1
// ---------------------------------------------------------------------------

test('[E] abandon de créance : statut dérivé Recu sur T1 dépense et T1 don', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense, montantParLigne: 90.0);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::findOrFail($ndf->transaction_id);
    $txDepense->refresh();
    $txDon->refresh();

    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);
    expect($txDon->statut_reglement)->toBe(StatutReglement::Recu);
})->group('fx_ndf');

// ---------------------------------------------------------------------------
// [F] mode_paiement reste stocké pour affichage
// ---------------------------------------------------------------------------

test('[F] NDF valider() : mode_paiement stocké sur la Transaction pour affichage', function (): void {
    $ndf = makeNdfSoumisePd($this->asso, $this->tiers, $this->scDepense);

    $tx = $this->service->valider($ndf, $this->data);
    $tx->refresh();

    expect($tx->mode_paiement)->toBe(ModePaiement::Virement);
})->group('fx_ndf');
