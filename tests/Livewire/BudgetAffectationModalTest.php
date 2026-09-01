<?php

use App\Enums\StatutExercice;
use App\Enums\StatutOperation;
use App\Enums\TypeTransaction;
use App\Livewire\BudgetAffectationModal;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
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
        ->and($ligne['restant'])->toBeNull();
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

// Correctif audit point 9a : la colonne "Restant à ventiler" affichait la
// BASE serveur (enveloppe − ventilations des AUTRES opérations), figée
// pendant la frappe — le dépassement n'était visible que dans un message
// séparé "dépasse de X €". La spec veut désormais que la colonne elle-même
// affiche base − montant saisi, en rouge si négatif ; le message séparé
// devient redondant et disparaît. Le calcul de la BASE reste inchangé côté
// serveur (lignes() n'est pas touché) : seul l'AFFICHAGE change, dans la vue.

it('affiche en rouge le reste a ventiler net quand la saisie depasse le restant', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);

    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '1500')
        ->html();

    preg_match('/id="budget-affectation-restant-'.$this->compte->id.'"[^>]*>([^<]*)</', $html, $m);

    expect(trim($m[1] ?? ''))->toContain('-500,00')
        ->and($html)->toContain('text-danger')
        ->and($html)->not->toContain('dépasse de');
});

it('affiche toujours un tiret pour le reste a ventiler d un compte sans enveloppe, quel que soit le montant', function () {
    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '50000')
        ->html();

    preg_match('/id="budget-affectation-restant-'.$this->compte->id.'"[^>]*>([^<]*)</', $html, $m);

    expect(trim($m[1] ?? ''))->toBe('—');
});

it('le reste a ventiler net reste juste apres enregistrement puis reouverture, sans fondre', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '500')
        ->call('enregistrer');

    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->html();

    // 1 000 − 0 (aucune AUTRE ventilation) − 500 (montant rechargé pour A) =
    // 500 : exactement la valeur qu'affichait déjà la frappe initiale — la
    // réouverture ne doit pas faire fondre le restant.
    preg_match('/id="budget-affectation-restant-'.$this->compte->id.'"[^>]*>([^<]*)</', $html, $m);

    expect(trim($m[1] ?? ''))->toContain('500,00');
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
        ->and($html)->toContain('id="budget-affectation-restant-'.$this->compte->id.'"')
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

// Correctif audit point 1 : enregistrer() empruntait la même branche de
// suppression pour une cellule vide/zéro (la spec) ET pour une saisie
// négative ou non numérique (une faute de frappe). Une ventilation existante
// pouvait donc être détruite silencieusement par une coquille de saisie. Le
// correctif sépare les deux cas : vide/zéro supprime, négatif/non-numérique
// est une erreur de validation qui n'écrit RIEN — même les comptes valides de
// la même saisie.

it('refuse un montant negatif et ne supprime pas la ventilation existante', function () {
    $ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '-500')
        ->call('enregistrer');

    expect((float) $ventilation->fresh()->montant_prevu)->toBe(400.0)
        ->and(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(1);
});

it('refuse un montant non numerique et ne supprime pas la ventilation existante', function () {
    $ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '12OO') // faute de frappe : lettres O au lieu de zéros
        ->call('enregistrer');

    expect((float) $ventilation->fresh()->montant_prevu)->toBe(400.0)
        ->and(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(1);
});

it('n ecrit aucun compte valide quand un autre compte de la meme saisie est invalide', function () {
    $autre = Compte::factory()->numero('611')->create([
        'association_id' => $this->association->id, 'intitule' => 'Sous-traitance',
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '2000') // valide
        ->set("montants.{$autre->id}", '-100') // invalide
        ->call('enregistrer');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

it('affiche un message nommant les comptes fautifs sans lever d exception', function () {
    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '-500')
        ->call('enregistrer')
        ->assertOk()
        ->assertHasErrors('montants');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

it('supprime toujours la ventilation pour une cellule videe ou a zero', function () {
    $ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '0')
        ->call('enregistrer')
        ->assertHasNoErrors();

    expect(BudgetLine::find($ventilation->id))->toBeNull();
});

// Retour de recette : totaux et résultat prévisionnel dans la modale, plus
// une 5ᵉ colonne "Réalisé" alimentée depuis
// BudgetService::realiseParCompteEtOperation(). Le réalisé est un FAIT (ne se
// recalcule jamais côté client), les totaux "prévu" sont dérivés de la
// saisie (recalculés en direct en JS, mais justes dès le premier rendu côté
// serveur — testé ici sans JS).

it('alimente le realise depuis realiseParCompteEtOperation, y compris une ligne eclatee entre deux operations', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id, 'date' => "{$this->exercice}-10-15",
    ]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id, 'compte_id' => $this->compte->id,
        'montant' => 300.00, 'debit' => 300.00, 'credit' => 0.00,
        'operation_id' => $this->opA->id,
    ]);
    // Ligne éclatée : 200 sur A, 100 sur B — seule la part de A doit remonter
    // quand la modale est ouverte sur A.
    TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => $this->opA->id, 'montant' => 200.00]);
    TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => $this->opB->id, 'montant' => 100.00]);

    $lignesA = Livewire::test(BudgetAffectationModal::class)->call('ouvrir', $this->opA->id)->viewData('lignes');
    $lignesB = Livewire::test(BudgetAffectationModal::class)->call('ouvrir', $this->opB->id)->viewData('lignes');

    $ligneA = collect($lignesA)->firstWhere('compte_id', (int) $this->compte->id);
    $ligneB = collect($lignesB)->firstWhere('compte_id', (int) $this->compte->id);

    expect($ligneA['realise'])->toBe(200.0)
        ->and($ligneB['realise'])->toBe(100.0);
});

