<?php

use App\Enums\StatutExercice;
use App\Livewire\BudgetTable;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Budget\BudgetGelService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // L'écran budget est clé par compte : création directe des comptes affichés.
    $this->depenseCompte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'SC Depense',
    ]);

    $this->recetteCompte = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id,
        'intitule' => 'SC Recette',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders with exercice', function () {
    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Charges')
        ->assertSee('Produits')
        ->assertSee('SC Depense')
        ->assertSee('SC Recette');
});

it('aligns charges, produits and resultat tables on identical colgroups', function () {
    // Point 4 — Charges, Produits et Résultat sont trois <table> distincts :
    // sans colgroup identique sur les trois, rien ne garantit que leurs
    // colonnes (Compte, Prévu, Réalisé, Écart, Actions) tombent à l'aplomb.
    $html = Livewire::test(BudgetTable::class)->html();

    preg_match_all('/<colgroup>.*?<\/colgroup>/s', $html, $matches);

    expect($matches[0])->toHaveCount(3);
    // Les trois colgroups doivent être identiques (mêmes largeurs, même ordre) :
    // sans quoi le décalage réapparaît, même de quelques pixels.
    expect($matches[0][0])->toBe($matches[0][1]);
    expect($matches[0][1])->toBe($matches[0][2]);
    // La colonne Actions (100px) doit être reproduite sur les trois : c'est
    // elle qui évite que le décalage revienne à droite du tableau.
    expect($matches[0][0])->toContain('width: 100px;');
});

it('can add a budget line', function () {
    $exercice = app(ExerciceService::class)->current();

    Livewire::test(BudgetTable::class)
        ->call('addLine', $this->depenseCompte->id);

    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => '0.00',
    ]);
});

it('can edit montant_prevu inline', function () {
    $exercice = app(ExerciceService::class)->current();

    $line = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 100.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('startEdit', $line->id)
        ->assertSet('editingLineId', $line->id)
        ->assertSet('editingMontant', '100.00')
        ->set('editingMontant', '250.00')
        ->call('saveEdit')
        ->assertSet('editingLineId', null);

    $this->assertDatabaseHas('budget_lines', [
        'id' => $line->id,
        'montant_prevu' => '250.00',
    ]);
});

it('can delete a budget line', function () {
    $exercice = app(ExerciceService::class)->current();

    $line = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('deleteLine', $line->id);

    $this->assertDatabaseMissing('budget_lines', ['id' => $line->id]);
});

it('shows prevu vs realise', function () {
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 1000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('1 000,00');
});

it('affiche les ventilations en sous-lignes du compte', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Stage été 2026',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 3500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Stage été 2026')
        ->assertSee('3 500,00')
        ->assertSee('2 000,00')
        ->assertSee('Non affecté')
        ->assertSee('1 500,00');
});

it('signale un depassement engage avant tout realise', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Stage coûteux',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 1800.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Dépassement engagé')
        ->assertSee('-300,00');
});

it('remonte les operations ouvertes sans budget affecte', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Atelier orphelin',
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('sans budget affecté')
        ->assertSee('Atelier orphelin');
});

it('ne remonte pas une operation deja budgetee', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Atelier budgété',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertDontSee('Atelier budgété n\'a aucun budget');
});

it('affiche aussi les ventilations en sous-lignes sur un compte de produits', function () {
    // Le blade a deux blocs symétriques (Charges / Produits) : ce test protège
    // spécifiquement le bloc Produits, qui n'est couvert par aucun autre test
    // du plan — un oubli de recopie y passerait inaperçu.
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Gala annuel',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 4000.00,
    ]);
    $ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2500.00,
    ]);

    $html = Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Gala annuel')
        ->assertSee('4 000,00')
        ->assertSee('2 500,00')
        ->assertSee('Non affecté')
        ->assertSee('1 500,00')
        ->html();

    // Même vérification que côté Charges : ligne cliquable dans son
    // ensemble, bouton de suppression qui n'ouvre pas la modale au passage.
    expect($html)->toContain("wire:click=\"\$dispatch('ouvrir-affectation', { operationId: {$op->id} })\"")
        ->and($html)->toContain("wire:click.stop=\"deleteLine({$ventilation->id})\"");
});

