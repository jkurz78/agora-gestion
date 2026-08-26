<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\BilanComptableBuilder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->utilisateurBilan = User::factory()->create();
});

function creerCompteBilan(string $numero, string $intitule): Compte
{
    return Compte::query()->create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => $intitule,
        'classe' => (int) $numero[0],
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function decimalBilan(int $centimes): string
{
    $signe = $centimes < 0 ? '-' : '';
    $absolu = abs($centimes);

    return $signe.intdiv($absolu, 100).'.'.str_pad((string) ($absolu % 100), 2, '0', STR_PAD_LEFT);
}

function enregistrerLigneBilan(
    object $contexte,
    Compte $compte,
    string $date,
    int $debitCentimes,
    int $creditCentimes,
    TypeTransaction $type = TypeTransaction::Virement,
): void {
    $transaction = Transaction::query()->create([
        'association_id' => TenantContext::currentId(),
        'type' => $type,
        'date' => $date,
        'libelle' => 'Fixture bilan '.$compte->numero_pcg,
        'montant_total' => '0.00',
        'saisi_par' => (int) $contexte->utilisateurBilan->id,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compte->id,
        'debit' => decimalBilan($debitCentimes),
        'credit' => decimalBilan($creditCentimes),
        'montant' => '0.00',
        'libelle' => 'Fixture bilan '.$compte->numero_pcg,
    ]);
}

it('retourne le contrat vide et le statut provisoire quand l exercice est absent', function (): void {
    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan)
        ->toMatchArray([
            'exercice' => 2025,
            'label_n' => '2025-2026',
            'label_n_1' => '2024-2025',
            'provisoire' => true,
            'statut' => 'Bilan provisoire avant clôture',
            'actif' => [],
            'passif' => [],
            'resultat_courant' => [
                'n_centimes' => 0,
                'n_1_centimes' => 0,
            ],
            'totaux' => [
                'actif_n_brut_centimes' => 0,
                'actif_n_amortissements_provisions_centimes' => 0,
                'actif_n_net_centimes' => 0,
                'actif_n_1_net_centimes' => 0,
                'passif_n_centimes' => 0,
                'passif_n_1_centimes' => 0,
            ],
            'ecart_actif_passif' => [
                'n_centimes' => 0,
                'n_1_centimes' => 0,
            ],
        ]);
});

it('conserve la mention provisoire exacte pour un exercice ouvert', function (): void {
    Exercice::query()->create([
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['provisoire'])->toBeTrue()
        ->and($bilan['statut'])->toBe('Bilan provisoire avant clôture');
});

it('identifie un exercice clôturé comme bilan définitif', function (): void {
    Exercice::query()->create([
        'annee' => 2025,
        'statut' => StatutExercice::Cloture,
    ]);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['provisoire'])->toBeFalse()
        ->and($bilan['statut'])->toBe('Bilan clôturé');
});

it('présente les immobilisations N en brut amortissements net et N moins 1 en net', function (): void {
    $compte21 = creerCompteBilan('2183', 'Matériel de bureau');
    $compte28 = creerCompteBilan('28183', 'Amortissements du matériel');

    enregistrerLigneBilan($this, $compte21, '2024-10-10', 80000, 0);
    enregistrerLigneBilan($this, $compte28, '2025-08-20', 0, 20000);
    enregistrerLigneBilan($this, $compte21, '2025-09-01', 100000, 0, TypeTransaction::AN);
    enregistrerLigneBilan($this, $compte28, '2025-09-01', 0, 30000, TypeTransaction::AN);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toHaveCount(1)
        ->and($bilan['actif'][0])->toBe([
            'code' => 'immobilisations_corporelles',
            'libelle' => 'Immobilisations corporelles',
            'brut_n_centimes' => 100000,
            'amortissements_provisions_n_centimes' => 30000,
            'net_n_centimes' => 70000,
            'net_n_1_centimes' => 60000,
        ])
        ->and($bilan['totaux']['actif_n_brut_centimes'])->toBe(100000)
        ->and($bilan['totaux']['actif_n_amortissements_provisions_centimes'])->toBe(30000)
        ->and($bilan['totaux']['actif_n_net_centimes'])->toBe(70000)
        ->and($bilan['totaux']['actif_n_1_net_centimes'])->toBe(60000);
});

