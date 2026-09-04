<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Famille;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

/**
 * Ligne d'un compte dans une section du rapport budget, ou null.
 *
 * @param  list<array>  $section
 */
function ligneCompteDuBudget(array $section, int $compteId): ?array
{
    foreach ($section as $famille) {
        foreach ($famille['comptes'] as $compte) {
            if ((int) $compte['compte_id'] === $compteId) {
                return $compte;
            }
        }
    }

    return null;
}

it('un compte ventile sans mouvement affiche un budget et un realise a zero', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBe(300.00);
    expect($ligne['realise'])->toBe(0.0);
    expect($ligne['prevision'])->toBeNull();
    expect($ligne['hors_dotation'])->toBeFalse();
    expect($data[(int) $operation->id]['operation_nom'])->toBe($operation->nom);
});

it('un compte sans prevision porte null et non zero', function (): void {
    // La distinction est TOUT le contrat : null = « cette grandeur ne parle pas
    // ici », 0.0 = « elle parle et dit zero ». Un ?? 0.0 pose par reflexe
    // detruirait l information sans rien casser visiblement.
    $compte = Compte::factory()->numero('741')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Subventions',
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 1200.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['produits'], (int) $compte->id);

    expect($ligne['prevision'])->toBeNull();
});

it('un compte mouvemente sans ventilation est hors dotation', function (): void {
    $compte = Compte::factory()->numero('625B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Deplacements',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBeNull();
    expect($ligne['realise'])->toBe(150.00);
    expect($ligne['hors_dotation'])->toBeTrue();
    expect($data[(int) $operation->id]['totaux']['charges']['hors_dotation'])->toBe(150.00);
});

it('un compte mouvemente et ventile n est pas hors dotation', function (): void {
    // Symetrique du test precedent : le marqueur exige budget === null. Sans
    // ce garde, un compte parfaitement dote (budget ET realise renseignes)
    // serait a tort signale hors dotation des que son realise est non nul —
    // c'est-a-dire pour la quasi-totalite des comptes ventiles du rapport.
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBe(300.00);
    expect($ligne['realise'])->toBe(150.00);
    expect($ligne['hors_dotation'])->toBeFalse();
    expect($data[(int) $operation->id]['totaux']['charges']['hors_dotation'])->toBe(0.0);
});

it('une prevision seule cree sa ligne sans la rendre hors dotation', function (): void {
    // Trois sources creent des lignes a egalite. Une prevision sans mouvement
    // n'a rien consomme : il n'y a rien a qualifier de hors dotation.
    $compte = Compte::factory()->numero('611B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Animation',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $seance = Seance::factory()->create([
        'association_id' => $this->association->id,
        'operation_id' => $operation->id,
        'date' => '2025-10-15',
        'numero' => 1,
    ]);
    EncadrementPrevision::factory()->create([
        'association_id' => $this->association->id,
        'operation_id' => $operation->id,
        'seance_id' => $seance->id,
        'compte_id' => $compte->id,
        'montant_prevu' => 3750.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne)->not->toBeNull();
    expect($ligne['prevision'])->toBe(3750.00);
    expect($ligne['budget'])->toBeNull();
    expect($ligne['realise'])->toBe(0.0);
    expect($ligne['hors_dotation'])->toBeFalse();
});

it('un total de famille entierement non couvert reste null', function (): void {
    $compte = Compte::factory()->numero('625B')->create([
        'association_id' => $this->association->id,
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);

    expect($data[(int) $operation->id]['totaux']['charges']['budget'])->toBeNull();
    expect($data[(int) $operation->id]['totaux']['charges']['realise'])->toBe(150.00);
});

it('un compte de classe 7 ne peut pas apparaitre du cote des charges', function (): void {
    // Le piege du lot 2a : une carte non filtree par classe faisait apparaitre
    // le meme compte des deux cotes.
    $compte = Compte::factory()->numero('706B')->create([
        'association_id' => $this->association->id,
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 250.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);

    expect(ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id))->toBeNull();
    expect(ligneCompteDuBudget($data[(int) $operation->id]['produits'], (int) $compte->id))->not->toBeNull();
});

it('la ventilation d une autre association ne fuit pas dans le rapport', function (): void {
    // Rule de revue : un test d'isolation tenant ne doit jamais pouvoir passer
    // a vide. On pose donc une ligne LEGITIME pour le tenant courant (le
    // compte 606) a cote de la ligne fuyante de l'autre tenant, et on affirme
    // les deux : que le compte du voisin est absent ET que le notre est bien
    // present. Sans la ligne legitime, le tableau charges serait vide que la
    // fuite soit bouchee ou non — l'assertion toBeNull ne prouverait rien.
    //
    // Ce que ce test prouve REELLEMENT, verifie par mutation : une
    // budget_line entierement posee par une autre association (compte ET
    // ligne du voisin) n'apparait pas dans le rapport. Il ne tue aucun filtre
    // pris isolement — dans ce scenario, `bl.association_id` de
    // ventilations() ecarte deja la ligne avant meme qu'elle atteigne
    // `c.association_id` ou metaComptes(). Retirer l'un ou l'autre de ces
    // deux filtres, ou le garde `isset($meta[...])`, laisse ce test vert :
    // c'est le test suivant (bl.association_id seul) et celui sur les
    // previsions plus bas (c.association_id de metaComptes() + le garde
    // isset) qui isolent ces protections precises.
    $autre = Association::factory()->create();
    $compteAutre = Compte::factory()->numero('606')->create([
        'association_id' => $autre->id,
        'intitule' => 'Fournitures du voisin',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $autre->id,
        'compte_id' => $compteAutre->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 999.00,
    ]);

    $compteLegitime = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
        'classe' => 6,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteLegitime->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 450.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);

    expect(ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compteAutre->id))->toBeNull();
    $ligneLegitime = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compteLegitime->id);
    expect($ligneLegitime)->not->toBeNull();
    expect($ligneLegitime['budget'])->toBe(450.00);
});

it('une ligne de budget posee par une autre association sur un compte du tenant courant ne gonfle pas son montant', function (): void {
    // Un test qui tue le filtre precis, distinct du precedent : ici le compte
    // ET l'operation appartiennent tous deux au tenant courant — seule la
    // ligne budget_lines elle-meme est posee par une autre association. Le
    // filtre sur `c.association_id` ne changerait rien (le compte est bien
    // au tenant courant) : seul `bl.association_id` peut ecarter cette ligne.
    // Sans lui, le SUM(bl.montant_prevu) engloutirait le montant du voisin
    // dans le budget du tenant courant — 300 + 999 au lieu de 300.
    $autre = Association::factory()->create();
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $autre->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 999.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBe(300.00);
});

it('le budget d un autre exercice sur le meme compte et la meme operation ne s additionne pas', function (): void {
    // Aucun fixture existant ne pose deux budget_lines sur des exercices
    // differents pour le meme couple (compte, operation) : retirer le
    // `->where('bl.exercice', $exercice)` de ventilations() laisse les autres
    // tests verts. Un compte ventile a la fois en 2024 et en 2025 sur la
    // meme operation verrait alors les deux montants additionnes, et l'ecart
    // budgetaire serait faux sur les cinq ecrans du lot.
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2024,
        'montant_prevu' => 999.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBe(300.00);
});

it('une famille agrege plusieurs comptes et une section plusieurs familles, sans jamais ne retenir que le dernier enfant', function (): void {
    // Aucun fixture existant ne pose deux comptes dans la meme famille, ni
    // deux familles dans la meme section : le cas central du contrat (des
    // enfants dont un seul est budgete, et une somme de plusieurs enfants
    // budgetes) n'etait exerce nulle part. Remplacer la somme par "le dernier
    // gagne" (`$budget = round($enfant['budget'], 2)`) aux deux niveaux
    // (agregerComptes() et agreger()) laissait les autres tests verts.
    //
    // Ce test verrouille aussi le tri alphabetique (compte_nom et
    // famille_nom, cf. finaliserSection()) : les familles et les comptes sont
    // crees dans l'ordre inverse du resultat attendu.
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    // Famille 62 (creee en premier, derniere attendue) : un seul compte,
    // jamais budgete mais mouvemente -> la famille reste a null.
    Famille::factory()->create(['association_id' => $this->association->id, 'code' => '62', 'nom' => 'Charlie']);
    $compteGolf = Compte::factory()->numero('621')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Golf',
        'classe' => 6,
    ]);
    $txGolf = Transaction::factory()->create(['association_id' => $this->association->id, 'date' => '2025-11-10']);
    TransactionLigne::factory()->create([
        'transaction_id' => $txGolf->id,
        'compte_id' => $compteGolf->id,
        'operation_id' => $operation->id,
        'debit' => 80.00,
        'credit' => 0,
    ]);

    // Famille 61 (creee en second) : deux comptes tous les deux budgetes,
    // 300 + 120 -> tue le "dernier gagne" a la maille famille, quel que soit
    // l'ordre de tri retenu (les deux valeurs sont non nulles et distinctes).
    Famille::factory()->create(['association_id' => $this->association->id, 'code' => '61', 'nom' => 'Bravo']);
    $compteFoxtrot = Compte::factory()->numero('611')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Foxtrot',
        'classe' => 6,
    ]);
    $compteEcho = Compte::factory()->numero('612')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Echo',
        'classe' => 6,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteFoxtrot->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteEcho->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 120.00,
    ]);

    // Famille 60 (creee en dernier, premiere attendue) : un compte budgete et
    // un compte seulement mouvemente -> la famille doit valoir 300.00 pile,
    // ni null (l'agregat existe des qu'un enfant parle), ni la somme des
    // deux (le compte non budgete n'a rien a additionner).
    Famille::factory()->create(['association_id' => $this->association->id, 'code' => '60', 'nom' => 'Alpha']);
    $compteZoulou = Compte::factory()->numero('607')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Zoulou',
        'classe' => 6,
    ]);
    $compteAlpha = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Alpha',
        'classe' => 6,
    ]);
    $txZoulou = Transaction::factory()->create(['association_id' => $this->association->id, 'date' => '2025-11-10']);
    TransactionLigne::factory()->create([
        'transaction_id' => $txZoulou->id,
        'compte_id' => $compteZoulou->id,
        'operation_id' => $operation->id,
        'debit' => 50.00,
        'credit' => 0,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteAlpha->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $charges = $data[(int) $operation->id]['charges'];

    expect($charges)->toHaveCount(3);
    expect(array_column($charges, 'famille_nom'))->toBe([
        '60 — Alpha',
        '61 — Bravo',
        '62 — Charlie',
    ]);

    [$familleA, $familleB, $familleC] = $charges;

    expect(array_column($familleA['comptes'], 'compte_nom'))->toBe(['Alpha', 'Zoulou']);
    expect($familleA['budget'])->toBe(300.00);

    expect(array_column($familleB['comptes'], 'compte_nom'))->toBe(['Echo', 'Foxtrot']);
    expect($familleB['budget'])->toBe(420.00);

    expect($familleC['budget'])->toBeNull();

    // Maille section : deux familles budgetees (300 et 420) et une entierement
    // non couverte -> 720.00, jamais null, jamais la valeur d'une seule
    // famille (ce que "le dernier gagne" produirait selon l'ordre).
    expect($data[(int) $operation->id]['totaux']['charges']['budget'])->toBe(720.00);
});