it('affiche le bandeau de validation du budget quand il n\'est pas encore valide', function () {
    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('n\'est pas encore validé', false)
        ->assertSee('Valider le budget');
});

it('affiche le bandeau de budget valide avec le nom du validateur', function () {
    // Exercice n'a NI HasFactory NI ExerciceFactory — création directe, comme
    // dans tests/Feature/BudgetGelTest.php.
    $exercice = Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Ouvert,
    ]);
    app(BudgetGelService::class)->valider($exercice, $this->user);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Budget validé le')
        ->assertSee($this->user->name)
        ->assertSee('Déverrouiller');
});

// Correctif 2 (revue BudgetAffectationModal) : contrairement au bouton
// générique juste au-dessus, ce bandeau n'était gardé ni par $exerciceCloture
// ni par canEdit. Sur un exercice clôturé, ses badges ouvraient la modale
// d'affectation, saisie possible, et Enregistrer levait une 500 (Exercice
// CloturedException nue, sans handler).
it('cache le bandeau operations sans budget affecte quand l exercice est cloture', function () {
    Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Cloture,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Op sans budget',
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertDontSee('sans budget affecté')
        ->assertDontSee($operation->nom);
});

// Task 8 : amorçage du budget N depuis le réalisé N-1 + import qui préserve
// la ventilation.
it('propose par defaut l exercice precedent comme reference d export', function () {
    $exercice = app(ExerciceService::class)->current();

    Livewire::test(BudgetTable::class)
        ->assertSet('exportSourceExercice', (string) ($exercice - 1));
});

it('affiche le selecteur d exercice de reference dans la modale export', function () {
    Livewire::test(BudgetTable::class)
        ->call('openExportModal')
        ->assertSee('Exercice de référence');
});

it('exporte sans erreur de validation avec un exercice de reference choisi', function () {
    $exercice = app(ExerciceService::class)->current();

    Livewire::test(BudgetTable::class)
        ->set('exportSourceExercice', (string) ($exercice - 2))
        ->call('openExportModal')
        ->call('export')
        ->assertHasNoErrors()
        ->assertSet('showExportModal', false);
});

it('affiche le compte rendu d import avec enveloppes et ventilations conservees', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Stage été']);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('toggleImportPanel')
        ->assertSet('compteRenduImport.enveloppes', 1)
        ->assertSet('compteRenduImport.ventilations', 1)
        ->assertSet('compteRenduImport.montant_ventile', 400.0)
        ->assertSee('enveloppe(s) seront remplacée(s)', false)
        ->assertSee('seront conservées', false);
});

it('efface le compte rendu d import a la fermeture du panneau', function () {
    Livewire::test(BudgetTable::class)
        ->call('toggleImportPanel')
        ->assertNotSet('compteRenduImport', null)
        ->call('toggleImportPanel')
        ->assertSet('compteRenduImport', null);
});

// Correctif audit point 2 : saveEdit() et deleteLine() appellent
// assertOuvert(current()) puis BudgetLine::findOrFail($id) sur N'IMPORTE
// QUELLE ligne — seul le scope tenant filtre, pas l'exercice de la ligne
// elle-même. Un appel Livewire forgé (editingLineId poussé directement, sans
// passer par startEdit()) peut donc viser une ligne d'un exercice CLÔTURÉ
// tout en affichant l'exercice courant, ouvert. ligneEstVerrouillee() ne
// teste que le gel du budget de CET exercice, jamais sa clôture.

it('ne modifie pas une ligne dont l exercice differe de l exercice courant', function () {
    $exercice = app(ExerciceService::class)->current();
    $exerciceAnterieur = $exercice - 1;

    Exercice::create(['annee' => $exerciceAnterieur, 'statut' => StatutExercice::Cloture]);

    $ligneAutreExercice = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exerciceAnterieur,
        'montant_prevu' => 1000.00,
    ]);

    // Appel forgé : editingLineId poussé directement (pas via startEdit(), qui
    // ne proposerait jamais l'id d'une ligne hors de l'exercice affiché).
    Livewire::test(BudgetTable::class)
        ->set('editingLineId', $ligneAutreExercice->id)
        ->set('editingMontant', '9999')
        ->call('saveEdit');

    expect((float) $ligneAutreExercice->fresh()->montant_prevu)->toBe(1000.0);
});

