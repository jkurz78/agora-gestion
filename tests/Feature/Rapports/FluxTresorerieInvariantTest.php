<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

/**
 * Invariant du flux de trésorerie : solde d'ouverture + variation de la période
 * = solde de clôture. C'est la propriété qui définit un état de trésorerie ;
 * sans elle, le rapport n'est pas un flux, c'est une juxtaposition de chiffres.
 *
 * Le rapport ne la respecte pas aujourd'hui, et pour une raison structurelle :
 * il mesure des ENGAGEMENTS, pas de la trésorerie. Sa variation somme
 * `montant_total` des transactions de journal `vente` ou `achat`
 * (Transaction::scopeOperationnel), or en partie double une dette fournisseur
 * ne déplace aucun argent — c'est son règlement, en journal `banque`, qui le
 * fait, et ce journal est hors du scope. Le solde d'ouverture, lui, est
 * reconstitué à rebours à partir de `transactions.compte_id`, qui est nul sur
 * la quasi-totalité des règlements séparés : une troisième base encore.
 *
 * La trésorerie, c'est le solde des comptes de CLASSE 5 : les 51 (banques et
 * valeurs à l'encaissement) et les 53 (caisse). L'écart entre ce total et les
 * seuls 512 est précisément ce qui est à remettre en banque ou détenu en
 * espèces — ce n'est pas du bruit à exclure, c'est de la trésorerie détenue.
 *
 * Ce test échoue sur le code actuel. Il est le garde-fou de la correction.
 */
uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    session(['exercice_actif' => 2025]);

    $this->tiers = Tiers::factory()->create(['association_id' => (int) $this->association->id]);
    $this->service = app(TransactionService::class);

    /**
     * Solde réel de la trésorerie au sens comptable : mouvement net de tous les
     * comptes de classe 5, lu sur le grand livre. Aucune notion de journal ni
     * de `compte_id` d'en-tête n'intervient — seules les écritures comptent.
     */
    $this->tresorerieReelle = function (?string $jusquA = null): float {
        $query = TransactionLigne::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('comptes', 'comptes.id', '=', 'transaction_lignes.compte_id')
            ->where('comptes.association_id', (int) $this->association->id)
            ->where('comptes.classe', 5)
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('transactions.deleted_at');

        if ($jusquA !== null) {
            $query->whereDate('transactions.date', '<=', $jusquA);
        }

        return round((float) $query->selectRaw(
            'COALESCE(SUM(transaction_lignes.debit),0) - COALESCE(SUM(transaction_lignes.credit),0) AS net'
        )->value('net'), 2);
    };

    $this->depense = function (float $montant, string $date, ?ModePaiement $mode): void {
        $this->service->create([
            'type' => TypeTransaction::Depense->value,
            'date' => $date,
            'libelle' => 'Achat fournitures',
            'montant_total' => (string) $montant,
            'mode_paiement' => $mode?->value,
            'tiers_id' => (int) $this->tiers->id,
            'compte_id' => $mode === null ? null : (int) $this->compteBancaire->id,
        ], [[
            'compte_id' => (int) $this->compte606->id,
            'montant' => (string) $montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]]);
    };

    $this->recette = function (float $montant, string $date, ?ModePaiement $mode): void {
        $this->service->create([
            'type' => TypeTransaction::Recette->value,
            'date' => $date,
            'libelle' => 'Cotisation',
            'montant_total' => (string) $montant,
            'mode_paiement' => $mode?->value,
            'tiers_id' => (int) $this->tiers->id,
            'compte_id' => $mode === null ? null : (int) $this->compteBancaire->id,
        ], [[
            'compte_id' => (int) $this->compte706->id,
            'montant' => (string) $montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]]);
    };
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('respecte l’invariant ouverture + variation = clôture', function (): void {
    ($this->recette)(1000.00, '2025-10-05', ModePaiement::Virement);
    ($this->depense)(300.00, '2025-10-20', ModePaiement::Virement);
    // Dette non réglée : aucun euro ne bouge, elle ne doit pas peser sur le flux.
    ($this->depense)(5000.00, '2025-11-10', null);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);
    $synthese = $flux['synthese'];

    $soldeCloture = ($this->tresorerieReelle)('2026-08-31');

    expect(round($synthese['solde_ouverture'] + $synthese['variation'], 2))
        ->toBe(
            $soldeCloture,
            'Ouverture + variation doit retomber sur le solde de trésorerie réel.'
        );
});

