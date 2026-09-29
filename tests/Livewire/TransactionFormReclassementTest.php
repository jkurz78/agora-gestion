<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

/*
 * Écran « Reclasser » d'une ligne de ventilation : offerte uniquement sur une
 * transaction réglée (seul cas où l'édition directe est refusée) et à qui peut
 * écrire en compta. Les gardes métier vivent dans ReclassementLigneService
 * (testé à part) ; ici on vérifie que l'écran les relaie sans les contourner.
 */

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    // Exercice affiché : 2025 (2025-09-01 → 2026-08-31), celui des pièces ci-dessous.
    session(['exercice_actif' => 2025]);

    $this->compte754 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '754'],
        ['intitule' => 'Dons manuels', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
    $this->compte706B = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706B'],
        ['intitule' => 'Parcours thérapeutiques', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
});

afterEach(function () {
    session()->forget('exercice_actif');
});

/**
 * Recette réglée à l'encaissement (le 411 est lettré) : 754 C 175 / 512X D 175.
 * Retourne la ligne de ventilation 754.
 *
 * EcritureGenerator ne pose ni le tiers sur la pièce ni le `montant` de la ligne
 * (tout est dans debit / credit) : on les complète comme le font le formulaire
 * et la synchronisation HelloAsso, pour que l'écran affiche et enregistre la
 * pièce comme une pièce réelle.
 */
function ligneReglee754PourEcranReclassementTest(object $ctx, string $libelle = 'HelloAsso — don additionnel'): TransactionLigne
{
    $tiers = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $tx = app(EcritureGenerator::class)->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $ctx->compte754, 'montant' => 175.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable('2025-11-15'),
        libelle: $libelle,
    );
    $tx->update(['tiers_id' => $tiers->id]);

    $ligne = $tx->lignes()->where('compte_id', $ctx->compte754->id)->sole();
    $ligne->update(['montant' => 175.00]);

    return $ligne->fresh();
}

it('propose de reclasser une ligne d\'une transaction réglée', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);
    expect($ligne->transaction->aUnReglementTiers())->toBeTrue();

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->assertSet('isLockedByReglement', true)
        ->assertSeeHtml('wire:click="ouvrirReclassement('.$ligne->id.')"');
});

it('ne propose pas de reclassement sur une transaction libre', function () {
    // Créance non réglée : l'édition directe reste possible, pas besoin de « Reclasser ».
    $tx = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id]),
        ventilations: [['compte' => $this->compte754, 'montant' => 175.00, 'sens' => SensVentilation::Credit]],
        dateConstatation: new DateTimeImmutable('2025-11-15'),
        libelle: 'Créance non réglée',
    );
    expect($tx->aUnReglementTiers())->toBeFalse();

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSet('isLockedByReglement', false)
        ->assertDontSeeHtml('ouvrirReclassement');
});

it('ne propose pas de reclassement à qui ne peut pas écrire en compta', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    $lecteur = User::factory()->create();
    $lecteur->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
    $this->actingAs($lecteur);

    $composant = Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->assertSet('isLockedByReglement', true)
        ->assertDontSeeHtml('ouvrirReclassement')
        // L'avertissement n'invite pas à une action qu'on ne lui offre pas.
        ->assertSee('Des règlements sont enregistrés')
        ->assertDontSee('« Reclasser »');

    // Et l'appel forgé à la main ne fait rien non plus.
    $composant->call('ouvrirReclassement', $ligne->id)
        ->assertSet('showReclassementModal', false)
        ->assertSet('reclassementLigneId', null);
});

it('ne propose pas de reclassement quand l\'exercice affiché est clôturé', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);
    Exercice::updateOrCreate(
        ['association_id' => $this->association->id, 'annee' => 2025],
        ['statut' => 'cloture'],
    );

    // L'écran est alors une simple consultation (« Visualiser ») : pas d'action de correction.
    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->assertSet('exerciceCloture', true)
        ->assertSet('isLockedByReglement', true)
        ->assertDontSeeHtml('ouvrirReclassement');
});