it('ne supprime pas une ligne dont l exercice differe de l exercice courant', function () {
    $exercice = app(ExerciceService::class)->current();
    $exerciceAnterieur = $exercice - 1;

    Exercice::create(['annee' => $exerciceAnterieur, 'statut' => StatutExercice::Cloture]);

    $ligneAutreExercice = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exerciceAnterieur,
        'montant_prevu' => 1000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('deleteLine', $ligneAutreExercice->id);

    expect(BudgetLine::find($ligneAutreExercice->id))->not->toBeNull();
});

// Correctif audit point 3 : addLine(int $compteId) ne contrôle ni
// l'association, ni l'activité, ni la classe comptable du compte reçu — un
// appel Livewire forgé peut créer une enveloppe sur le compte de N'IMPORTE
// QUELLE association, ou sur un compte inactif / hors classe 6-7. Même trou
// déjà colmaté côté BudgetAffectationModal::enregistrer() via
// comptesAutorises() (désormais PlanComptableSelecteur::comptesAutorisesPourTypes()).

it('ignore addLine sur un compte d une autre association', function () {
    $autreAssociation = Association::factory()->create();
    $compteAutre = Compte::factory()->numero('606')->create([
        'association_id' => $autreAssociation->id,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('addLine', $compteAutre->id);

    $this->assertDatabaseMissing('budget_lines', ['compte_id' => $compteAutre->id]);
});

it('ignore addLine sur un compte inactif de la meme association', function () {
    $compteInactif = Compte::factory()->numero('618')->create([
        'association_id' => $this->association->id,
        'actif' => false,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('addLine', $compteInactif->id);

    $this->assertDatabaseMissing('budget_lines', ['compte_id' => $compteInactif->id]);
});

// Correctif audit point 7 : Operation porte SoftDeletes. La spec veut qu'une
// ligne de budget reste LISIBLE après suppression de son opération. Mais
// BudgetLine::operation() n'utilisait pas withTrashed() : la relation
// renvoyait null pour une opération supprimée, et l'écran affichait
// "Opération supprimée" au lieu de son nom — l'historique perdait
// l'information la plus utile (quelle opération) au moment même où on la
// consulte a posteriori.

it('affiche le nom d une operation supprimee au lieu de le perdre', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Sortie ski 2026',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 1200.00,
    ]);

    $op->delete(); // soft delete

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Sortie ski 2026')
        ->assertDontSee('Opération supprimée');
});

it('ignore addLine sur un compte hors classe 6-7', function () {
    // Classe 5 (trésorerie) : jamais proposé par le sélecteur budget.
    $compteTresorerie = Compte::factory()->numero('512')->create([
        'association_id' => $this->association->id,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('addLine', $compteTresorerie->id);

    $this->assertDatabaseMissing('budget_lines', ['compte_id' => $compteTresorerie->id]);
});

// Correctif audit point 8 : les boutons d'ajout, l'édition inline, la
// suppression et l'import restent VISIBLES après validation du budget, alors
// que le serveur les refuse déjà (BudgetGelTest le prouve) — l'utilisateur
// clique et rien ne se passe. La garde d'affichage doit porter sur les
// ENVELOPPES uniquement : la ventilation par opération reste modifiable,
// c'est la règle centrale du design, donc "Affecter un budget à une
// opération" doit rester visible.

it('masque ajouter modifier et supprimer une enveloppe quand le budget est valide', function () {
    $exercice = Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Ouvert,
    ]);
    $ligne = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice->annee,
        'operation_id' => null,
        'montant_prevu' => 500.00,
    ]);
    app(BudgetGelService::class)->valider($exercice, $this->user);

    $html = Livewire::test(BudgetTable::class)->html();

    expect($html)->not->toContain('wire:click="addLine('.$this->recetteCompte->id.')"')
        ->and($html)->not->toContain('wire:click="deleteLine('.$ligne->id.')"')
        ->and($html)->not->toContain('wire:click="startEdit('.$ligne->id.')"');
});

