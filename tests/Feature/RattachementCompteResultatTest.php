<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Services\Rapports\BilanComptableBuilder;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * Le cas des 175 € bout en bout (AC-1, AC-2, AC-3 de la spec du 2026-09-30).
 *
 * Ce fichier existe parce que la spec de juin 2026 spécifiait un schéma d'écriture
 * inversé — elle débitait le 487, de classe 4, en croyant retirer un produit, alors
 * que le compte de résultat ne lit que les classes 6 et 7 — et que ses tests
 * vérifiaient les comptes, le journal et les dates sans jamais demander quel effet
 * l'écriture avait sur le résultat. Ici on ne regarde pas l'écriture : on regarde les
 * euros que lisent le compte de résultat de l'association, celui de l'opération et le
 * bilan, sur les deux exercices.
 *
 * ⚠️ AC-1 ne peut pas être vérifié sous SQLite. La dotation est datée du DERNIER jour
 * de l'exercice (31/08/2026), et SQLite stocke les colonnes `date` castées en
 * « 2026-08-31 00:00:00 » : un whereBetween dont la borne est « 2026-08-31 » compare une
 * chaîne plus courte, qui trie avant, et exclut la dotation (le CR 2025 rend 175 au lieu
 * de 0). MySQL et MariaDB l'incluent, la production est donc juste : seul l'environnement
 * de test diverge, et c'est ce piège qui avait masqué en juin 2026 le double-comptage des
 * provisions. AC-1 saute sous SQLite et tourne sous phpunit.mysql.xml (job CI bloquant).
 * On ne date PAS la dotation un jour plus tôt pour le faire passer : ce serait encoder
 * une fausse spec.
 *
 * AC-3 n'est pas concerné : le bilan lit les soldes du grand livre (BalanceComptableBuilder,
 * résultat courant en whereDate), pas le whereBetween du compte de résultat. Il tourne
 * sous les deux moteurs, et c'est lui qui garde le sens de l'écriture dans la suite par
 * défaut, où AC-1 est sauté.
 */
const RATTACHEMENT_SQLITE_SKIP_RAISON = 'La dotation est datée du dernier jour de l’exercice (31/08/2026) : '
    .'SQLite compare « 2026-08-31 00:00:00 » à la borne « 2026-08-31 » et l’exclut du whereBetween '
    .'du compte de résultat, MySQL et MariaDB l’incluent. À exécuter sous phpunit.mysql.xml.';

beforeEach(function () {
    // Le tenant par défaut est posé par le bootstrap global de tests/Pest.php.
    $this->association = Association::query()->findOrFail(TenantContext::currentId());
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486, 487 et les comptes de trésorerie : posés par le seeder système.
    SystemeSeeder::seed();

    foreach ([2025, 2026] as $annee) {
        Exercice::create(['association_id' => $this->association->id, 'annee' => $annee, 'statut' => StatutExercice::Ouvert]);
    }

    // Le cas réel du § 9 : une opération entièrement sur 2026-2027...
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'EquiThe FE sept 2026',
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);

    // ...dont le produit a été encaissé le 08/04/2026, donc compté en 2025-2026.
    $this->compte706 = Compte::factory()->numero('706B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Parcours thérapeutiques',
    ]);

    $this->ligne = pieceEncaissee($this->compte706, 175.0, $this->operation);
});

/**
 * Une pièce de recette du 08/04/2026, équilibrée : le produit au crédit sur le compte
 * donné (porteur de l'opération), l'encaissement au débit en caisse. Retourne la ligne
 * de produit, celle que le rattachement prend pour origine.
 */
function pieceEncaissee(Compte $compteProduit, float $montant, Operation $operation): TransactionLigne
{
    $transaction = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'date' => '2026-04-08',
        'libelle' => 'HelloAsso — Membership',
        'montant_total' => $montant,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Vente,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => compteSysteme('530')->id,
        'debit' => $montant,
        'credit' => 0,
        'montant' => $montant,
        'libelle' => 'Encaissement',
    ]);

    return TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteProduit->id,
        'debit' => 0,
        'credit' => $montant,
        'montant' => $montant,
        'operation_id' => $operation->id,
        'libelle' => 'Parcours thérapeutique',
    ]);
}

