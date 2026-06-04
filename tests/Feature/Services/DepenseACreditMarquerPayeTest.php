<?php

declare(strict_types=1);

/**
 * Chantier 3a-ii — Dépense à crédit + Marquer payé + Réversion.
 *
 * Ce fichier couvre :
 *   3b-1 : saisie dette ouverte (non payée) → T1 60x D / 401 C (journal=Achat), pas de T2, statut=EnAttente
 *   3b-2 : marquerPaye sur la dette → crée T2 (401 D / 512X C, journal=Banque), 401 lettré T1↔T2, statut=Recu
 *   3b-3 : réversion (mode null via update) → T2 supprimée, 401 de T1 délettré, dette reste ouverte
 *   3b-4 : Livewire bouton « Marquer payé » visible pour une dépense en attente + appel marquerPaye
 */

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TransactionUniverselle;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Config::set('compta.use_partie_double', true);

    // Comptes système : 401, 411, 5112
    SystemeSeeder::seed();

    // CompteBancaire + Compte 512X correspondant (via IBAN)
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    BancairesSeeder::seed();
    $this->compte512X = Compte::where('iban', $this->iban)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // Compte 606 (classe 6) pour les dépenses
    $categorieDep = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges diverses',
    ]);
    $this->sc606 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieDep->id,
        'nom' => 'Achats fournitures',
        'code_cerfa' => '606',
    ]);
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Achats non stockés de matières et fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(TransactionService::class);
    $this->reglementService = app(ReglementOperationService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper : données d'une dépense non payée (mode null = dette ouverte)
// ---------------------------------------------------------------------------

function depenseNonPayeeData(object $ctx, float $montant = 150.0): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Depense->value,
            'date' => '2025-10-15',
            'libelle' => 'Achat fournitures à crédit',
            'montant_total' => (string) $montant,
            'mode_paiement' => null, // non payée = dette ouverte
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $ctx->compteBancaire->id,
            'statut_reglement' => StatutReglement::EnAttente->value,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc606->id,
            'montant' => (string) $montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

// ---------------------------------------------------------------------------
// [3b-1] Saisie dette ouverte : T1 60x D / 401 C, pas de T2, statut=EnAttente
// ---------------------------------------------------------------------------

it('[3b-1] dépense non payée produit T1 (60x D / 401 C, journal=Achat), pas de T2, statut=EnAttente', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseNonPayeeData($this);

    $t1 = $this->service->create($data, $lignes);
    $t1->refresh();

    // Une seule transaction créée (pas de T2)
    expect(Transaction::count())->toBe(1, 'Seulement T1 — pas de T2 pour une dépense non payée');

    // Statut = EnAttente
    expect($t1->statut_reglement)->toBe(StatutReglement::EnAttente, 'statut_reglement doit être EnAttente pour une dépense non payée');

    // Journal = Achat
    expect($t1->journal)->toBe(JournalComptable::Achat, 'T1 journal doit être Achat');

    // T1 doit avoir une ligne 60x D (ventilation enrichie)
    $ligneVentilation = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $this->sc606->id)
        ->first();
    expect($ligneVentilation)->not()->toBeNull('La ligne de ventilation 606 doit exister');
    expect($ligneVentilation->compte_id)->toBe($this->compte606->id, 'Ligne legacy enrichie avec compte_id 606');
    expect((float) $ligneVentilation->debit)->toBe(150.0, 'Ligne 606 est débitée (charge)');

    // T1 doit avoir une ligne 401 C (dette fournisseur)
    $compte401 = compteSysteme('401');
    $ligne401C = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->first();
    expect($ligne401C)->not()->toBeNull('T1 doit avoir une ligne 401 C (dette fournisseur)');
    expect((float) $ligne401C->credit)->toBe(150.0, 'Ligne 401 C = dette ouverte');
    expect((int) $ligne401C->tiers_id)->toBe((int) $this->tiers->id, 'Ligne 401 C doit avoir tiers_id');

    // Ligne 401 NON lettrée (dette ouverte — pas encore réglée)
    expect($ligne401C->lettrage_code)->toBeNull('Ligne 401 de T1 doit être non lettrée (dette ouverte)');

    // Aucune ligne 512X sur T1
    $ligne512 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $this->compte512X->id)
        ->first();
    expect($ligne512)->toBeNull('T1 ne doit PAS avoir de ligne 512X (portage uniquement sur T2 après règlement)');
});

// ---------------------------------------------------------------------------
// [3b-2] marquerPaye sur la dette → T2 (401 D / 512X C), 401 lettré, statut=Recu
// ---------------------------------------------------------------------------

