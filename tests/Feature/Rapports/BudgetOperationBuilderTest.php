<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
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
