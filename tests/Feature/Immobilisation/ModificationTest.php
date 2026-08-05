<?php

declare(strict_types=1);

use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
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

it('modifie le libellé, la quantité, la durée, la mise en service et les notes', function (): void {
    $modifiee = app(ImmobilisationService::class)->modifier(
        immobilisation: $this->immo,
        libelle: '25 tenues d’escrime (complément)',
        quantite: 25,
        dureeMois: 36,
        dateMiseEnService: Carbon::parse('2026-10-01'),
        notes: 'Achat complémentaire',
    );

    expect($modifiee->libelle)->toBe('25 tenues d’escrime (complément)')
        ->and($modifiee->quantite)->toBe(25)
        ->and($modifiee->duree_mois)->toBe(36)
        ->and($modifiee->date_mise_en_service->toDateString())->toBe('2026-10-01')
        ->and($modifiee->notes)->toBe('Achat complémentaire')
        // Le montant et le compte n'ont pas bougé : ils n'engagent pas
        // l'écriture comptable et ne sont pas modifiables.
        ->and($modifiee->montant_acquisition)->toEqual('3000.00')
        ->and((int) $modifiee->compte_id)->toBe((int) Compte::ofNumero('2188')->id);
});

it('accepte des notes vides (null)', function (): void {
    $modifiee = app(ImmobilisationService::class)->modifier(
        immobilisation: $this->immo,
        libelle: $this->immo->libelle,
        quantite: $this->immo->quantite,
        dureeMois: $this->immo->duree_mois,
        dateMiseEnService: $this->immo->date_mise_en_service,
        notes: null,
    );

    expect($modifiee->notes)->toBeNull();
});

it('refuse une mise en service modifiée antérieure à l’exercice de l’acquisition', function (): void {
    expect(fn () => app(ImmobilisationService::class)->modifier(
        immobilisation: $this->immo,
        libelle: $this->immo->libelle,
        quantite: $this->immo->quantite,
        dureeMois: $this->immo->duree_mois,
        dateMiseEnService: Carbon::parse('2025-06-01'), // avant l'exercice 2026 de l'achat
        notes: null,
    ))->toThrow(MiseEnServiceAnterieureException::class);

    expect($this->immo->fresh()->date_mise_en_service->toDateString())->toBe('2026-09-12');
});

it('pré-remplit le formulaire d’édition avec les valeurs actuelles de la fiche', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('ouvrirEdition')
        ->assertSet('showEditModal', true)
        ->assertSet('libelle', '20 tenues d’escrime')
        ->assertSet('quantite', 20)
        ->assertSet('duree_mois', 60)
        ->assertSet('date_mise_en_service', '2026-09-12')
        ->assertSet('notes', '');
});

it('modifie une fiche depuis la fiche', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('ouvrirEdition')
        ->set('libelle', 'Tenues renouvelées')
        ->set('quantite', 22)
        ->set('notes', 'Renouvellement partiel')
        ->call('enregistrerModification')
        ->assertHasNoErrors()
        ->assertSet('showEditModal', false);

    $fresh = $this->immo->fresh();
    expect($fresh->libelle)->toBe('Tenues renouvelées')
        ->and($fresh->quantite)->toBe(22)
        ->and($fresh->notes)->toBe('Renouvellement partiel');
});

it('refuse la modification depuis la fiche si la mise en service précède l’exercice de l’acquisition', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('ouvrirEdition')
        ->set('date_mise_en_service', '2025-06-01')
        ->call('enregistrerModification')
        ->assertHasErrors('date_mise_en_service');

    expect($this->immo->fresh()->date_mise_en_service->toDateString())->toBe('2026-09-12');
});

it('affiche le montant et le compte en lecture seule, avec une mention explicative', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('ouvrirEdition')
        ->assertSee('supprimez la fiche')
        ->assertSee('2188')
        ->assertSee('3 000,00');
});