/**
 * Une pièce de dépense du 08/04/2026, équilibrée : la charge au débit sur le compte
 * donné (porteur de l'opération), le paiement au crédit en caisse. Retourne la ligne de
 * charge, celle que le rattachement prend pour origine.
 */
function pieceDepensee(Compte $compteCharge, float $montant, Operation $operation): TransactionLigne
{
    $transaction = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Depense,
        'date' => '2026-04-08',
        'libelle' => 'Assurance saison 2026-2027',
        'montant_total' => $montant,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Achat,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => compteSysteme('530')->id,
        'debit' => 0,
        'credit' => $montant,
        'montant' => $montant,
        'libelle' => 'Paiement',
    ]);

    return TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteCharge->id,
        'debit' => $montant,
        'credit' => 0,
        'montant' => $montant,
        'operation_id' => $operation->id,
        'libelle' => 'Assurance',
    ]);
}

/** Rattache la ligne à l'exercice 2025 et génère la dotation et l'extourne. */
function rattacherAvecEcritures(TransactionLigne $ligne): Provision
{
    $provision = Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);

    app(ProvisionPDService::class)->generer($provision);

    return $provision;
}

/**
 * Montant d'un compte dans un compte de résultat (hiérarchie famille → comptes), lu à
 * la clé donnée ; `null` quand le compte n'y figure pas du tout.
 *
 * `null` n'est PAS zéro, et la différence porte l'assertion : 0.0 veut dire « des
 * mouvements qui se compensent » — pour la PCA, le produit de 175 € ET sa dotation de
 * −175 € sont lus, et leur somme est nulle. Un helper qui rendrait 0.0 pour un compte
 * introuvable ferait passer ces assertions à vide, sur n'importe quelle erreur de clé.
 *
 * @param  array<string, mixed>  $compteDeResultat
 */
function montantDuCompte(array $compteDeResultat, string $section, Compte $compte, string $cle): ?float
{
    foreach ($compteDeResultat[$section] ?? [] as $famille) {
        foreach ($famille['comptes'] ?? [] as $ligne) {
            if ((int) $ligne['compte_id'] === (int) $compte->id) {
                return round((float) $ligne[$cle], 2);
            }
        }
    }

    return null;
}

/**
 * Le montant du compte au compte de résultat de l'ASSOCIATION pour l'exercice, dans la
 * section de sa classe : produits pour un 7xx, charges pour un 6xx.
 */
function montantAssociation(int $exercice, Compte $compte): ?float
{
    $rapport = app(RapportService::class)->compteDeResultat($exercice);

    return montantDuCompte($rapport, (int) $compte->classe === 7 ? 'produits' : 'charges', $compte, 'montant_n');
}

/**
 * Le montant du compte au compte de résultat de l'OPÉRATION pour l'exercice. Ce n'est
 * pas le même chemin de calcul que celui de l'association (jointure sur l'opération de
 * la ligne, pas sur le seul compte) : les deux doivent être vérifiés.
 */
function montantOperation(int $exercice, Operation $operation, Compte $compte): ?float
{
    $rapport = app(RapportService::class)->compteDeResultatOperations($exercice, [(int) $operation->id]);

    return montantDuCompte($rapport, (int) $compte->classe === 7 ? 'produits' : 'charges', $compte, 'montant');
}

/**
 * Une rubrique du bilan, ou `null` quand elle est absente — le builder écarte les
 * rubriques dont les deux colonnes (N et N-1) sont nulles. Les montants sont en
 * centimes : `montant_n_centimes` au passif, `net_n_centimes` à l'actif.
 *
 * @param  'actif'|'passif'  $cote
 * @return array<string, int|string>|null
 */
function rubriqueDuBilan(int $exercice, string $cote, string $code): ?array
{
    $bilan = app(BilanComptableBuilder::class)->build($exercice);

    return collect($bilan[$cote])->firstWhere('code', $code);
}

