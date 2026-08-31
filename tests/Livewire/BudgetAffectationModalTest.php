<?php

use App\Enums\StatutExercice;
use App\Enums\StatutOperation;
use App\Livewire\BudgetAffectationModal;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\User;
use App\Services\Budget\BudgetGelService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->exercice = app(ExerciceService::class)->current();
    $this->compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id, 'intitule' => 'Achats non stockés',
    ]);
    $this->opA = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op A']);
    $this->opB = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op B']);
});

afterEach(fn () => TenantContext::clear());

it('exclut l operation editee du restant a ventiler', function () {
    // Enveloppe 3 000, A ventilée à 1 000, B à 500.
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('lignes');

    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    // 3 000 − 500 (opération B seule), et NON 3 000 − 1 500 (A + B, la bogue
    // que ce test doit détecter si l'exclusion de l'opération éditée saute).
    expect($ligne['restant'])->toBe(2500.0)
        ->and($ligne['enveloppe'])->toBe(3000.0)
        ->and($ligne['montant'])->toBe(1000.0);
});

it('accepte une ventilation sur un compte sans enveloppe et ne signale aucun depassement', function () {
    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '750')
        ->call('enregistrer');

    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $this->opA->id,
        'montant_prevu' => '750.00',
    ]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('lignes');
    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    expect($ligne['enveloppe'])->toBeNull()
        ->and($ligne['restant'])->toBeNull()
        ->and($ligne['depassement'])->toBe(0.0);
});

it('supprime la ventilation quand la cellule est videe, sans toucher a l enveloppe', function () {
    // L'ancienne version de ce test n'avait PAS d'enveloppe : son assertion
    // assertDatabaseMissing(['compte_id' => ..., 'operation_id' => $opA->id])
    // aurait passé à l'identique si enregistrer() avait aussi supprimé
    // l'enveloppe — celle-ci porte operation_id = null et ne matche pas
    // l'assertion. Détruire le budget voté est le pire accident possible ici.
    $enveloppe = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '')
        ->call('enregistrer');

    $this->assertDatabaseMissing('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $this->opA->id,
    ]);

    expect((float) $enveloppe->fresh()->montant_prevu)->toBe(3000.0);
});

it('enregistre plusieurs comptes en une passe', function () {
    $autre = Compte::factory()->numero('611')->create([
        'association_id' => $this->association->id, 'intitule' => 'Sous-traitance',
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '2000')
        ->set("montants.{$autre->id}", '1800')
        ->call('enregistrer');

    // Resserré sur operation_id : compter sans filtrer passerait aussi si les
    // deux lignes atterrissaient sur la mauvaise opération.
    expect(BudgetLine::forExercice($this->exercice)->ventilations()->where('operation_id', $this->opA->id)->count())->toBe(2);
    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->compte->id, 'operation_id' => $this->opA->id, 'montant_prevu' => '2000.00',
    ]);
    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $autre->id, 'operation_id' => $this->opA->id, 'montant_prevu' => '1800.00',
    ]);
});

it('refuse l enregistrement pour un role sans droit d ecriture en compta', function () {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);
    $this->actingAs($gestionnaire);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '999')
        ->call('enregistrer');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

// Le bouton générique de l'écran Budget dispatche operationId=0 (« aucune
// opération choisie » : le sélecteur reste vide, l'utilisateur en pique une
// dans la modale). Ces deux tests ne viennent pas du plan d'origine — ils
// couvrent la normalisation 0 -> null et le rechargement des montants quand
// l'opération est choisie APRÈS coup via le select, deux chemins que les tests
// fournis (qui appellent tous ouvrir() avec un id déjà connu) ne touchent pas.

it('ouvre sans operation preselectionnee quand operationId vaut zero', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 1000.00,
    ]);

    $component = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->assertSet('operationId', null);

    $ligne = collect($component->viewData('lignes'))->firstWhere('compte_id', (int) $this->compte->id);

    // Aucune opération éditée à exclure : le restant déduit TOUTES les
    // ventilations existantes, comme la sous-ligne « Non affecté » de l'écran
    // Budget.
    expect($ligne['restant'])->toBe(2000.0);
});