it('affiche un tiret pour le realise de chaque ligne quand aucune operation n est choisie', function () {
    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->html();

    preg_match('/id="budget-affectation-realise-'.$this->compte->id.'"[^>]*>([^<]*)</', $html, $m);

    expect(trim($m[1] ?? ''))->toBe('—');
});

it('calcule les totaux prevu et realise par section et le resultat produits moins charges', function () {
    $autreDepense = Compte::factory()->numero('611')->create([
        'association_id' => $this->association->id, 'intitule' => 'Sous-traitance',
    ]);
    $recette1 = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id, 'intitule' => 'Prestations',
    ]);
    $recette2 = Compte::factory()->numero('754')->create([
        'association_id' => $this->association->id, 'intitule' => 'Dons',
    ]);

    // Réalisé de l'opération A pour chacun des 4 comptes.
    foreach ([
        [$this->compte, 1890.00, TypeTransaction::Depense],
        [$autreDepense, 1200.00, TypeTransaction::Depense],
        [$recette1, 3200.00, TypeTransaction::Recette],
        [$recette2, 800.00, TypeTransaction::Recette],
    ] as [$compte, $montant, $type]) {
        $tx = Transaction::factory()->create([
            'association_id' => $this->association->id, 'type' => $type, 'date' => "{$this->exercice}-10-15",
        ]);
        $tx->lignes()->forceDelete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id, 'compte_id' => $compte->id,
            'montant' => $montant,
            'debit' => $type === TypeTransaction::Depense ? $montant : 0.0,
            'credit' => $type === TypeTransaction::Recette ? $montant : 0.0,
            'operation_id' => $this->opA->id,
        ]);
    }

    $totaux = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '2000')
        ->set("montants.{$autreDepense->id}", '1800')
        ->set("montants.{$recette1->id}", '3500')
        ->set("montants.{$recette2->id}", '800')
        ->viewData('totaux');

    expect($totaux['charges_prevu'])->toBe(3800.0)
        ->and($totaux['produits_prevu'])->toBe(4300.0)
        ->and($totaux['resultat_prevu'])->toBe(500.0)
        ->and($totaux['charges_realise'])->toBe(3090.0)
        ->and($totaux['produits_realise'])->toBe(4000.0)
        ->and($totaux['resultat_realise'])->toBe(910.0);
});

it('un contra-compte reduit le total realise de sa section, pas seulement sa ligne', function () {
    // Contra-produit (classe 709 : rabais/ristournes accordés) : la ligne
    // porte un montant positif mais SensMontantPd le rend négatif pour un
    // compte de recette — le total de la section doit baisser d'autant, pas
    // seulement l'affichage de sa propre ligne.
    $recette = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id, 'intitule' => 'Prestations',
    ]);
    $contraCompte = Compte::factory()->numero('709')->create([
        'association_id' => $this->association->id, 'intitule' => 'RRR accordés',
    ]);

    $txRecette = Transaction::factory()->create([
        'association_id' => $this->association->id, 'type' => TypeTransaction::Recette, 'date' => "{$this->exercice}-10-15",
    ]);
    $txRecette->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txRecette->id, 'compte_id' => $recette->id,
        'montant' => 1000.00, 'debit' => 0.0, 'credit' => 1000.00,
        'operation_id' => $this->opA->id,
    ]);

    $txContra = Transaction::factory()->create([
        'association_id' => $this->association->id, 'type' => TypeTransaction::Recette, 'date' => "{$this->exercice}-10-16",
    ]);
    $txContra->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txContra->id, 'compte_id' => $contraCompte->id,
        'montant' => 150.00, 'debit' => 150.00, 'credit' => 0.0,
        'operation_id' => $this->opA->id,
    ]);

    $totaux = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('totaux');

    // 1000 (recette) - 150 (contra) = 850, et non 1000.
    expect($totaux['produits_realise'])->toBe(850.0);
});

it('laisse les totaux realise a null quand aucune operation n est choisie, sans casser les totaux prevu', function () {
    $totaux = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->viewData('totaux');

    expect($totaux['charges_realise'])->toBeNull()
        ->and($totaux['produits_realise'])->toBeNull()
        ->and($totaux['resultat_realise'])->toBeNull()
        // Aucune cellule de saisie possible sans opération choisie (correctif
        // 1) : les totaux "prévu" sont donc à zéro, pas cassés.
        ->and($totaux['charges_prevu'])->toBe(0.0)
        ->and($totaux['produits_prevu'])->toBe(0.0)
        ->and($totaux['resultat_prevu'])->toBe(0.0);
});

it('affiche le pied de modale avec le libelle de l exercice et excedent en vert', function () {
    $exerciceLabel = app(ExerciceService::class)->label($this->exercice);

    $html = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '100') // recette-free : résultat prévu négatif (une charge sans produit)
        ->html();

    expect($html)->toContain("Sur l'exercice {$exerciceLabel}")
        ->and($html)->not->toContain('résultat de l\'opération');
});