it('déduit les dépréciations 29 de la valeur nette des immobilisations', function (): void {
    $compte21 = creerCompteBilan('2184', 'Mobilier');
    $compte29 = creerCompteBilan('29184', 'Dépréciation du mobilier');

    enregistrerLigneBilan($this, $compte21, '2025-10-10', 20000, 0);
    enregistrerLigneBilan($this, $compte29, '2025-12-10', 0, 2000);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'][0]['amortissements_provisions_n_centimes'])->toBe(2000)
        ->and($bilan['actif'][0]['net_n_centimes'])->toBe(18000);
});

it('classe toutes les familles ANC d immobilisations et leurs contre-comptes par nature', function (): void {
    $mouvements = [
        ['205', 'Logiciels', 10000, 0],
        ['2805', 'Amortissements des logiciels', 0, 1000],
        ['223', 'Immobilisations mises en concession', 20000, 0],
        ['2823', 'Amortissements des immobilisations en concession', 0, 2000],
        ['235', 'Installations en cours', 30000, 0],
        ['2935', 'Dépréciations des installations en cours', 0, 3000],
        ['241', 'Immobilisations financières diverses', 40000, 0],
        ['2941', 'Dépréciations des immobilisations financières diverses', 0, 4000],
        ['251', 'Titres immobilisés', 50000, 0],
        ['2951', 'Dépréciations des titres immobilisés', 0, 5000],
        ['261', 'Titres de participation', 60000, 0],
        ['2961', 'Dépréciations des titres de participation', 0, 6000],
        ['271', 'Prêts', 70000, 0],
        ['2971', 'Dépréciations des prêts', 0, 7000],
    ];

    foreach ($mouvements as [$numero, $intitule, $debit, $credit]) {
        enregistrerLigneBilan($this, creerCompteBilan($numero, $intitule), '2025-10-15', $debit, $credit);
    }

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toBe([
        [
            'code' => 'immobilisations_incorporelles',
            'libelle' => 'Immobilisations incorporelles',
            'brut_n_centimes' => 10000,
            'amortissements_provisions_n_centimes' => 1000,
            'net_n_centimes' => 9000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'immobilisations_corporelles',
            'libelle' => 'Immobilisations corporelles',
            'brut_n_centimes' => 50000,
            'amortissements_provisions_n_centimes' => 5000,
            'net_n_centimes' => 45000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'immobilisations_financieres',
            'libelle' => 'Immobilisations financières',
            'brut_n_centimes' => 220000,
            'amortissements_provisions_n_centimes' => 22000,
            'net_n_centimes' => 198000,
            'net_n_1_centimes' => 0,
        ],
    ]);
});

it('conserve le sens débiteur d un contre-compte dans la déduction et dans l équation', function (): void {
    $immobilisation = creerCompteBilan('2183', 'Matériel de bureau');
    $amortissement = creerCompteBilan('28183', 'Amortissements du matériel');
    $fonds = creerCompteBilan('102', 'Fonds associatifs');

    enregistrerLigneBilan($this, $immobilisation, '2025-10-15', 10000, 0);
    enregistrerLigneBilan($this, $amortissement, '2025-10-15', 2500, 0);
    enregistrerLigneBilan($this, $fonds, '2025-10-15', 0, 12500);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toBe([
        [
            'code' => 'immobilisations_corporelles',
            'libelle' => 'Immobilisations corporelles',
            'brut_n_centimes' => 10000,
            'amortissements_provisions_n_centimes' => -2500,
            'net_n_centimes' => 12500,
            'net_n_1_centimes' => 0,
        ],
    ])->and($bilan['ecart_actif_passif']['n_centimes'])->toBe(0);
});