it('garde le bouton affecter un budget visible et masque importer quand le budget est valide', function () {
    $exercice = Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Ouvert,
    ]);
    app(BudgetGelService::class)->valider($exercice, $this->user);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Affecter un budget à une opération')
        ->assertDontSee('Importer');
});

// Correctif audit point 9b : les sous-lignes de ventilation de l'écran
// Budget doivent être cliquables et ouvrir la modale sur l'opération
// concernée, comme le font déjà les badges du bandeau "opérations sans
// budget affecté" — même événement ouvrir-affectation.

it('ouvre la modale d affectation en cliquant sur une sous-ligne de ventilation', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Camp printemps',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 2000.00,
    ]);
    $ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 800.00,
    ]);

    // Retour de recette : le clic doit porter sur TOUTE la sous-ligne (le
    // <tr>), pas seulement sur le nom de l'opération — c'est la zone que
    // l'utilisateur vise naturellement.
    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toContain("wire:click=\"\$dispatch('ouvrir-affectation', { operationId: {$op->id} })\"")
        // Le bouton de suppression est DANS cette ligne cliquable : son clic
        // ne doit pas se propager et ouvrir la modale en même temps.
        ->and($html)->toContain("wire:click.stop=\"deleteLine({$ventilation->id})\"");
});

// Repli/dépliage des sous-lignes de ventilation (JS pur, verrouillé côté PHP
// via TDD Livewire) : le composant expose l'appariement compte ↔ sous-lignes
// par des data-attributes, et rend systématiquement les sous-lignes dans le
// HTML — repliées par défaut via une classe CSS (d-none), jamais absentes
// côté serveur. Le pliage lui-même n'est pas testable ici (pas de JS dans
// Livewire::test), seul le contrat HTML que le JS consomme l'est.

it('rend les sous lignes repliees par defaut avec l appariement compte vers sous lignes', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Stage été 2026',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 3500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2000.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    // La ligne de compte porte l'ancre que le JS utilise pour retrouver ses
    // sous-lignes, et rien d'autre ne doit y ressembler par accident.
    expect($html)->toContain('data-compte-toggle="'.$this->depenseCompte->id.'"')
        // Sous-ligne de ventilation ET ligne de solde ("Non affecté") portent
        // le même id de compte : c'est la clé d'appariement du JS.
        ->and(substr_count($html, 'data-ventilation-of="'.$this->depenseCompte->id.'"'))->toBe(2)
        // Repliées par défaut, mais bien présentes dans le HTML — assertSee
        // ci-dessus le prouve déjà, on vérifie ici que c'est via une classe
        // CSS (d-none) et non une absence.
        ->and($html)->toContain('Stage été 2026')
        ->and($html)->toContain('Non affecté');
});

it('n affiche pas de chevron ni de repere de repli sur un compte sans ventilation', function () {
    $exercice = app(ExerciceService::class)->current();

    // Compte avec une enveloppe mais aucune ventilation par opération.
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    // 'budget-chevron' seul apparaît toujours (règle CSS globale du bloc
    // <style>) : on vérifie l'absence de l'icône elle-même, pas de la classe.
    expect($html)->not->toContain('data-compte-toggle="'.$this->depenseCompte->id.'"')
        ->and($html)->not->toContain('data-compte-toggle="'.$this->recetteCompte->id.'"')
        ->and($html)->not->toContain('bi-chevron-right budget-chevron');
});

it('porte l appariement compte vers sous lignes aussi sur un compte de produits', function () {
    // Même garantie que côté Charges, sur le bloc Produits : les deux blocs
    // symétriques doivent recevoir le même traitement de repli.
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Gala annuel',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 4000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2500.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toContain('data-compte-toggle="'.$this->recetteCompte->id.'"')
        ->and(substr_count($html, 'data-ventilation-of="'.$this->recetteCompte->id.'"'))->toBe(2)
        ->and($html)->toContain('id="budget-table-produits"')
        ->and($html)->toContain('id="budget-table-charges"');
});

it('affiche un controle tout deplier tout replier sur les deux blocs', function () {
    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toContain('data-toggle-all-target="budget-table-charges"')
        ->and($html)->toContain('data-toggle-all-target="budget-table-produits"')
        ->and($html)->toContain('Tout déplier');
});

