<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\Rapports\BalanceComptableBuilder;
use App\Services\Rapports\GrandLivreBuilder;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->ecritures = app(EcritureGenerator::class);
    $this->reglements = app(PosteTiersReglementService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

function creerScenarioGrandLivreBalance(object $contexte): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $contexte->association->id,
        'nom' => 'Client Builder',
    ]);

    $contexte->ecritures->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(10000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-09-15'),
        libelle: 'Créance avant période',
    );

    $t1Periode = $contexte->ecritures->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(8000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance période',
    );

    $poste = app(PostesTiersOuvertsService::class)
        ->pourTransaction($t1Periode, 2025);

    $contexte->reglements->regler(new PosteTiersReglementData(
        ligneId: (int) $poste?->ligneActionId,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2025-10-10'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $contexte->compteBancaire->id,
        exercice: 2025,
    ));

    return $tiers;
}

it('calcule une balance par compte et par tiers pour les comptes 401 et 411', function (): void {
    $tiers = creerScenarioGrandLivreBalance($this);

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2025-10-01', '2025-10-31', ['411']);

    expect($balance['lignes'])->toHaveCount(1);

    $ligne411 = $balance['lignes'][0];
    expect($ligne411['numero_compte'])->toBe('411')
        ->and($ligne411['tiers_id'])->toBe((int) $tiers->id)
        ->and($ligne411['tiers'])->toContain('CLIENT BUILDER')
        ->and($ligne411['ouverture_debit_centimes'])->toBe(10000)
        ->and($ligne411['ouverture_credit_centimes'])->toBe(0)
        ->and($ligne411['mouvement_debit_centimes'])->toBe(8000)
        ->and($ligne411['mouvement_credit_centimes'])->toBe(3000)
        ->and($ligne411['solde_fin_debit_centimes'])->toBe(15000)
        ->and($ligne411['solde_fin_credit_centimes'])->toBe(0);
});

it('sélectionne les comptes par préfixe comme un logiciel comptable standard', function (): void {
    creerScenarioGrandLivreBalance($this);

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2025-10-01', '2025-10-31', ['5']);

    expect($balance['lignes'])->toHaveCount(1)
        ->and($balance['lignes'][0]['numero_compte'])->toStartWith('512')
        ->and($balance['lignes'][0]['mouvement_debit_centimes'])->toBe(3000)
        ->and($balance['lignes'][0]['mouvement_credit_centimes'])->toBe(0);
});

it('trie les comptes de balance dans l’ordre comptable caractère par caractère', function (): void {
    $numeros = ['411', '530', '606', '609', '5112', '5121'];

    foreach ($numeros as $numero) {
        $compte = Compte::query()->firstOrCreate(
            [
                'association_id' => (int) $this->association->id,
                'numero_pcg' => $numero,
            ],
            [
                'intitule' => 'Compte '.$numero,
                'classe' => (int) $numero[0],
                'actif' => true,
                'est_systeme' => false,
                'pour_inscriptions' => false,
                'lettrable' => false,
            ]
        );

        $transaction = Transaction::query()->create([
            'association_id' => (int) $this->association->id,
            'type' => TypeTransaction::Depense,
            'date' => '2025-09-01',
            'libelle' => 'Mouvement '.$numero,
            'montant_total' => '1.00',
            'saisi_par' => (int) $this->user->id,
        ]);

        TransactionLigne::query()->create([
            'transaction_id' => (int) $transaction->id,
            'compte_id' => (int) $compte->id,
            'debit' => '1.00',
            'credit' => '0.00',
            'montant' => '0.00',
            'libelle' => 'Mouvement '.$numero,
        ]);
    }

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2025-09-01', '2025-09-30', []);

    expect(array_column($balance['lignes'], 'numero_compte'))->toBe([
        '411',
        '5112',
        '5121',
        '530',
        '606',
        '609',
    ]);

    $grandLivre = app(GrandLivreBuilder::class)
        ->grandLivre('2025-09-01', '2025-09-30', []);

    expect(array_column($grandLivre['comptes'], 'numero_compte'))->toBe([
        '411',
        '5112',
        '5121',
        '530',
        '606',
        '609',
    ]);
});

it('traite les écritures AN du premier jour comme solde ouverture de la balance', function (): void {
    $transaction = Transaction::query()->create([
        'association_id' => (int) $this->association->id,
        'type' => TypeTransaction::AN,
        'date' => '2025-09-01',
        'libelle' => 'AN trésorerie',
        'montant_total' => '500.00',
        'saisi_par' => (int) $this->user->id,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compte512X->id,
        'debit' => '500.00',
        'credit' => '0.00',
        'montant' => '0.00',
        'libelle' => 'AN trésorerie',
    ]);

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2025-09-01', '2025-09-30', ['512']);

    expect($balance['lignes'])->toHaveCount(1)
        ->and($balance['lignes'][0]['ouverture_debit_centimes'])->toBe(50000)
        ->and($balance['lignes'][0]['mouvement_debit_centimes'])->toBe(0)
        ->and($balance['lignes'][0]['solde_fin_debit_centimes'])->toBe(50000);
});