it('ne compte comme mouvement que ce qui a réellement bougé', function (): void {
    ($this->recette)(1000.00, '2025-10-05', ModePaiement::Virement);
    ($this->depense)(300.00, '2025-10-20', ModePaiement::Virement);
    ($this->depense)(5000.00, '2025-11-10', null);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // 1 000 encaissés − 300 décaissés = 700. La dette de 5 000 n'a rien déplacé.
    expect($flux['synthese']['variation'])->toBe(700.00);
});

it('ignore une dette ouverte, quel que soit son montant', function (): void {
    ($this->depense)(50000.00, '2025-11-10', null);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    expect($flux['synthese']['variation'])->toBe(0.00)
        ->and(($this->tresorerieReelle)('2026-08-31'))->toBe(0.00);
});

it('compte les espèces et les chèques à encaisser comme de la trésorerie', function (): void {
    // Un chèque reçu débite 5112 (valeurs à l'encaissement) : l'association
    // détient la valeur avant même la remise en banque.
    ($this->recette)(400.00, '2025-10-05', ModePaiement::Cheque);
    // Les espèces débitent 530.
    ($this->recette)(120.00, '2025-10-06', ModePaiement::Especes);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    expect($flux['synthese']['variation'])->toBe(520.00);

    // Et le détail doit dire où se trouve cet argent : rien en banque tant que
    // le chèque n'est pas remis, 400 à remettre, 120 en caisse.
    $comptes = collect(TransactionLigne::query()
        ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
        ->join('comptes', 'comptes.id', '=', 'transaction_lignes.compte_id')
        ->where('comptes.classe', 5)
        ->whereNull('transaction_lignes.deleted_at')
        ->whereNull('transactions.deleted_at')
        ->groupBy('comptes.numero_pcg')
        ->selectRaw('comptes.numero_pcg, SUM(transaction_lignes.debit)-SUM(transaction_lignes.credit) AS net')
        ->pluck('net', 'comptes.numero_pcg'));

    expect(round((float) $comptes->get('5112'), 2))->toBe(400.00)
        ->and(round((float) $comptes->get(Compte::ofNumeroSysteme('530')->numero_pcg), 2))->toBe(120.00);
});

/**
 * Le rapprochement bancaire n'a pas le même périmètre que la variation.
 *
 * La variation mesure « combien d'argent a bougé » : toute la classe 5, chèques
 * à encaisser et caisse compris, puisque c'est de la trésorerie détenue.
 *
 * Le rapprochement mesure « ce que la banque va me présenter » : les seuls
 * comptes qui ont un relevé, c'est-à-dire les 512X. Un chèque reçu non remis
 * (5112) et des espèces en caisse (530) n'y figureront jamais individuellement
 * — ils apparaîtront le jour de la remise, sous la forme d'une seule ligne, et
 * c'est cette remise qui se pointe. Les lister comme « en attente de pointage »
 * noierait le trésorier sous des écritures qu'il ne pourra jamais pointer.
 */
it('n’attend pas le pointage d’un chèque non remis ni des espèces en caisse', function (): void {
    ($this->recette)(400.00, '2025-10-05', ModePaiement::Cheque);
    ($this->recette)(120.00, '2025-10-06', ModePaiement::Especes);
    ($this->recette)(1000.00, '2025-10-07', ModePaiement::Virement);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // Les trois sont bien de la trésorerie entrée…
    expect($flux['synthese']['variation'])->toBe(1520.00);

    // …mais seul le virement passera sur un relevé bancaire.
    expect($flux['rapprochement']['recettes_non_pointees'])->toBe(1000.00)
        ->and($flux['rapprochement']['nb_recettes_non_pointees'])->toBe(1)
        ->and($flux['ecritures_non_pointees'])->toHaveCount(1);
});

it('nomme le fournisseur d’un règlement en attente de pointage', function (): void {
    ($this->depense)(180.00, '2025-10-20', ModePaiement::Virement);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    expect($flux['ecritures_non_pointees'])->toHaveCount(1)
        ->and($flux['ecritures_non_pointees'][0]['tiers'])
        ->toBe($this->tiers->displayName(), 'Le tiers vit sur la ligne 401, pas sur l’en-tête.');
});

/**
 * Décomposition de la trésorerie de clôture.
 *
 * « 0 écriture non pointée » est exact au sens du relevé bancaire, mais ne dit
 * pas que l'association détient des chèques non remis ou des espèces. Ces
 * sommes sont de la trésorerie — elles comptent dans la variation — sans être
 * en banque. La synthèse les nomme.
 */