it('[3b-2] marquerPaye crée T2 (401 D / 512X C, journal=Banque), lettre 401 T1↔T2, pose statut=Recu', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseNonPayeeData($this);
    $t1 = $this->service->create($data, $lignes);

    $compte401 = compteSysteme('401');
    $ligne401C_T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->firstOrFail();
    expect($ligne401C_T1->lettrage_code)->toBeNull('[précond] 401 non lettré avant marquerPaye');

    // Act : marquerPaye avec mode virement + compte bancaire
    $this->reglementService->marquerPaye($t1, ModePaiement::Virement, (int) $this->compteBancaire->id);

    // Assert : statut=Recu sur T1
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu, 'statut_reglement doit être Recu après marquerPaye');

    // T2 créée → 2 transactions au total
    expect(Transaction::count())->toBe(2, 'T2 doit exister après marquerPaye');

    // Retrouver T2 via lettrage_code
    $ligne401C_T1->refresh();
    expect($ligne401C_T1->lettrage_code)->not()->toBeNull('Ligne 401 C de T1 doit être lettrée après marquerPaye');

    $ligne401D_T2 = TransactionLigne::where('lettrage_code', $ligne401C_T1->lettrage_code)
        ->where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();
    expect($ligne401D_T2)->not()->toBeNull('Ligne 401 D sur T2 doit partager le code lettrage de T1');

    $t2 = Transaction::findOrFail($ligne401D_T2->transaction_id);
    expect($t2->journal)->toBe(JournalComptable::Banque, 'T2 journal doit être Banque');

    // T2 : ligne 401 D (soldage dette)
    expect((float) $ligne401D_T2->debit)->toBe(150.0);
    expect((int) $ligne401D_T2->tiers_id)->toBe((int) $this->tiers->id, 'Ligne 401 D doit avoir tiers_id');

    // T2 : ligne 512X C (décaissement)
    $ligne512C = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $this->compte512X->id)
        ->first();
    expect($ligne512C)->not()->toBeNull('T2 doit avoir une ligne 512X C');
    expect((float) $ligne512C->credit)->toBe(150.0);
    expect($ligne512C->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');
});

// ---------------------------------------------------------------------------
// [3b-3] Réversion (update mode null) → T2 supprimée, 401 T1 délettré
// ---------------------------------------------------------------------------

it('[3b-3] réversion paye→non-paye (mode null via update) supprime T2 et délettre le 401 de T1', function () {
    // Arrange : dépense + marquerPaye → T1+T2 avec 401 lettré
    ['data' => $data, 'lignes' => $lignes] = depenseNonPayeeData($this);
    $data['mode_paiement'] = ModePaiement::Virement->value; // d'abord payée (comptant)
    $data['statut_reglement'] = StatutReglement::Recu->value;
    $t1 = $this->service->create($data, $lignes);

    // Vérifier que T2 existe
    expect(Transaction::count())->toBe(2, '[précond] T1+T2 doivent exister');

    // Retrouver la ligne de ventilation pour l'update
    $ligneVentilation = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $this->sc606->id)
        ->firstOrFail();

    $compte401 = compteSysteme('401');
    $ligne401C_T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->firstOrFail();
    expect($ligne401C_T1->lettrage_code)->not()->toBeNull('[précond] 401 lettré avant réversion');

    // Act : update avec mode null (= repasse non payée)
    $updateData = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Achat fournitures à crédit (révisé)',
        'montant_total' => '150.00',
        'mode_paiement' => null, // réversion !
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $updateLignes = [[
        'id' => (int) $ligneVentilation->id,
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '150.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $this->service->update($t1, $updateData, $updateLignes);

    // Assert : T2 supprimée → 1 seule transaction
    expect(Transaction::count())->toBe(1, 'T2 doit être supprimée après réversion');

    // Assert : 401 de T1 délettré
    $t1->refresh();
    $ligne401C_T1_apres = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->first();
    expect($ligne401C_T1_apres)->not()->toBeNull('La ligne 401 de T1 doit encore exister');
    expect($ligne401C_T1_apres->lettrage_code)->toBeNull('Le 401 de T1 doit être délettré après réversion');

    // Note : statut_reglement reste Recu (staleness connue — chantier 4)
    // On constate sans bloquer.
});

// ---------------------------------------------------------------------------
// [3b-4] Livewire : bouton "Marquer payé" visible + appel marquerPaye
// ---------------------------------------------------------------------------

it('[3b-4] TransactionUniverselle affiche bouton Marquer payé pour dépense en attente et appelle marquerPaye', function () {
    // Arrange : T1 dette ouverte avec ligne 401 non lettrée
    ['data' => $data, 'lignes' => $lignes] = depenseNonPayeeData($this);
    $t1 = $this->service->create($data, $lignes);

    // Act : appel via Livewire (le bouton appelle marquerRecu qui dispatche une modale pour les dépenses)
    // Pour une dépense en attente avec mode null : doit ouvrir la modale marquer-paye
    $component = Livewire::test(TransactionUniverselle::class)
        ->call('marquerRecu', $t1->id);

    // Assert : la modale doit s'ouvrir (payeTxId renseigné + event dispatché)
    $component->assertDispatched('marquer-paye-modal-open');

    // Act : confirmer depuis la modale
    Livewire::test(TransactionUniverselle::class)
        ->set('payeTxId', (int) $t1->id)
        ->set('payeMode', ModePaiement::Virement->value)
        ->set('payeCompteId', (int) $this->compteBancaire->id)
        ->call('confirmerPaye');

    // Assert : statut=Recu
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu, 'statut_reglement doit être Recu après confirmerPaye');

    // Assert : T2 générée
    expect(Transaction::count())->toBe(2, 'T2 doit exister après confirmerPaye');
});