it('laisse les comptes de résultat à zéro en solde ouverture', function (): void {
    $compte611b = Compte::query()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '611b',
        'intitule' => 'Sous-traitance ateliers',
        'classe' => 6,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    $compte401 = Compte::query()
        ->where('association_id', (int) $this->association->id)
        ->where('numero_pcg', '401')
        ->firstOrFail();

    foreach ([
        ['date' => '2025-09-15', 'montant' => '150.00'],
        ['date' => '2025-10-05', 'montant' => '80.00'],
    ] as $ecriture) {
        $transaction = Transaction::query()->create([
            'association_id' => (int) $this->association->id,
            'type' => TypeTransaction::Depense,
            'date' => $ecriture['date'],
            'libelle' => 'Charge 611b',
            'montant_total' => $ecriture['montant'],
            'saisi_par' => (int) $this->user->id,
        ]);

        TransactionLigne::query()->create([
            'transaction_id' => (int) $transaction->id,
            'compte_id' => (int) $compte611b->id,
            'debit' => $ecriture['montant'],
            'credit' => '0.00',
            'montant' => '0.00',
            'libelle' => 'Charge 611b',
        ]);

        TransactionLigne::query()->create([
            'transaction_id' => (int) $transaction->id,
            'compte_id' => (int) $compte401->id,
            'debit' => '0.00',
            'credit' => $ecriture['montant'],
            'montant' => '0.00',
            'libelle' => 'Dette fournisseur',
        ]);
    }

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2025-10-01', '2025-10-31', ['611']);

    expect($balance['lignes'])->toHaveCount(1);

    $ligne611b = $balance['lignes'][0];
    expect($ligne611b['numero_compte'])->toBe('611b')
        ->and($ligne611b['ouverture_debit_centimes'])->toBe(0)
        ->and($ligne611b['solde_ouverture_debit_centimes'])->toBe(0)
        ->and($ligne611b['mouvement_debit_centimes'])->toBe(8000)
        ->and($ligne611b['solde_fin_debit_centimes'])->toBe(8000);

    $grandLivre = app(GrandLivreBuilder::class)
        ->grandLivre('2025-10-01', '2025-10-31', ['611']);

    expect($grandLivre['comptes'])->toHaveCount(1)
        ->and($grandLivre['comptes'][0]['numero_compte'])->toBe('611b')
        ->and($grandLivre['comptes'][0]['solde_ouverture_centimes'])->toBe(0)
        ->and($grandLivre['comptes'][0]['mouvement_debit_centimes'])->toBe(8000)
        ->and($grandLivre['comptes'][0]['solde_fin_centimes'])->toBe(8000)
        ->and($grandLivre['comptes'][0]['lignes'])->toHaveCount(1)
        ->and($grandLivre['comptes'][0]['lignes'][0]['date'])->toBe('2025-10-05');
});

it('produit un grand livre avec solde ouverture et solde progressif', function (): void {
    $tiers = creerScenarioGrandLivreBalance($this);

    $grandLivre = app(GrandLivreBuilder::class)
        ->grandLivre('2025-10-01', '2025-10-31', ['411']);

    expect($grandLivre['comptes'])->toHaveCount(1);

    $compte411 = $grandLivre['comptes'][0];
    expect($compte411['numero_compte'])->toBe('411')
        ->and($compte411['solde_ouverture_centimes'])->toBe(10000)
        ->and($compte411['mouvement_debit_centimes'])->toBe(8000)
        ->and($compte411['mouvement_credit_centimes'])->toBe(3000)
        ->and($compte411['solde_fin_centimes'])->toBe(15000)
        ->and($compte411['lignes'])->toHaveCount(3);

    expect($compte411['lignes'][0]['date'])->toBe('2025-10-03')
        ->and($compte411['lignes'][0]['tiers_id'])->toBe((int) $tiers->id)
        ->and($compte411['lignes'][0]['tiers'])->toContain('CLIENT BUILDER')
        ->and(collect($compte411['lignes'])->sum('debit_centimes'))->toBe(8000)
        ->and(collect($compte411['lignes'])->sum('credit_centimes'))->toBe(3000)
        ->and($compte411['lignes'][2]['date'])->toBe('2025-10-10')
        ->and($compte411['lignes'][2]['credit_centimes'])->toBe(3000)
        ->and($compte411['lignes'][2]['solde_progressif_centimes'])->toBe(15000);
});