it('classe stocks créances charges constatées avance et disponibilités avec leurs dépréciations', function (): void {
    $mouvements = [
        ['371', 'Stocks de marchandises', 40000, 0],
        ['391', 'Dépréciation des stocks', 0, 6000],
        ['411', 'Clients', 50000, 0],
        ['491', 'Dépréciation des clients', 0, 5000],
        ['467', 'Autres comptes débiteurs', 12000, 0],
        ['496', 'Dépréciation des autres créances', 0, 2000],
        ['486', 'Charges constatées avance', 7000, 0],
        ['503', 'Valeurs mobilières de placement', 8000, 0],
        ['5121', 'Banque', 30000, 0],
        ['530', 'Caisse', 4000, 0],
        ['590', 'Dépréciation des valeurs mobilières', 0, 1000],
    ];

    foreach ($mouvements as [$numero, $intitule, $debit, $credit]) {
        enregistrerLigneBilan(
            $this,
            creerCompteBilan($numero, $intitule),
            '2025-10-15',
            $debit,
            $credit,
        );
    }

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toBe([
        [
            'code' => 'stocks',
            'libelle' => 'Stocks',
            'brut_n_centimes' => 40000,
            'amortissements_provisions_n_centimes' => 6000,
            'net_n_centimes' => 34000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'creances_clients',
            'libelle' => 'Créances clients',
            'brut_n_centimes' => 50000,
            'amortissements_provisions_n_centimes' => 5000,
            'net_n_centimes' => 45000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'autres_creances',
            'libelle' => 'Autres créances',
            'brut_n_centimes' => 12000,
            'amortissements_provisions_n_centimes' => 2000,
            'net_n_centimes' => 10000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'charges_constatees_avance',
            'libelle' => 'Charges constatées d’avance',
            'brut_n_centimes' => 7000,
            'amortissements_provisions_n_centimes' => 0,
            'net_n_centimes' => 7000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'valeurs_mobilieres_placement',
            'libelle' => 'Valeurs mobilières de placement',
            'brut_n_centimes' => 8000,
            'amortissements_provisions_n_centimes' => 1000,
            'net_n_centimes' => 7000,
            'net_n_1_centimes' => 0,
        ],
        [
            'code' => 'disponibilites',
            'libelle' => 'Disponibilités',
            'brut_n_centimes' => 34000,
            'amortissements_provisions_n_centimes' => 0,
            'net_n_centimes' => 34000,
            'net_n_1_centimes' => 0,
        ],
    ]);
});

it('calcule le résultat courant N et N moins 1 exclusivement depuis les classes 6 et 7', function (): void {
    $compte606 = creerCompteBilan('6063', 'Fournitures entretien');
    $compte706 = creerCompteBilan('706', 'Prestations');

    enregistrerLigneBilan($this, $compte606, '2024-10-01', 10000, 0);
    enregistrerLigneBilan($this, $compte706, '2024-11-01', 0, 25000);
    enregistrerLigneBilan($this, $compte606, '2025-10-01', 30000, 0);
    enregistrerLigneBilan($this, $compte706, '2025-11-01', 0, 50000);
    enregistrerLigneBilan($this, $compte706, '2025-09-01', 0, 99900, TypeTransaction::AN);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['resultat_courant'])->toBe([
        'n_centimes' => 20000,
        'n_1_centimes' => 15000,
    ])->and($bilan['passif'])->toBe([
        [
            'code' => 'resultat_courant',
            'libelle' => 'Résultat de l’exercice',
            'montant_n_centimes' => 20000,
            'montant_n_1_centimes' => 15000,
        ],
    ]);
});

