<?php

declare(strict_types=1);

use App\Exceptions\Immobilisation\CompteImmobilisationInvalideException;
use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Audit — les comptes passés à ImmobilisationService::acquerir() ne sont
 * contraints que côté interface (PlanComptableSelecteur, exists:comptes,id).
 * `compte_amortissement_id` est une propriété Livewire publique, hydratée
 * depuis le client malgré son champ HTML en lecture seule : rien n'empêche
 * une requête forgée de la falsifier. Le service est la frontière qui
 * compte, pas le formulaire.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('refuse un compte d’immobilisation de classe 6', function (): void {
    $compteInvalide = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: $compteInvalide,
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte 28X comme compte d’immobilisation', function (): void {
    $compte28x = Compte::ofNumero('28188');

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: $compte28x,
        compteAmortissement: Compte::ofNumero('28154'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte d’amortissement qui ne correspond pas au compte d’actif', function (): void {
    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28154'), // dérivé de 2154, pas de 2188
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte d’immobilisation appartenant à un autre tenant', function (): void {
    $tenantCourant = TenantContext::current();
    $tenantB = Association::factory()->create();

    TenantContext::boot($tenantB);
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
    $compteAutreTenant = Compte::ofNumero('2188');

    TenantContext::boot($tenantCourant);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel volé à un autre tenant',
        quantite: 1,
        compte: $compteAutreTenant,
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte d’amortissement appartenant à un autre tenant', function (): void {
    $tenantCourant = TenantContext::current();
    $tenantB = Association::factory()->create();

    TenantContext::boot($tenantB);
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
    $compteAmortissementAutreTenant = Compte::ofNumero('28188');

    TenantContext::boot($tenantCourant);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: $compteAmortissementAutreTenant,
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte d’immobilisation inactif', function (): void {
    $compteInactif = Compte::factory()->create(['numero_pcg' => '2190', 'classe' => 2, 'actif' => false]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: $compteInactif,
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un compte d’amortissement inactif', function (): void {
    $compteAmortissementInactif = Compte::factory()->create([
        'numero_pcg' => '28190', 'classe' => 2, 'actif' => false,
    ]);
    Compte::factory()->create(['numero_pcg' => '2190', 'classe' => 2, 'actif' => true]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2190'),
        compteAmortissement: $compteAmortissementInactif,
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('accepte un compte d’immobilisation classe 2 avec son compte d’amortissement dérivé, actifs et du bon tenant', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
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

    expect($immo->numero)->toBe('IM00001')
        ->and((int) $immo->compte_id)->toBe((int) Compte::ofNumero('2188')->id)
        ->and((int) $immo->compte_amortissement_id)->toBe((int) Compte::ofNumero('28188')->id);
});

it('rejette la falsification de la propriété Livewire compte_amortissement_id', function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();

    // Le champ compte_amortissement_id est en lecture seule côté HTML,
    // dérivé automatiquement par updatedCompteId() — mais c'est une propriété
    // Livewire publique, hydratée depuis le client à chaque requête : rien ne
    // garantit côté serveur qu'elle est restée la valeur dérivée. On la
    // falsifie ici volontairement vers un compte d'amortissement qui ne
    // correspond pas au compte d'immobilisation choisi (2188 → attend 28188).
    expect(fn () => Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel falsifié')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('compte_amortissement_id', (string) Compte::ofNumero('28154')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '1000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 36)
        ->call('enregistrer')
    )->toThrow(CompteImmobilisationInvalideException::class);

    expect(Immobilisation::count())->toBe(0);
});

it('n’inclut dans le sélecteur immobilisation que les comptes dont le compte d’amortissement dérivé existe et est actif', function (): void {
    // 2154 → 28154 existe et actif (posé par le kit) : inclus.
    // 2183 → 28183 existe et actif (posé par le kit) : inclus.
    // 2199 → aucun compte 28199 dans le plan : exclu.
    Compte::factory()->create(['numero_pcg' => '2199', 'classe' => 2, 'actif' => true]);
    // 2196 → 28196 existe mais inactif : exclu.
    Compte::factory()->create(['numero_pcg' => '2196', 'classe' => 2, 'actif' => true]);
    Compte::factory()->create(['numero_pcg' => '28196', 'classe' => 2, 'actif' => false]);

    $numeros = PlanComptableSelecteur::groupesPourType('immobilisation')
        ->flatMap(fn (array $groupe) => $groupe['comptes']->pluck('numero_pcg'))
        ->all();

    expect($numeros)->toContain('2154')
        ->and($numeros)->toContain('2183')
        ->and($numeros)->not->toContain('2199')
        ->and($numeros)->not->toContain('2196');
});
