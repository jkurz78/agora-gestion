<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

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
});

it('affiche l’aperçu et génère les dotations', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('IM00001')
        ->assertSee('600,00')
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('autorise la génération avant la fin de l’exercice, dès qu’il a commencé', function (): void {
    Carbon::setTestNow('2027-03-01');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::count())->toBe(1);

    Carbon::setTestNow();
});

it('bloque la génération sur un exercice dont la date de début n’est pas encore arrivée', function (): void {
    Carbon::setTestNow('2027-10-15');

    $component = Livewire::test(DotationsExercice::class)
        ->set('exercice', 2028)
        ->call('genererTout');

    expect(ImmobilisationDotation::count())->toBe(0)
        ->and($component->get('flashType'))->toBe('warning')
        ->and($component->get('flashMessage'))->toContain("n'a pas encore commencé");

    Carbon::setTestNow();
});

it('signale un écart et le recalcule', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $this->immo->update(['duree_mois' => 30]);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('Écart')
        ->call('recalculer', (int) $this->immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
});

it('annule une dotation comptabilisée et supprime sa transaction', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    $transactionId = (int) $dotation->transaction_id;

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('annulerDotation', (int) $this->immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0)
        ->and(Transaction::withTrashed()->findOrFail($transactionId)->trashed())->toBeTrue();

    Carbon::setTestNow();
});

it('permet de régénérer une dotation après l’avoir annulée', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('annulerDotation', (int) $this->immo->id)
        ->assertSee('À générer');

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('refuse d’annuler la dotation d’un exercice clôturé', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    $component = Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('annulerDotation', (int) $this->immo->id);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1)
        ->and($component->get('flashType'))->toBe('warning')
        ->and($component->get('flashMessage'))->toContain('clôturé');

    Carbon::setTestNow();
});