it('ouvre la fenêtre sur la ligne, avec son compte, son opération et sa séance actuels', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->assertSet('showReclassementModal', true)
        ->assertSet('reclassementLigneId', $ligne->id)
        ->assertSet('reclassementCompteId', (string) $this->compte754->id)
        ->assertSet('reclassementMotif', '')
        // Rappel en lecture seule, dans la fenêtre (rendue après le tableau des lignes).
        ->assertSeeHtmlInOrder(['id="reclassementModal"', 'Compte actuel', 'Dons manuels', '175,00'])
        // Sélecteur du compte cible : lié à la propriété du formulaire, limité aux
        // comptes de produit pour une recette.
        ->assertSeeHtml('wire:model="$parent.reclassementCompteId"')
        ->assertSeeHtml('&quot;filtre&quot;:&quot;recette&quot;');
});

it('refuse un reclassement sans motif et garde la fenêtre ouverte', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementCompteId', (string) $this->compte706B->id)
        ->set('reclassementMotif', '')
        ->call('reclasser')
        ->assertHasErrors('reclassement')
        ->assertSet('showReclassementModal', true)
        ->assertSee('Indiquez le motif du reclassement');

    $ligne->refresh();
    expect((int) $ligne->compte_id)->toBe((int) $this->compte754->id)
        ->and($ligne->estReclassee())->toBeFalse();
});

it('refuse de reclasser si les droits ont été retirés entre l\'ouverture et la validation', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    $composant = Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementCompteId', (string) $this->compte706B->id)
        ->set('reclassementMotif', 'Erreur de saisie du payeur');

    $lecteur = User::factory()->create();
    $lecteur->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
    $this->actingAs($lecteur);

    $composant->call('reclasser');

    expect($ligne->fresh()->estReclassee())->toBeFalse()
        ->and((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('affiche dans la fenêtre le refus du service, qui reste ouverte', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    // Une recette ne se reclasse que vers un compte de produit (classe 7).
    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementCompteId', (string) $this->compte606->id)
        ->set('reclassementMotif', 'Erreur de saisie du payeur')
        ->call('reclasser')
        ->assertHasErrors('reclassement')
        ->assertSet('showReclassementModal', true)
        ->assertSee('compte actif de classe 7');

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('reclasse la ligne depuis la fenêtre et affiche la marque', function () {
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Parcours automne',
        'nombre_seances' => 3,
        'statut' => StatutOperation::EnCours,
    ]);
    $ligne = ligneReglee754PourEcranReclassementTest($this);
    $ligneId = (int) $ligne->id;
    $motif = 'Règlement de parcours saisi en don par le payeur';

    $composant = Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementCompteId', (string) $this->compte706B->id)
        ->set('reclassementOperationId', (string) $operation->id)
        ->set('reclassementSeance', '2')
        ->set('reclassementMotif', $motif)
        ->call('reclasser')
        ->assertHasNoErrors()
        ->assertSet('showReclassementModal', false)
        // Les lignes affichées sont rechargées : la marque est visible sans rouvrir l'écran.
        ->assertSee('Reclassée le 15/01/2026')
        ->assertSee('depuis Dons manuels (754)')
        ->assertSee($this->user->nom)
        ->assertSeeHtml('title="'.$motif.'"');

    $ligne = TransactionLigne::findOrFail($ligneId);
    expect((int) $ligne->compte_id)->toBe((int) $this->compte706B->id)
        ->and((int) $ligne->operation_id)->toBe((int) $operation->id)
        ->and((int) $ligne->seance)->toBe(2)
        ->and($ligne->estReclassee())->toBeTrue()
        ->and((int) $ligne->reclassement_compte_origine_id)->toBe((int) $this->compte754->id)
        ->and($ligne->reclassement_motif)->toBe($motif)
        ->and((int) $ligne->reclassee_par_user_id)->toBe((int) $this->user->id)
        ->and((float) $ligne->credit)->toBe(175.00);

    // Le montant n'a pas bougé, ni la transaction.
    expect((float) $ligne->transaction->fresh()->montant_total)->toBe(175.00);

    // Un écran rouvert plus tard montre la même marque (chargée par edit()).
    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->assertSee('Reclassée le 15/01/2026')
        ->assertSee('depuis Dons manuels (754)');
});

it('ignore la séance quand aucune opération n\'est choisie', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementCompteId', (string) $this->compte706B->id)
        ->set('reclassementOperationId', '')
        ->set('reclassementSeance', '2')
        ->set('reclassementMotif', 'Erreur de saisie du payeur')
        ->call('reclasser')
        ->assertHasNoErrors();

    expect($ligne->fresh()->seance)->toBeNull()
        ->and($ligne->fresh()->operation_id)->toBeNull();
});

it('repart d\'une séance vide quand on change d\'opération', function () {
    $premiere = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nombre_seances' => 3,
        'statut' => StatutOperation::EnCours,
    ]);
    $seconde = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nombre_seances' => 2,
        'statut' => StatutOperation::EnCours,
    ]);
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementOperationId', (string) $premiere->id)
        ->set('reclassementSeance', '3')
        ->set('reclassementOperationId', (string) $seconde->id)
        ->assertSet('reclassementSeance', '');
});