test('AC-1 — la PCA met le 706B à zéro sur 2025-2026 et le reconnaît sur 2026-2027, au CR association ET au CR opération', function () {
    // Avant : le produit pèse sur le mauvais exercice, des deux côtés.
    expect(montantAssociation(2025, $this->compte706))->toBe(175.0);
    expect(montantOperation(2025, $this->operation, $this->compte706))->toBe(175.0);
    // Aucun mouvement en 2026-2027 : la ligne manque (opération) ou n'existe que par la
    // colonne N-1 (association). Les deux se lisent « zéro euro ».
    expect(montantAssociation(2026, $this->compte706) ?? 0.0)->toBe(0.0);
    expect(montantOperation(2026, $this->operation, $this->compte706) ?? 0.0)->toBe(0.0);

    rattacherAvecEcritures($this->ligne);

    // Après : les quatre montants du § 9 de la spec.
    expect(montantAssociation(2025, $this->compte706))->toBe(0.0);
    expect(montantAssociation(2026, $this->compte706))->toBe(175.0);
    expect(montantOperation(2025, $this->operation, $this->compte706))->toBe(0.0);
    expect(montantOperation(2026, $this->operation, $this->compte706))->toBe(175.0);
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', RATTACHEMENT_SQLITE_SKIP_RAISON);

test('AC-3 — le 487 sort au passif en « Produits constatés d’avance » au 31/08/2026, puis disparaît au 31/08/2027', function () {
    expect(rubriqueDuBilan(2025, 'passif', 'produits_constates_avance'))->toBeNull();

    rattacherAvecEcritures($this->ligne);

    $pca = rubriqueDuBilan(2025, 'passif', 'produits_constates_avance');

    expect($pca)->not->toBeNull('La rubrique « Produits constatés d’avance » est absente du passif au 31/08/2026.');
    expect($pca['libelle'])->toBe('Produits constatés d’avance');
    expect($pca['montant_n_centimes'])->toBe(17500);

    // Au 31/08/2027 l'extourne a soldé le 487 : plus rien en N, et la colonne N-1 garde les 175 €.
    $pcaSuivant = rubriqueDuBilan(2026, 'passif', 'produits_constates_avance');

    expect($pcaSuivant['montant_n_centimes'])->toBe(0);
    expect($pcaSuivant['montant_n_1_centimes'])->toBe(17500);
});

test('AC-1 (CCA) — la charge payée d’avance sort du résultat 2025-2026 et entre dans 2026-2027, au CR association ET au CR opération', function () {
    // Le même cas, côté charges : 90 € d'assurance payés le 08/04/2026 pour la saison suivante.
    $compte616 = Compte::factory()->numero('616')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Assurances',
    ]);
    $ligne = pieceDepensee($compte616, 90.0, $this->operation);

    // Avant : la charge pèse sur le mauvais exercice, des deux côtés.
    expect(montantAssociation(2025, $compte616))->toBe(90.0);
    expect(montantOperation(2025, $this->operation, $compte616))->toBe(90.0);
    expect(montantAssociation(2026, $compte616) ?? 0.0)->toBe(0.0);
    expect(montantOperation(2026, $this->operation, $compte616) ?? 0.0)->toBe(0.0);

    rattacherAvecEcritures($ligne);

    expect(montantAssociation(2025, $compte616))->toBe(0.0);
    expect(montantAssociation(2026, $compte616))->toBe(90.0);
    expect(montantOperation(2025, $this->operation, $compte616))->toBe(0.0);
    expect(montantOperation(2026, $this->operation, $compte616))->toBe(90.0);
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', RATTACHEMENT_SQLITE_SKIP_RAISON);

test('AC-3 (CCA) — le 486 sort à l’actif en « Charges constatées d’avance » au 31/08/2026, puis disparaît au 31/08/2027', function () {
    $compte616 = Compte::factory()->numero('616')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Assurances',
    ]);
    $ligne = pieceDepensee($compte616, 90.0, $this->operation);

    expect(rubriqueDuBilan(2025, 'actif', 'charges_constatees_avance'))->toBeNull();

    rattacherAvecEcritures($ligne);

    $cca = rubriqueDuBilan(2025, 'actif', 'charges_constatees_avance');

    expect($cca)->not->toBeNull('La rubrique « Charges constatées d’avance » est absente de l’actif au 31/08/2026.');
    expect($cca['libelle'])->toBe('Charges constatées d’avance');
    expect($cca['net_n_centimes'])->toBe(9000);

    $ccaSuivante = rubriqueDuBilan(2026, 'actif', 'charges_constatees_avance');

    expect($ccaSuivante['net_n_centimes'])->toBe(0);
    expect($ccaSuivante['net_n_1_centimes'])->toBe(9000);
});