it('une prevision du tenant courant sur un compte etranger n apparait pas, sans ecarter les previsions legitimes', function (): void {
    // Le docblock de metaComptes() designait a tort la ligne de budget comme
    // le scenario ou son filtre est le seul rempart : ce cas est en realite
    // deja arrete en amont par le c.association_id de ventilations(). Le
    // chemin ou le filtre de metaComptes() (et le garde isset($meta[...]) en
    // aval) sont VRAIMENT le seul rempart est celui des previsions :
    // CompteResultatBuilder::fetchPrevisionsFlatEntries() scope
    // ep.association_id et op.association_id, jamais le compte lui-meme.
    //
    // On pose donc une EncadrementPrevision du tenant courant pointant un
    // compte d'une AUTRE association, a cote d'une prevision legitime sur la
    // meme operation, et on affirme les deux : la ligne etrangere est
    // absente, la ligne legitime est presente. Jamais un toBe([]) sur un
    // fixture vide.
    $autre = Association::factory()->create();
    $compteEtranger = Compte::factory()->numero('622')->create([
        'association_id' => $autre->id,
        'intitule' => 'Compte du voisin',
        'classe' => 6,
    ]);
    $compteLegitime = Compte::factory()->numero('611B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Animation',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $seanceEtrangere = Seance::factory()->create([
        'association_id' => $this->association->id,
        'operation_id' => $operation->id,
        'date' => '2025-10-15',
        'numero' => 1,
    ]);
    EncadrementPrevision::factory()->create([
        'operation_id' => $operation->id,
        'seance_id' => $seanceEtrangere->id,
        'compte_id' => $compteEtranger->id,
        'montant_prevu' => 999.00,
    ]);

    $seanceLegitime = Seance::factory()->create([
        'association_id' => $this->association->id,
        'operation_id' => $operation->id,
        'date' => '2025-10-16',
        'numero' => 2,
    ]);
    EncadrementPrevision::factory()->create([
        'operation_id' => $operation->id,
        'seance_id' => $seanceLegitime->id,
        'compte_id' => $compteLegitime->id,
        'montant_prevu' => 3750.00,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $charges = $data[(int) $operation->id]['charges'];

    expect(ligneCompteDuBudget($charges, (int) $compteEtranger->id))->toBeNull();
    $ligneLegitime = ligneCompteDuBudget($charges, (int) $compteLegitime->id);
    expect($ligneLegitime)->not->toBeNull();
    expect($ligneLegitime['prevision'])->toBe(3750.00);
});

it('un contra-compte mouvemente au sens inverse de sa classe rend un realise negatif', function (): void {
    // Le docblock interdit explicitement l'abs() et l'inversion de signe par
    // classe : tous les fixtures existants posent des debits positifs de
    // classe 6, aucun contra-compte n'etait exerce. Un compte 709 (gratuites
    // accordees, contra-produit) mouvemente au DEBIT doit rendre un realise
    // NEGATIF (SensMontantPd::ligne(7) = SUM(credit) - SUM(debit)), pas sa
    // valeur absolue.
    $compte = Compte::factory()->numero('709')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Gratuites accordees',
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 200.00,
        'credit' => 0,
    ]);

    $data = app(RapportService::class)->budgetParOperations(2025, [(int) $operation->id]);
    $ligne = ligneCompteDuBudget($data[(int) $operation->id]['produits'], (int) $compte->id);

    expect($ligne['realise'])->toBe(-200.00);
    expect($ligne['hors_dotation'])->toBeTrue();
    expect($data[(int) $operation->id]['totaux']['produits']['hors_dotation'])->toBe(-200.00);
});

it('sans contexte tenant boote, parOperations rend un tableau vide', function (): void {
    // Doctrine fail-closed du projet (TenantModel, TenantContext) : jamais
    // testee pour ce builder. On garde un operationIds non vide pour
    // n'exercer QUE le garde `! TenantContext::hasBooted()` — un
    // operationIds vide court-circuiterait deja par la premiere moitie du
    // `||` et ne prouverait rien sur le garde tenant.
    TenantContext::clear();

    $data = app(RapportService::class)->budgetParOperations(2025, [123456]);

    expect($data)->toBe([]);
});