// ─────────────────────────────────────────────────────────────────────────────
// Coupure des à-nouveaux : la pièce AN résume l'historique antérieur, elle ne
// s'y ajoute pas. Sans coupure, l'ouverture d'un exercice suivant une clôture
// comptait deux fois le solde de clôture (constat recette 5122, 2026-07-24).
// ─────────────────────────────────────────────────────────────────────────────

/** Crée un mouvement bancaire simple (débit 512X) à la date donnée. */
function creerMouvementBanque(object $contexte, string $date, string $montant, string $libelle): void
{
    $transaction = Transaction::query()->create([
        'association_id' => (int) $contexte->association->id,
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => $libelle,
        'montant_total' => $montant,
        'saisi_par' => (int) $contexte->user->id,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $contexte->compte512X->id,
        'debit' => $montant,
        'credit' => '0.00',
        'montant' => '0.00',
        'libelle' => $libelle,
    ]);
}

/** Crée une pièce AN (journal des à-nouveaux) portant un débit 512X. */
function creerPieceANouveau(object $contexte, string $date, string $montant): void
{
    $transaction = Transaction::query()->create([
        'association_id' => (int) $contexte->association->id,
        'type' => TypeTransaction::AN,
        'date' => $date,
        'libelle' => 'À-nouveaux',
        'montant_total' => $montant,
        'saisi_par' => (int) $contexte->user->id,
        'journal' => JournalComptable::AN,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $contexte->compte512X->id,
        'debit' => $montant,
        'credit' => '0.00',
        'montant' => '0.00',
        'libelle' => 'AN banque',
    ]);
}

it('n additionne pas l historique et la pièce AN dans l ouverture', function (): void {
    creerMouvementBanque($this, '2025-10-01', '500.00', 'Encaissement exercice N');
    creerPieceANouveau($this, '2026-09-01', '500.00');

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2026-09-01', '2027-08-31', ['512']);
    $ligne = collect($balance['lignes'])
        ->firstWhere('compte_id', (int) $this->compte512X->id);

    expect($ligne['ouverture_debit_centimes'])->toBe(50000)
        ->and($ligne['mouvement_debit_centimes'])->toBe(0)
        ->and($ligne['solde_fin_debit_centimes'])->toBe(50000);

    $grandLivre = app(GrandLivreBuilder::class)
        ->grandLivre('2026-09-01', '2027-08-31', ['512']);
    $compte = collect($grandLivre['comptes'])
        ->firstWhere('compte_id', (int) $this->compte512X->id);

    expect($compte['solde_ouverture_centimes'])->toBe(50000)
        ->and($compte['solde_fin_centimes'])->toBe(50000);
});

it('ignore les exercices antérieurs à la dernière pièce AN', function (): void {
    // Deux clôtures successives : seule la plus récente doit porter l'ouverture.
    creerMouvementBanque($this, '2024-10-01', '300.00', 'Exercice N-2');
    creerPieceANouveau($this, '2025-09-01', '300.00');
    creerMouvementBanque($this, '2025-10-01', '200.00', 'Exercice N-1');
    creerPieceANouveau($this, '2026-09-01', '500.00');

    $balance = app(BalanceComptableBuilder::class)
        ->balance('2026-09-01', '2027-08-31', ['512']);
    $ligne = collect($balance['lignes'])
        ->firstWhere('compte_id', (int) $this->compte512X->id);

    expect($ligne['ouverture_debit_centimes'])->toBe(50000)
        ->and($ligne['solde_fin_debit_centimes'])->toBe(50000);
});

it('compte les mouvements postérieurs à l AN quand la période démarre en cours d exercice', function (): void {
    creerMouvementBanque($this, '2025-10-01', '500.00', 'Exercice N');
    creerPieceANouveau($this, '2026-09-01', '500.00');
    creerMouvementBanque($this, '2026-09-20', '100.00', 'Début exercice N+1');
    creerMouvementBanque($this, '2026-11-05', '40.00', 'Dans la période');

    // Période démarrant après l'AN : l'ouverture = AN + mouvements intercalaires.
    $balance = app(BalanceComptableBuilder::class)
        ->balance('2026-10-15', '2027-08-31', ['512']);
    $ligne = collect($balance['lignes'])
        ->firstWhere('compte_id', (int) $this->compte512X->id);

    expect($ligne['ouverture_debit_centimes'])->toBe(60000)
        ->and($ligne['mouvement_debit_centimes'])->toBe(4000)
        ->and($ligne['solde_fin_debit_centimes'])->toBe(64000);
});