it('décompose la trésorerie de clôture entre banque, caisse et à remettre', function (): void {
    ($this->recette)(1000.00, '2025-10-07', ModePaiement::Virement);
    ($this->recette)(400.00, '2025-10-05', ModePaiement::Cheque);
    ($this->recette)(120.00, '2025-10-06', ModePaiement::Especes);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);
    $d = $flux['synthese']['decomposition'];

    expect($d['banque'])->toBe(1000.00)
        ->and($d['a_remettre'])->toBe(400.00)
        ->and($d['caisse'])->toBe(120.00)
        ->and(round($d['banque'] + $d['a_remettre'] + $d['caisse'], 2))
        ->toBe($flux['synthese']['solde_theorique'], 'La décomposition doit totaliser la trésorerie de clôture.');
});

/**
 * Un virement interne est à somme nulle sur la trésorerie : il ne fait ni
 * entrer ni sortir d'argent, et n'a donc rien à faire dans la variation.
 *
 * Le rapprochement, lui, doit le compter dès qu'une de ses deux jambes n'est
 * pas pointée : c'est précisément ce qui justifie l'écart entre le solde
 * comptable et l'extrait de compte. Les deux jambes se pointent
 * indépendamment (rapprochement_source_id / rapprochement_destination_id) ;
 * quand elles le sont toutes les deux, l'écart est nul.
 */
it('ignore un virement interne dans la variation mais le compte au rapprochement', function (): void {
    $compte2 = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
    ]);

    VirementInterne::factory()->create([
        'association_id' => (int) $this->association->id,
        'date' => '2025-10-10',
        'montant' => 5000.00,
        'compte_source_id' => (int) $this->compteBancaire->id,
        'compte_destination_id' => (int) $compte2->id,
        'rapprochement_source_id' => null,
        'rapprochement_destination_id' => null,
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // Aucun euro n'est entré ni sorti de l'association.
    expect($flux['synthese']['variation'])->toBe(0.00);

    // Mais les deux jambes restent à pointer, une de chaque côté.
    expect($flux['rapprochement']['nb_recettes_non_pointees'])->toBe(1)
        ->and($flux['rapprochement']['nb_depenses_non_pointees'])->toBe(1)
        ->and($flux['rapprochement']['recettes_non_pointees'])->toBe(5000.00)
        ->and($flux['rapprochement']['depenses_non_pointees'])->toBe(5000.00);
});

/**
 * Pont résultat → trésorerie.
 *
 * Le résultat et le flux répondent à deux questions différentes et n'ont
 * aucune raison d'être égaux : une créance est un produit sans encaissement,
 * une acquisition d'immobilisation un décaissement sans charge. Le rapport
 * doit montrer le chemin de l'un à l'autre, sinon le lecteur conclut à une
 * erreur là où il n'y a qu'une différence de nature.
 *
 * La ligne « autres » n'est pas un fourre-tout de confort : par identité de la
 * partie double, la somme des variations de tous les comptes est nulle. Elle
 * garantit donc que le pont boucle quelles que soient les écritures — y
 * compris celles qu'on n'a pas pensé à nommer.
 */
it('boucle le pont entre le résultat et la trésorerie', function (): void {
    // Une créance non encaissée : produit acquis, aucun euro reçu.
    ($this->recette)(40.00, '2025-10-01', null);
    // Une vente encaissée : produit ET trésorerie.
    ($this->recette)(1000.00, '2025-10-05', ModePaiement::Virement);
    // Une dette non payée : charge constatée, aucun euro sorti.
    ($this->depense)(300.00, '2025-11-10', null);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);
    $pont = $flux['synthese']['pont_resultat'];

    // Résultat = 1040 de produits − 300 de charges = 740.
    expect($pont['resultat'])->toBe(740.00);

    // Trésorerie = seuls les 1000 encaissés.
    expect($flux['synthese']['variation'])->toBe(1000.00);

    // Et le chemin de l'un à l'autre boucle au centime.
    $somme = round(
        $pont['resultat'] + $pont['creances'] + $pont['dettes']
        + $pont['immobilisations'] + $pont['dotations'] + $pont['autres'],
        2
    );

    expect($somme)->toBe($flux['synthese']['variation'], 'Le pont doit retomber sur la variation de trésorerie.');
});

/**
 * Second pont : de la trésorerie détenue au solde des relevés bancaires.
 *
 * Le solde théorique porte TOUTE la trésorerie, chèques en main et caisse
 * compris. Pour retomber sur ce que disent les relevés, il faut en retrancher
 * ce qui n'est pas en banque, puis corriger des mouvements bancaires que la
 * banque n'a pas encore passés. Sans la première soustraction, le bas de page
 * ne tombait pas juste — c'est ce que la recette a fait apparaître.
 */
