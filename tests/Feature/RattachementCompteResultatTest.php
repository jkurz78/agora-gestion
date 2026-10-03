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
 * Le cas des 175 € de la provision aux rapports (AC-1 et AC-3 de la spec du 2026-09-30 ;
 * AC-2 n'a pas de test permanent, voir plus bas).
 *
 * Ce fichier existe parce que la spec de juin 2026 spécifiait un schéma d'écriture
 * inversé — elle débitait le 487, de classe 4, en croyant retirer un produit, alors
 * que le compte de résultat ne lit que les classes 6 et 7 — et que ses tests
 * vérifiaient les comptes, le journal et les dates sans jamais demander quel effet
 * l'écriture avait sur le résultat. Ici on ne regarde pas l'écriture : on regarde les
 * euros que lisent le compte de résultat de l'association, celui de l'opération et le
 * bilan, sur les deux exercices.
 *
 * Le parcours va « de la provision aux rapports » : Provision::create puis
 * ProvisionPDService::generer(). Il ne passe pas par RattachementExerciceService
 * (éligibilité, verrou) : le sens de l'écriture se décide dans le générateur, et c'est
 * lui que ces tests gardent.
 *
 * ⚠️ AC-1 ne peut pas être vérifié sous SQLite. La dotation est datée du DERNIER jour
 * de l'exercice (31/08/2026), et SQLite stocke les colonnes `date` castées en
 * « 2026-08-31 00:00:00 » : un whereBetween dont la borne est « 2026-08-31 » compare une
 * chaîne plus courte, qui trie avant, et exclut la dotation (le CR 2025 rend 175 au lieu
 * de 0). MySQL et MariaDB l'incluent, la production est donc juste : seul l'environnement
 * de test diverge, et c'est ce piège qui avait masqué en juin 2026 le double-comptage des
 * provisions. AC-1 saute sous SQLite et tourne sous phpunit.mysql.xml (job CI bloquant)
 * et phpunit.mariadb.xml. On ne date PAS la dotation un jour plus tôt pour le faire
 * passer : ce serait encoder une fausse spec.
 *
 * AC-3 n'est pas concerné : le bilan lit le grand livre par BalanceComptableBuilder, donc
 * par AnalysePeriodeComptable::requeteLignes() (whereDate en SQL, puis comparaison des
 * dates en PHP), et lit son résultat courant en whereDate : aucun des deux n'a le piège
 * du whereBetween. Il tourne sous les deux moteurs. Dans la suite par défaut (SQLite), où
 * AC-1 est sauté, il garde le sens de la jambe 486/487, celui de l'extourne (par
 * l'assertion de l'exercice suivant) et l'effet sur le résultat. Il ne garde PAS
 * l'operation_id de la ligne 6/7 (D8) ni le compte de la jambe résultat : le bilan ignore
 * l'opération et somme toutes les lignes de classe 6 et 7. Sous SQLite, ceux-là sont
 * couverts par Services/Compta/RattachementExerciceServiceTest. Ce qui rend ce fichier
 * non redondant avec lui : ici on lit les RAPPORTS (comptes de résultat, bilan), là-bas
 * on calcule l'impact sur l'ÉCRITURE.
 *
 * AC-2 (mutation) : inverser le sens dans le générateur doit faire tomber ces tests.
 * Pas de test permanent, c'est une expérience à rejouer à la main : copier le worktree
 * sous .worktrees/ (cp -cR), retirer le fichier .git de la copie pour qu'aucune commande
 * git n'atteigne le vrai dépôt, vérifier d'abord que la copie NON mutée passe, puis
 * inverser `$resultatAuDebit` dans EcritureGenerator::pourRattachement() et relancer.
 * Attendu : sous MySQL/MariaDB les quatre tests tombent ; sous SQLite les deux AC-3
 * tombent et les deux AC-1 sont sautés. Exécutions consignées (sens global : toute
 * l'expression de `$resultatAuDebit` niée) :
 *  - 2026-10-01, MySQL 8.4, sens global : 4 tests sur 4 tombent ; SQLite : les 2 AC-3.
 *  - 2026-10-03, MariaDB 11.4, sens global : 4 sur 4 tombent (350 au lieu de 0 ; 35000 au
 *    lieu de 0 ; 180 au lieu de 0 ; −500 au lieu de 17500). SQLite : les 2 AC-3, sur le
 *    résultat courant du bilan (35000 au lieu de 0 ; −500 au lieu de 17500).
 *  - 2026-10-01, MySQL 8.4, CCA seule inversée dans CasProvision::dotationDebiteLeResultat() :
 *    les 2 tests CCA tombent, les 2 PCA restent verts (mesuré avant l'ajout des assertions
 *    sur le résultat courant du bilan).
 *  - 2026-10-03, MariaDB 11.4, extourne seule inversée (`? ! $cas->dotationDebiteLeResultat()`
 *    devenu `? $cas->dotationDebiteLeResultat()`) : 4 sur 4 tombent (−175 au lieu de 175 ;
 *    −17500 au lieu de 17500 ; −90 au lieu de 90 ; 9000 au lieu de −9000). SQLite : les 2 AC-3.
 *  - 2026-10-03, MariaDB 11.4, D8 retiré (`'operation_id' => null` sur la ligne 6/7) :
 *    les 2 AC-1 tombent au CR opération 2025 (175 et 90 au lieu de 0), les 2 AC-3 passent
 *    (le bilan ignore l'opération). Sous SQLite ce fichier passe : c'est
 *    RattachementExerciceServiceTest qui tombe (PCA et CCA).
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

    $this->ligne = rattachementPieceEncaissee($this->compte706, 175.0, $this->operation);
});