it('reprend uniquement les soldes ouverture 120 et 129 comme résultats antérieurs', function (): void {
    $compte120 = creerCompteBilan('120', 'Résultat excédentaire');
    $compte129 = creerCompteBilan('129', 'Résultat déficitaire');

    enregistrerLigneBilan($this, $compte120, '2024-09-01', 0, 8000, TypeTransaction::AN);
    enregistrerLigneBilan($this, $compte129, '2024-09-01', 1000, 0, TypeTransaction::AN);
    enregistrerLigneBilan($this, $compte120, '2024-12-01', 0, 4000);
    enregistrerLigneBilan($this, $compte120, '2025-09-01', 0, 12000, TypeTransaction::AN);
    enregistrerLigneBilan($this, $compte129, '2025-09-01', 3000, 0, TypeTransaction::AN);
    enregistrerLigneBilan($this, $compte129, '2025-12-01', 2000, 0);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['passif'])->toBe([
        [
            'code' => 'resultats_anterieurs',
            'libelle' => 'Résultats antérieurs',
            'montant_n_centimes' => 9000,
            'montant_n_1_centimes' => 7000,
        ],
    ]);
});

it('classe fonds propres provisions dettes produits constatés avance et découvert pour N et N moins 1', function (): void {
    $postes = [
        ['102', 'Fonds associatifs', 80000, 100000],
        ['1511', 'Provisions pour litiges', 6000, 8000],
        ['401', 'Fournisseurs', 15000, 20000],
        ['467', 'Autres comptes créditeurs', 4000, 5000],
        ['487', 'Produits constatés avance', 2000, 3000],
        ['5121', 'Banque à découvert', 1000, 4000],
    ];

    foreach ($postes as [$numero, $intitule, $montantN1, $montantN]) {
        $compte = creerCompteBilan($numero, $intitule);
        enregistrerLigneBilan($this, $compte, '2024-10-15', 0, $montantN1);
        enregistrerLigneBilan($this, $compte, '2025-09-01', 0, $montantN, TypeTransaction::AN);
    }

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['passif'])->toBe([
        [
            'code' => 'fonds_propres',
            'libelle' => 'Fonds propres',
            'montant_n_centimes' => 100000,
            'montant_n_1_centimes' => 80000,
        ],
        [
            'code' => 'provisions_risques_charges',
            'libelle' => 'Provisions pour risques et charges',
            'montant_n_centimes' => 8000,
            'montant_n_1_centimes' => 6000,
        ],
        [
            'code' => 'dettes_fournisseurs',
            'libelle' => 'Dettes fournisseurs',
            'montant_n_centimes' => 20000,
            'montant_n_1_centimes' => 15000,
        ],
        [
            'code' => 'autres_dettes',
            'libelle' => 'Autres dettes',
            'montant_n_centimes' => 5000,
            'montant_n_1_centimes' => 4000,
        ],
        [
            'code' => 'produits_constates_avance',
            'libelle' => 'Produits constatés d’avance',
            'montant_n_centimes' => 3000,
            'montant_n_1_centimes' => 2000,
        ],
        [
            'code' => 'decouverts_bancaires',
            'libelle' => 'Découverts bancaires',
            'montant_n_centimes' => 4000,
            'montant_n_1_centimes' => 1000,
        ],
    ]);
});

it('conserve les soldes inverses des sous-comptes 401x et 411x dans leurs rubriques naturelles', function (): void {
    $fournisseurDebiteur = creerCompteBilan('4011', 'Fournisseurs débiteurs');
    $clientCrediteur = creerCompteBilan('4111', 'Clients créditeurs');

    enregistrerLigneBilan($this, $fournisseurDebiteur, '2025-10-01', 4000, 0);
    enregistrerLigneBilan($this, $clientCrediteur, '2025-10-01', 0, 4000);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toBe([
        [
            'code' => 'creances_clients',
            'libelle' => 'Créances clients',
            'brut_n_centimes' => -4000,
            'amortissements_provisions_n_centimes' => 0,
            'net_n_centimes' => -4000,
            'net_n_1_centimes' => 0,
        ],
    ])->and($bilan['passif'])->toBe([
        [
            'code' => 'dettes_fournisseurs',
            'libelle' => 'Dettes fournisseurs',
            'montant_n_centimes' => -4000,
            'montant_n_1_centimes' => 0,
        ],
    ])->and($bilan['ecart_actif_passif']['n_centimes'])->toBe(0);
});