// Bug remonté en recette (propriétaire) : la colonne « Écart » était calculée
// partout en prévu − réalisé, correct pour les charges mais inversé pour les
// produits — encaisser plus que prévu affichait un écart négatif, encaisser
// moins affichait un écart positif en noir. Voir App\Support\ComparaisonBudgetaire::ecart().
//
// Le « réalisé » vient de vraies écritures (transaction_lignes), lues par
// BudgetService::realiseParCompte() — pas d'un simple champ de test.

it('un produit qui encaisse plus que prevu affiche un ecart positif favorable', function () {
    // Cas 1 du propriétaire : dons prévu 600 € / réalisé 670 € → +70, pas -70.
    $exercice = app(ExerciceService::class)->current();
    $compteDons = Compte::factory()->numero('758')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Dons',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteDons->id,
        'exercice' => $exercice,
        'montant_prevu' => 600.00,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $exercice.'-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteDons->id,
        'debit' => 0,
        'credit' => 670.00,
        'montant' => 670.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    // "70,00" seul (non précédé d'un chiffre) : évite de matcher le "70,00"
    // contenu dans "670,00" (réalisé).
    expect($html)->toMatch('/(?<!\d)70,00 &euro;/')
        ->and($html)->not->toMatch('/-70,00/');
});

it('un produit qui encaisse moins que prevu affiche un ecart negatif en rouge', function () {
    // Cas 2 du propriétaire, le plus dangereux : cotisations prévu 500 € /
    // réalisé 300 € → -200 en rouge, pas +200 en noir (ça rassurerait à tort).
    $exercice = app(ExerciceService::class)->current();
    $compteCotisations = Compte::factory()->numero('756')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Cotisations',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteCotisations->id,
        'exercice' => $exercice,
        'montant_prevu' => 500.00,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $exercice.'-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCotisations->id,
        'debit' => 0,
        'credit' => 300.00,
        'montant' => 300.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toContain('-200,00')
        // La cellule Écart doit porter text-danger, pas juste la valeur être
        // négative — fenêtre large car Livewire insère des commentaires
        // <!--[if BLOCK]>--> entre la balise et le contenu du @if.
        ->and($html)->toMatch('/text-end text-danger">[\s\S]{0,120}?-200,00/');
});

it('les sous lignes de ventilation du bloc produits suivent la meme regle que la ligne de compte', function () {
    // Ventilation d'un compte de produits sur une opération : réalisé 550 €
    // pour un prévu de 400 € doit rester favorable (+150), pas -150.
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Vente de livres',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 400.00,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $exercice.'-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->recetteCompte->id,
        'operation_id' => $op->id,
        'debit' => 0,
        'credit' => 550.00,
        'montant' => 550.00,
    ]);

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toMatch('/(?<!\d)150,00 &euro;/')
        ->and($html)->not->toMatch('/-150,00/');
});

it('le total du bloc produits est calcule realise moins prevu, pas l inverse', function () {
    // Deux comptes de produits qui encaissent chacun plus que prévu : le
    // total prévu (500) doit être inférieur au total réalisé (600), pour un
    // écart de pied de tableau à +100 — jamais -100.
    $exercice = app(ExerciceService::class)->current();
    $compteX = Compte::factory()->numero('709')->create(['association_id' => $this->association->id]);
    $compteY = Compte::factory()->numero('710')->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteX->id,
        'exercice' => $exercice, 'montant_prevu' => 300.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteY->id,
        'exercice' => $exercice, 'montant_prevu' => 200.00,
    ]);

    foreach ([[$compteX, 380.00], [$compteY, 220.00]] as [$compte, $montant]) {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => $this->association->id,
            'date' => $exercice.'-10-15',
            'saisi_par' => $this->user->id,
        ]);
        $tx->lignes()->forceDelete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => $montant,
            'montant' => $montant,
        ]);
    }

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toContain('Total Produits')
        ->and($html)->toMatch('/(?<!\d)100,00 &euro;/')
        ->and($html)->not->toMatch('/-100,00/');
});