it('retombe sur le solde des relevés bancaires', function (): void {
    ($this->recette)(1000.00, '2025-10-07', ModePaiement::Virement);
    ($this->recette)(400.00, '2025-10-05', ModePaiement::Cheque);
    ($this->recette)(120.00, '2025-10-06', ModePaiement::Especes);
    ($this->depense)(180.00, '2025-10-20', ModePaiement::Virement);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);
    $s = $flux['synthese'];
    $r = $flux['rapprochement'];

    // Trésorerie totale : 1000 + 400 + 120 − 180 = 1340.
    expect($s['solde_theorique'])->toBe(1340.00);

    // Dont 820 en banque, 400 à remettre, 120 en caisse.
    expect($s['decomposition']['banque'])->toBe(820.00)
        ->and($s['decomposition']['a_remettre'])->toBe(400.00)
        ->and($s['decomposition']['caisse'])->toBe(120.00);

    // Le relevé, lui, ne connaît ni les chèques ni la caisse. Et les deux
    // mouvements bancaires ne sont pas encore pointés : +180 de dépense,
    // −1000 de recette.
    expect($r['solde_banque'])->toBe(820.00)
        ->and($r['solde_reel'])->toBe(0.00);
});

/**
 * Le pont doit boucler AVEC une dotation et une immobilisation payée — les
 * deux postes qui écartent le plus le résultat de la trésorerie, et les deux
 * que les jeux d'essai précédents laissaient à zéro.
 */
it('boucle le pont avec une dotation et une immobilisation', function (): void {
    ($this->recette)(1000.00, '2025-10-05', ModePaiement::Virement);

    // Immobilisation payée comptant : décaissement sans charge.
    $compte2154 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '2154', 'classe' => 2,
    ]);
    $compte6811 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '6811', 'classe' => 6,
    ]);
    $compte28154 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '28154', 'classe' => 2,
    ]);

    $ecriture = function (string $date, array $lignes): void {
        $tx = Transaction::factory()->create([
            'association_id' => (int) $this->association->id,
            'type' => 'depense',
            'date' => $date,
            'montant_total' => 0,
            'journal' => JournalComptable::Od,
        ]);
        foreach ($lignes as [$compte, $debit, $credit]) {
            TransactionLigne::factory()->create([
                'transaction_id' => $tx->id,
                'compte_id' => $compte->id,
                'debit' => $debit, 'credit' => $credit, 'montant' => 0,
            ]);
        }
    };

    // Acquisition payée : 2154 D 600 / 512X C 600.
    $ecriture('2025-11-02', [[$compte2154, 600.0, 0.0], [$this->compte512X, 0.0, 600.0]]);
    // Dotation : 6811 D 150 / 28154 C 150. Aucune trésorerie.
    $ecriture('2026-08-15', [[$compte6811, 150.0, 0.0], [$compte28154, 0.0, 150.0]]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);
    $pont = $flux['synthese']['pont_resultat'];

    // Résultat : 1000 de produits − 150 de dotation = 850.
    expect($pont['resultat'])->toBe(850.00)
        ->and($pont['dotations'])->toBe(150.00, 'La dotation se rend au pont, elle ne se retranche pas.')
        ->and($pont['immobilisations'])->toBe(-600.00);

    // Trésorerie : 1000 encaissés − 600 payés = 400.
    expect($flux['synthese']['variation'])->toBe(400.00);

    $somme = round(
        $pont['resultat'] + $pont['creances'] + $pont['dettes']
        + $pont['immobilisations'] + $pont['dotations'] + $pont['autres'],
        2
    );

    expect($somme)->toBe(400.00)
        ->and($pont['autres'])->toBe(0.00, 'Tous les postes sont nommés : rien ne doit tomber dans « autres ».');
});

it('affiche le libellé de la facture d’origine, pas celui du règlement', function (): void {
    $this->service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-20',
        'libelle' => 'Kaligrafik — Facture FA000696 cartes-tampons',
        'montant_total' => '180.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => (int) $this->tiers->id,
        'compte_id' => (int) $this->compteBancaire->id,
    ], [[
        'compte_id' => (int) $this->compte606->id,
        'montant' => '180.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    expect($flux['ecritures_non_pointees'])->toHaveCount(1)
        ->and($flux['ecritures_non_pointees'][0]['libelle'])
        ->toBe('Kaligrafik — Facture FA000696 cartes-tampons');
});