it('le refus de l\'enregistrement direct renvoie vers l\'action Reclasser', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    $composant = Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->set('lignes.0.compte_id', (string) $this->compte706B->id)
        ->call('save')
        ->assertHasErrors('lignes');

    $message = $composant->errors()->first('lignes');
    expect($message)->toContain('Reclasser')
        ->and($message)->not->toContain('annulez le règlement')
        ->and($message)->not->toContain('reste modifiable');

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('l\'avertissement des transactions réglées renvoie vers l\'action Reclasser', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->assertSee('Des règlements sont enregistrés')
        ->assertSee('« Reclasser »')
        ->assertDontSee('La répartition par opération et séance reste modifiable');
});

it('refuse d\'ouvrir une ligne qui n\'appartient pas à la transaction affichée', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this, 'Don ouvert');
    $autre = ligneReglee754PourEcranReclassementTest($this, 'Autre don');

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $autre->id)
        ->assertSet('showReclassementModal', false)
        ->assertSet('reclassementLigneId', null);
});

it('refuse de reclasser une ligne qui n\'appartient plus à la transaction affichée', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this, 'Don ouvert');
    $autre = ligneReglee754PourEcranReclassementTest($this, 'Autre don');

    // La fenêtre a été ouverte sur la ligne d'une transaction, puis l'écran passe
    // à une autre : l'appartenance est revérifiée à la validation, pas seulement
    // à l'ouverture.
    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->call('edit', $autre->transaction_id)
        ->set('reclassementCompteId', (string) $this->compte706B->id)
        ->set('reclassementMotif', 'Erreur de saisie du payeur')
        ->call('reclasser');

    expect($ligne->fresh()->estReclassee())->toBeFalse()
        ->and((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id)
        ->and($autre->fresh()->estReclassee())->toBeFalse();
});

it('ne laisse pas le client choisir la ligne à reclasser', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this, 'Don ouvert');
    $autre = ligneReglee754PourEcranReclassementTest($this, 'Autre don');

    $composant = Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id);

    expect(fn () => $composant->set('reclassementLigneId', $autre->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('n\'ouvre pas la fenêtre pour la contrepartie 411 de la transaction', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);
    $contrepartie = $ligne->transaction->lignes()
        ->where('id', '!=', $ligne->id)
        ->orderBy('id')
        ->firstOrFail();

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $contrepartie->id)
        ->assertSet('showReclassementModal', false)
        ->assertSet('reclassementLigneId', null);
});

it('sait refermer la fenêtre sans rien changer', function () {
    $ligne = ligneReglee754PourEcranReclassementTest($this);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ligne->transaction_id)
        ->call('ouvrirReclassement', $ligne->id)
        ->set('reclassementMotif', 'Un motif à moitié saisi')
        ->call('fermerReclassement')
        ->assertSet('showReclassementModal', false)
        ->assertSet('reclassementLigneId', null)
        ->assertSet('reclassementMotif', '');

    expect($ligne->fresh()->estReclassee())->toBeFalse();
});