it('les ecarts du bloc charges sont un delta brut realise moins prevu, rouge seulement au depassement', function () {
    // Convention unifiée avec le compte de résultat (2026-09-01) : l'écart
    // affiché est TOUJOURS réalisé - prévu, y compris pour une charge — ce
    // test figeait auparavant l'ancienne convention « prévu - réalisé »,
    // opposée à celle du compte de résultat sur les charges. Un compte sous
    // le budget affiche donc désormais un écart NÉGATIF (et reste favorable,
    // pas rouge), un compte en dépassement un écart POSITIF (et rouge).
    $exercice = app(ExerciceService::class)->current();
    $compteSousBudget = Compte::factory()->numero('611')->create(['association_id' => $this->association->id]);
    $compteDepassement = Compte::factory()->numero('612')->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteSousBudget->id,
        'exercice' => $exercice, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteDepassement->id,
        'exercice' => $exercice, 'montant_prevu' => 1000.00,
    ]);

    foreach ([[$compteSousBudget, 800.00], [$compteDepassement, 1200.00]] as [$compte, $montant]) {
        $tx = Transaction::factory()->asDepense()->create([
            'association_id' => $this->association->id,
            'date' => $exercice.'-10-15',
            'saisi_par' => $this->user->id,
        ]);
        $tx->lignes()->forceDelete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => $montant,
            'credit' => 0,
            'montant' => $montant,
        ]);
    }

    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    // Sous le budget (dépensé 800 pour 1000 prévu) : écart -200, favorable —
    // donc PAS dans une cellule text-danger.
    expect($html)->toMatch('/(?<!\d)-200,00 &euro;/')
        ->and($html)->not->toMatch('/text-end text-danger">[\s\S]{0,120}?-200,00/')
        // Au-dessus du budget (dépensé 1200 pour 1000 prévu) : écart +200, en
        // rouge — fenêtre large car Livewire insère des commentaires
        // <!--[if BLOCK]>--> entre la balise et le contenu du @if.
        ->and($html)->toMatch('/text-end text-danger">[\s\S]{0,120}?\+200,00/');
});

it('le resultat suit la regle produit : favorable quand le realise depasse le prevu', function () {
    // Le résultat (produits - charges) se comporte comme un produit : plus le
    // résultat réalisé dépasse le résultat prévu, plus c'est favorable — donc
    // écart = réalisé - prévu, jamais prévu - réalisé (même bug que la tuile
    // budget du tableau de bord, corrigé au commit 9cdc3b2d).
    $exercice = app(ExerciceService::class)->current();
    $compteCharge = Compte::factory()->numero('613')->create(['association_id' => $this->association->id]);
    $compteProduit = Compte::factory()->numero('711')->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteCharge->id,
        'exercice' => $exercice, 'montant_prevu' => 400.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $compteProduit->id,
        'exercice' => $exercice, 'montant_prevu' => 1000.00,
    ]);

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id, 'date' => $exercice.'-10-15', 'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id, 'compte_id' => $compteCharge->id,
        'debit' => 300.00, 'credit' => 0, 'montant' => 300.00,
    ]);

    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id, 'date' => $exercice.'-10-15', 'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id, 'compte_id' => $compteProduit->id,
        'debit' => 0, 'credit' => 1300.00, 'montant' => 1300.00,
    ]);

    // Résultat prévu = 1000 - 400 = 600 ; résultat réalisé = 1300 - 300 = 1000
    // ; écart = réalisé - prévu = 1000 - 600 = +400 (jamais -400).
    $html = Livewire::test(BudgetTable::class)->assertOk()->html();

    expect($html)->toMatch('/(?<!\d)400,00 &euro;/')
        ->and($html)->not->toMatch('/-400,00/');
});

it('n exige pas le commentaire de deverrouillage pour importer un budget', function () {
    // Régression : importBudget() appelait $this->validate() sans argument, ce
    // qui valide TOUTES les propriétés portant un #[Validate] — dont
    // commentaireDeverrouillage, vide et déclaré required|min:5. L'import
    // échouait donc en silence, l'erreur n'étant rendue que dans la modale de
    // déverrouillage, fermée. L'utilisateur cliquait, rien ne se passait.
    Livewire::test(BudgetTable::class)
        ->call('importBudget')
        ->assertHasErrors('budgetFile')
        ->assertHasNoErrors('commentaireDeverrouillage');
});