it('recharge les montants existants quand on change d operation via le select', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->assertSet("montants.{$this->compte->id}", null)
        ->set('operationId', $this->opB->id)
        ->assertSet("montants.{$this->compte->id}", '500.00');
});

// Correctif 1 : le bouton générique ouvre la modale operationId=null avec
// TOUTES les cellules actives. Rien n'empêchait alors de saisir avant de
// choisir l'opération — updatedOperationId() écrase $this->montants sans un
// mot. Les cellules doivent donc être désactivées tant qu'aucune opération
// n'est choisie, pour que la perte de saisie soit inobservable côté navigateur.

it('desactive les cellules de saisie tant qu aucune operation n est choisie', function () {
    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->html();

    expect($html)->toMatch('/wire:model="montants\.'.$this->compte->id.'"[^>]*\bdisabled\b/')
        ->and($html)->toContain("Choisissez d'abord une opération.");
});

it('active les cellules de saisie une fois l operation choisie', function () {
    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->html();

    expect($html)->not->toMatch('/wire:model="montants\.'.$this->compte->id.'"[^>]*\bdisabled\b/')
        ->and($html)->not->toContain("Choisissez d'abord une opération.");
});

// Correctif 2 : enregistrer() appelait ExerciceService::assertOuvert(), qui
// lève ExerciceCloturedException — une RuntimeException nue sans render() ni
// handler, donc une 500. Le chemin est atteignable : les badges du bandeau
// « sans budget affecté » de budget-table.blade.php ouvrent la modale avec
// tous les champs actifs, sans garde de $exerciceCloture.