it('totalise les deux colonnes et expose l écart actif moins passif en centimes', function (): void {
    $banque = creerCompteBilan('5121', 'Banque');
    $fonds = creerCompteBilan('102', 'Fonds associatifs');

    enregistrerLigneBilan($this, $banque, '2024-10-01', 50000, 0);
    enregistrerLigneBilan($this, $fonds, '2024-10-01', 0, 50000);
    enregistrerLigneBilan($this, $banque, '2025-09-01', 70000, 0, TypeTransaction::AN);
    enregistrerLigneBilan($this, $fonds, '2025-09-01', 0, 68000, TypeTransaction::AN);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['totaux'])->toBe([
        'actif_n_brut_centimes' => 70000,
        'actif_n_amortissements_provisions_centimes' => 0,
        'actif_n_net_centimes' => 70000,
        'actif_n_1_net_centimes' => 50000,
        'passif_n_centimes' => 68000,
        'passif_n_1_centimes' => 50000,
    ])->and($bilan['ecart_actif_passif'])->toBe([
        'n_centimes' => 2000,
        'n_1_centimes' => 0,
    ]);
});

it('ignore le tenant voisin et reste vide sans contexte tenant', function (): void {
    $associationCourante = TenantContext::current();
    $compteCourant = creerCompteBilan('5121', 'Banque tenant courant');
    enregistrerLigneBilan($this, $compteCourant, '2025-10-01', 10000, 0);

    $associationVoisine = Association::factory()->create();
    TenantContext::boot($associationVoisine);
    $compteVoisin = creerCompteBilan('5121', 'Banque tenant voisin');
    enregistrerLigneBilan($this, $compteVoisin, '2025-10-01', 90000, 0);

    TenantContext::boot($associationCourante);
    $bilanCourant = app(BilanComptableBuilder::class)->build(2025);

    TenantContext::clear();
    $bilanSansTenant = app(BilanComptableBuilder::class)->build(2025);

    expect($bilanCourant['totaux']['actif_n_net_centimes'])->toBe(10000)
        ->and($bilanSansTenant['actif'])->toBe([])
        ->and($bilanSansTenant['passif'])->toBe([])
        ->and($bilanSansTenant['totaux']['actif_n_net_centimes'])->toBe(0)
        ->and($bilanSansTenant['totaux']['passif_n_centimes'])->toBe(0);
});

it('utilise le calendrier exercice du tenant pour les labels et le résultat courant', function (): void {
    $association = TenantContext::current();
    $association->update(['exercice_mois_debut' => 1]);
    TenantContext::boot($association->fresh());

    $compte606 = creerCompteBilan('6063', 'Fournitures');
    $compte706 = creerCompteBilan('706', 'Prestations');
    enregistrerLigneBilan($this, $compte606, '2025-01-15', 10000, 0);
    enregistrerLigneBilan($this, $compte706, '2025-12-15', 0, 30000);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['label_n'])->toBe('2025')
        ->and($bilan['label_n_1'])->toBe('2024')
        ->and($bilan['resultat_courant']['n_centimes'])->toBe(20000);
});

it('masque les rubriques dont les montants nets sont nuls sur N et N moins 1', function (): void {
    $stock = creerCompteBilan('371', 'Stocks de marchandises');
    $depreciation = creerCompteBilan('391', 'Dépréciation des stocks');

    enregistrerLigneBilan($this, $stock, '2025-10-15', 40000, 0);
    enregistrerLigneBilan($this, $depreciation, '2025-10-16', 0, 40000);

    $bilan = app(BilanComptableBuilder::class)->build(2025);

    expect($bilan['actif'])->toBe([])
        ->and($bilan['totaux']['actif_n_brut_centimes'])->toBe(0)
        ->and($bilan['totaux']['actif_n_amortissements_provisions_centimes'])->toBe(0)
        ->and($bilan['totaux']['actif_n_net_centimes'])->toBe(0);
});