/**
 * Une pièce de recette du 08/04/2026, équilibrée : le produit au crédit sur le compte
 * donné, l'encaissement au débit en caisse. C'est la ligne de produit qui porte
 * l'opération. Retourne cette ligne, celle que le rattachement prend pour origine.
 */
function rattachementPieceEncaissee(Compte $compteProduit, float $montant, Operation $operation): TransactionLigne
{
    $transaction = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'date' => '2026-04-08',
        'libelle' => 'Encaissement en caisse — parcours thérapeutique',
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
 * donné, le paiement au crédit en caisse. C'est la ligne de charge qui porte
 * l'opération. Retourne cette ligne, celle que le rattachement prend pour origine.
 */
function rattachementPieceDepensee(Compte $compteCharge, float $montant, Operation $operation): TransactionLigne
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
 * La section du compte de résultat où figure un compte : produits pour la classe 7,
 * charges pour la classe 6. Toute autre classe n'a rien à faire ici et lève, plutôt que
 * d'être rangée en silence dans les charges.
 */
function rattachementSectionDuCompte(Compte $compte): string
{
    return match ((int) $compte->classe) {
        6 => 'charges',
        7 => 'produits',
        default => throw new InvalidArgumentException(
            "Le compte {$compte->numero_pcg} est de classe {$compte->classe} : il ne figure pas au compte de résultat."
        ),
    };
}

/**
 * Montant d'un compte dans un compte de résultat (hiérarchie famille → comptes), lu à
 * la clé donnée ; `null` quand le compte n'y figure pas du tout.
 *
 * `null` n'est PAS zéro, et la différence porte l'assertion : `null` veut dire que le
 * compte ne figure pas au rapport, 0.0 qu'il y figure avec un montant nul pour
 * l'exercice. Un helper qui rendrait 0.0 pour un compte introuvable ferait passer ces
 * assertions à vide, sur n'importe quelle erreur de clé.
 *
 * @param  array<string, mixed>  $compteDeResultat
 */
function rattachementMontantDuCompte(array $compteDeResultat, string $section, Compte $compte, string $cle): ?float
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
function rattachementMontantAuCrAssociation(int $exercice, Compte $compte): ?float
{
    $rapport = app(RapportService::class)->compteDeResultat($exercice);

    return rattachementMontantDuCompte($rapport, rattachementSectionDuCompte($compte), $compte, 'montant_n');
}

/**
 * Le montant du compte au compte de résultat de l'OPÉRATION pour l'exercice. Ce n'est
 * pas le même chemin de calcul que celui de l'association : fetchOperationRowsPD filtre
 * sur `tl.operation_id` (whereIn), et non sur le seul compte. Le CR de l'opération ne
 * voit donc la dotation que si la ligne 6/7 porte l'operation_id (D8) — c'est pour cela
 * qu'il est vérifié séparément.
 */
function rattachementMontantAuCrOperation(int $exercice, Operation $operation, Compte $compte): ?float
{
    $rapport = app(RapportService::class)->compteDeResultatOperations($exercice, [(int) $operation->id]);

    return rattachementMontantDuCompte($rapport, rattachementSectionDuCompte($compte), $compte, 'montant');
}

/**
 * Une rubrique du bilan, ou `null` quand elle est absente — le builder écarte les
 * rubriques dont les deux colonnes (N et N-1) sont nulles. Les montants sont en
 * centimes : `montant_n_centimes` au passif, `net_n_centimes` à l'actif.
 *
 * @param  'actif'|'passif'  $cote
 * @return array<string, int|string>|null
 */
function rattachementRubriqueDuBilan(int $exercice, string $cote, string $code): ?array
{
    $bilan = app(BilanComptableBuilder::class)->build($exercice);

    return collect($bilan[$cote])->firstWhere('code', $code);
}

/**
 * Le résultat courant du bilan pour l'exercice, en centimes : somme (crédit − débit) de
 * toutes les lignes de classe 6 et 7 hors journal d'à-nouveau, quel que soit le compte,
 * lue en whereDate — donc juste sous SQLite, contrairement au whereBetween du compte de
 * résultat.
 *
 * @return array{n_centimes: int, n_1_centimes: int}
 */
function rattachementResultatCourantDuBilan(int $exercice): array
{
    return app(BilanComptableBuilder::class)->build($exercice)['resultat_courant'];
}

test('AC-1 — la PCA met le 706B à zéro sur 2025-2026 et le reconnaît sur 2026-2027, au CR association ET au CR opération', function () {
    // Avant : le produit pèse sur le mauvais exercice, des deux côtés.
    expect(rattachementMontantAuCrAssociation(2025, $this->compte706))->toBe(175.0);
    expect(rattachementMontantAuCrOperation(2025, $this->operation, $this->compte706))->toBe(175.0);
    // Aucun mouvement en 2026-2027, mais deux lectures différentes. Au CR association la
    // ligne existe par la colonne N-1 (les 175 € de 2025-2026), avec un montant N nul. Au
    // CR opération, qui ne lit que l'exercice demandé, elle est absente.
    expect(rattachementMontantAuCrAssociation(2026, $this->compte706))->toBe(0.0);
    expect(rattachementMontantAuCrOperation(2026, $this->operation, $this->compte706))->toBeNull();

    rattacherAvecEcritures($this->ligne);

    // Après : les quatre montants du § 9 de la spec.
    expect(rattachementMontantAuCrAssociation(2025, $this->compte706))->toBe(0.0);
    expect(rattachementMontantAuCrAssociation(2026, $this->compte706))->toBe(175.0);
    expect(rattachementMontantAuCrOperation(2025, $this->operation, $this->compte706))->toBe(0.0);
    expect(rattachementMontantAuCrOperation(2026, $this->operation, $this->compte706))->toBe(175.0);
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', RATTACHEMENT_SQLITE_SKIP_RAISON);

test('AC-3 — le 487 sort au passif en « Produits constatés d’avance » au 31/08/2026, puis se solde au 31/08/2027', function () {
    expect(rattachementRubriqueDuBilan(2025, 'passif', 'produits_constates_avance'))->toBeNull();
    // Avant : les 175 € pèsent sur le résultat 2025-2026.
    expect(rattachementResultatCourantDuBilan(2025)['n_centimes'])->toBe(17500);

    rattacherAvecEcritures($this->ligne);

    // L'effet sur le résultat : 175 € - 175 € en 2025-2026, puis +175 € en 2026-2027.
    expect(rattachementResultatCourantDuBilan(2025)['n_centimes'])->toBe(0);
    expect(rattachementResultatCourantDuBilan(2026)['n_centimes'])->toBe(17500);

    $pca = rattachementRubriqueDuBilan(2025, 'passif', 'produits_constates_avance');

    expect($pca)->not->toBeNull('La rubrique « Produits constatés d’avance » est absente du passif au 31/08/2026.');
    expect($pca['libelle'])->toBe('Produits constatés d’avance');
    expect($pca['montant_n_centimes'])->toBe(17500);

    // Au 31/08/2027 l'extourne a soldé le 487 : plus rien en N, et la colonne N-1 garde les 175 €.
    $pcaSuivant = rattachementRubriqueDuBilan(2026, 'passif', 'produits_constates_avance');

    expect($pcaSuivant)->not->toBeNull('La rubrique « Produits constatés d’avance » est absente du passif au 31/08/2027 : la colonne N-1 devrait y porter les 175 €.');
    expect($pcaSuivant['montant_n_centimes'])->toBe(0);
    expect($pcaSuivant['montant_n_1_centimes'])->toBe(17500);
});

/*
 * Les deux paires de tests, PCA puis CCA, se complètent et ne sont pas redondantes :
 * chacune ne garde que son propre cas. Le sens de l'écriture se décide CAS PAR CAS :
 * CasProvision::dotationDebiteLeResultat() dit, pour chaque cas, si la dotation débite ou
 * crédite le résultat. Une inversion de la seule CCA laisse les tests PCA verts (mesuré le
 * 2026-10-01) : sans la paire ci-dessous, ce fichier la laisserait passer, et seul
 * RattachementExerciceServiceTest la verrait, sur l'écriture et non sur les rapports.
 * Ne pas supprimer les tests CCA parce qu'ils « répètent » les PCA.
 */

test('Même garde que l’AC-1, côté charges — la charge payée d’avance sort du résultat 2025-2026 et entre dans 2026-2027, au CR association ET au CR opération', function () {
    // Le même cas, côté charges : 90 € d'assurance payés le 08/04/2026 pour la saison suivante.
    $compte616 = Compte::factory()->numero('616')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Assurances',
    ]);
    $ligne = rattachementPieceDepensee($compte616, 90.0, $this->operation);

    // Avant : la charge pèse sur le mauvais exercice, des deux côtés.
    expect(rattachementMontantAuCrAssociation(2025, $compte616))->toBe(90.0);
    expect(rattachementMontantAuCrOperation(2025, $this->operation, $compte616))->toBe(90.0);
    // Comme pour le 706B : présent par la colonne N-1 au CR association, absent au CR opération.
    expect(rattachementMontantAuCrAssociation(2026, $compte616))->toBe(0.0);
    expect(rattachementMontantAuCrOperation(2026, $this->operation, $compte616))->toBeNull();

    rattacherAvecEcritures($ligne);

    expect(rattachementMontantAuCrAssociation(2025, $compte616))->toBe(0.0);
    expect(rattachementMontantAuCrAssociation(2026, $compte616))->toBe(90.0);
    expect(rattachementMontantAuCrOperation(2025, $this->operation, $compte616))->toBe(0.0);
    expect(rattachementMontantAuCrOperation(2026, $this->operation, $compte616))->toBe(90.0);
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', RATTACHEMENT_SQLITE_SKIP_RAISON);

test('Même garde que l’AC-3, côté charges — le 486 sort à l’actif en « Charges constatées d’avance » au 31/08/2026, puis se solde au 31/08/2027', function () {
    $compte616 = Compte::factory()->numero('616')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Assurances',
    ]);
    $ligne = rattachementPieceDepensee($compte616, 90.0, $this->operation);

    expect(rattachementRubriqueDuBilan(2025, 'actif', 'charges_constatees_avance'))->toBeNull();
    // Avant : le résultat 2025-2026 porte le produit de 175 € de beforeEach, que ce test ne
    // rattache pas, moins la charge de 90 € : 17500 - 9000.
    expect(rattachementResultatCourantDuBilan(2025)['n_centimes'])->toBe(8500);

    rattacherAvecEcritures($ligne);

    // L'effet sur le résultat : la charge sort de 2025-2026, qui ne garde plus que les
    // 175 € de produit, et entre dans 2026-2027 (-90 €).
    expect(rattachementResultatCourantDuBilan(2025)['n_centimes'])->toBe(17500);
    expect(rattachementResultatCourantDuBilan(2026)['n_centimes'])->toBe(-9000);

    $cca = rattachementRubriqueDuBilan(2025, 'actif', 'charges_constatees_avance');

    expect($cca)->not->toBeNull('La rubrique « Charges constatées d’avance » est absente de l’actif au 31/08/2026.');
    expect($cca['libelle'])->toBe('Charges constatées d’avance');
    expect($cca['net_n_centimes'])->toBe(9000);

    $ccaSuivante = rattachementRubriqueDuBilan(2026, 'actif', 'charges_constatees_avance');

    expect($ccaSuivante)->not->toBeNull('La rubrique « Charges constatées d’avance » est absente de l’actif au 31/08/2027 : la colonne N-1 devrait y porter les 90 €.');
    expect($ccaSuivante['net_n_centimes'])->toBe(0);
    expect($ccaSuivante['net_n_1_centimes'])->toBe(9000);
});