it('ne leve aucune exception et n ecrit rien sur un exercice cloture', function () {
    Exercice::create(['annee' => $this->exercice, 'statut' => StatutExercice::Cloture]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '999')
        ->call('enregistrer');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

it('desactive le bouton enregistrer de la modale sur un exercice cloture', function () {
    Exercice::create(['annee' => $this->exercice, 'statut' => StatutExercice::Cloture]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->assertSeeHtml('disabled');
});

// Correctif 3 : $this->montants et $this->operationId sont entièrement
// pilotés par le navigateur — aucun n'est confronté au périmètre affiché. Il
// faut déjà canWrite(Espace::Compta), mais un appel forgé peut fabriquer des
// lignes budgétaires sur un compte ou une opération d'une AUTRE association :
// invisibles à l'écran (hors des groupes de PlanComptableSelecteur), donc
// impossibles à supprimer depuis l'interface, et violant l'intégrité tenant.

it('ignore une ventilation forgee sur un compte d une autre association', function () {
    $autreAssociation = Association::factory()->create();
    $compteAutre = Compte::factory()->numero('606')->create([
        'association_id' => $autreAssociation->id,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$compteAutre->id}", '500')
        ->call('enregistrer');

    $this->assertDatabaseMissing('budget_lines', [
        'compte_id' => $compteAutre->id,
    ]);
});

it('n ecrit rien pour une operation d une autre association forgee via set', function () {
    $autreAssociation = Association::factory()->create();
    $operationAutre = Operation::factory()->create(['association_id' => $autreAssociation->id]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->set('operationId', $operationAutre->id)
        ->set("montants.{$this->compte->id}", '500')
        ->call('enregistrer');

    $this->assertDatabaseMissing('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $operationAutre->id,
    ]);
});

it('n ecrit rien pour une operation cloturee forgee via set', function () {
    $operationCloturee = Operation::factory()->create([
        'association_id' => $this->association->id,
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->set('operationId', $operationCloturee->id)
        ->set("montants.{$this->compte->id}", '500')
        ->call('enregistrer');

    $this->assertDatabaseMissing('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $operationCloturee->id,
    ]);
});

it('ignore une cle non numerique dans montants sans lever d exception', function () {
    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set('montants.abc', '500')
        ->call('enregistrer')
        ->assertOk();

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

// Correctif 4 : couvertures manquantes ou non discriminantes relevées en revue.

it('accepte une ventilation via la modale meme budget valide, car le gel ne verrouille que les enveloppes', function () {
    // Exercice n'a NI HasFactory NI ExerciceFactory — création directe, comme
    // dans tests/Feature/BudgetGelTest.php.
    $exerciceModele = Exercice::create(['annee' => $this->exercice, 'statut' => StatutExercice::Ouvert]);
    app(BudgetGelService::class)->valider($exerciceModele, $this->user);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '750')
        ->call('enregistrer');

    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->compte->id, 'operation_id' => $this->opA->id, 'montant_prevu' => '750.00',
    ]);
});

it('calcule un depassement quand le montant saisi excede le restant', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '1500')
        ->viewData('lignes');

    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    expect($ligne['depassement'])->toBe(500.0);
});

it('ne signale aucun depassement pour un compte sans enveloppe quel que soit le montant', function () {
    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '50000')
        ->viewData('lignes');

    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    expect($ligne['enveloppe'])->toBeNull()
        ->and($ligne['depassement'])->toBe(0.0);
});

it('isole les montants d une association des enveloppes et ventilations d une autre sur un compte homonyme', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    // Une seconde association, avec un compte de MÊME numéro PCG (606), porte
    // une enveloppe et une ventilation largement supérieures. Toute la colonne
    // « Restant » est une somme : une régression du scope tenant produirait
    // des montants faux plutôt qu'une erreur explicite.
    $autreAssociation = Association::factory()->create();
    TenantContext::boot($autreAssociation);
    $compteHomonyme = Compte::factory()->numero('606')->create([
        'association_id' => $autreAssociation->id, 'intitule' => 'Achats non stockés (autre asso)',
    ]);
    $operationAutre = Operation::factory()->create(['association_id' => $autreAssociation->id]);
    BudgetLine::factory()->create([
        'association_id' => $autreAssociation->id, 'compte_id' => $compteHomonyme->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 999999.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $autreAssociation->id, 'compte_id' => $compteHomonyme->id,
        'exercice' => $this->exercice, 'operation_id' => $operationAutre->id, 'montant_prevu' => 888888.00,
    ]);

    // Retour au tenant courant pour interroger la modale comme le ferait
    // l'utilisateur de la première association.
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('lignes');

    $lignesCompte606 = collect($lignes)->where('numero', $this->compte->numero_pcg);
    $ligne = $lignesCompte606->firstWhere('compte_id', (int) $this->compte->id);

    // Une seule ligne 606 dans la vue de la première association : le compte
    // homonyme de la seconde n'a pas fuité dans le sélecteur de comptes.
    expect($lignesCompte606)->toHaveCount(1)
        ->and($ligne['enveloppe'])->toBe(3000.0)
        ->and($ligne['restant'])->toBe(2500.0);
});

// Correctif 5 : le docblock de la classe annonçait un recalcul du restant
// « côté client » alors qu'il n'y avait aucun JS dans la vue. Vérification de
// bas niveau (le comportement d'entrée utilisateur n'est pas testable via
// Pest) : le script et les attributs qu'il consomme sont bien rendus, avec la
// bonne valeur de restant par compte. Le comportement interactif a été vérifié
// à la main dans un navigateur (voir rapport final).
it('rend les attributs et le script consommes par le recalcul cote client du depassement', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->html();

    expect($html)->toContain('data-budget-affectation-montant="'.$this->compte->id.'"')
        ->and($html)->toContain('data-restant="500"')
        ->and($html)->toContain('id="budget-affectation-depassement-'.$this->compte->id.'"')
        ->and($html)->toContain("document.addEventListener('input'");
});

// Correctif 6 : render() interrogeait Operation::proposableALaSaisie() même
// modale fermée — une requête gratuite à chaque chargement de l'écran Budget,
// où <livewire:budget-affectation-modal /> est monté fermé par défaut.

it('n interroge pas les operations proposables quand la modale est fermee', function () {
    DB::enableQueryLog();
    $operations = Livewire::test(BudgetAffectationModal::class)->viewData('operations');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($operations)->toHaveCount(0)
        ->and($queries)->not->toContain('from `operations`');
});
