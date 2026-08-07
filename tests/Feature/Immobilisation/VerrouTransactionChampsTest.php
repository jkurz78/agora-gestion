<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * isLockedByImmobilisation() bloque déjà save() côté serveur (voir
 * VerrouTransactionServiceTest / VerrouTransactionTest), mais rien ne le
 * signalait côté champs avant de cliquer sur Enregistrer — l'utilisateur
 * saisissait puis découvrait l'interdiction sous forme d'erreur. Ce fichier
 * vérifie que le formulaire désactive les champs comptables et le bouton
 * d'enregistrement à l'affichage, comme il le fait déjà pour
 * isLockedByFacture, sans jamais bloquer la ventilation.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();
    $this->transactionId = (int) $dotation->transaction_id;

    $this->ligne = TransactionLigne::where('transaction_id', $this->transactionId)
        ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '6811'))
        ->firstOrFail();
});

it('désactive le bouton d’enregistrement d’une transaction pilotée par une immobilisation', function (): void {
    $html = Livewire::test(TransactionForm::class)->call('edit', $this->transactionId)->html();

    preg_match('/<button type="submit"[\s\S]*?>/', $html, $bouton);

    expect($bouton[0] ?? '')->toContain('disabled');
});

it('affiche le compte de la ligne verrouillée en lecture seule, pas dans un sélecteur', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', $this->transactionId)
        ->assertSeeHtml('form-control-plaintext d-flex align-items-center gap-2 py-1');
});

it('affiche le montant de la ligne verrouillée en lecture seule, pas dans un champ modifiable', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', $this->transactionId)
        ->assertDontSeeHtml('wire:model.live="lignes.0.montant"');
});

it('désactive le sélecteur d’opération de la ligne verrouillée', function (): void {
    $html = Livewire::test(TransactionForm::class)->call('edit', $this->transactionId)->html();

    preg_match('/<select wire:model\.live="lignes\.0\.operation_id"[\s\S]*?>/', $html, $select);

    expect($select[0] ?? '')->toContain('disabled');
});

it('masque l’ajout et la suppression de ligne sur une transaction pilotée par une immobilisation', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', $this->transactionId)
        ->assertDontSeeHtml('wire:click="addLigne"')
        ->assertDontSeeHtml('wire:click="removeLigne(0)"');
});

it('laisse le bouton Ventiler accessible sur la ligne verrouillée', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', $this->transactionId)
        ->assertSeeHtml('wire:click="ouvrirVentilation('.$this->ligne->id.')"');
});

it('permet toujours de ventiler malgré les champs désactivés', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', $this->transactionId)
        ->call('ouvrirVentilation', (int) $this->ligne->id)
        ->assertSet('ventilationLigneId', (int) $this->ligne->id);
});
