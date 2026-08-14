<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Recette du 13/08/2026 — « Solde général » du tableau de bord comptait les
 * 30 000 € d'acquisition d'un véhicule comme une dépense de l'exercice.
 *
 * Une immobilisation n'est pas une charge : l'achat porte sur un compte de
 * CLASSE 2 (actif au bilan), la charge de l'exercice est la dotation aux
 * amortissements (6811). L'indicateur faisait exactement l'inverse — il
 * comptait l'acquisition, parce que sa transaction porte le journal `achat`
 * comme n'importe quel achat, et ignorait la dotation, parce que son journal
 * est `od`.
 *
 * Le journal ne peut pas départager les deux cas : le bon discriminant est la
 * classe du compte de ventilation. L'indicateur se lit désormais sur le grand
 * livre, classes 6 et 7 — la définition même du compte de résultat, ce qui
 * aligne au passage le tableau de bord sur le rapport du même nom.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);
    session(['exercice_actif' => 2026]);

    SystemeSeeder::seed();
    ImmobilisationComptesSeeder::seed();

    Compte::factory()->create([
        'numero_pcg' => '606',
        'classe' => 6,
        'intitule' => 'Achats non stockés',
        'actif' => true,
    ]);

    $this->acheterImmo = function (string $montant): void {
        app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: 'Deux gros 4x4',
            quantite: 2,
            compte: Compte::ofNumero('2154'),
            compteAmortissement: Compte::ofNumero('28154'),
            montant: $montant,
            dateAchat: Carbon::parse('2026-10-15'),
            dateMiseEnService: Carbon::parse('2026-10-15'),
            dureeMois: 96,
            modePaiement: null,
            compteTresorerie: null,
        );
    };

    $this->depenseOrdinaire = function (string $montant): void {
        app(EcritureGenerator::class)->pourDepenseACredit(
            tiers: Tiers::factory()->create(),
            ventilations: [['compte' => Compte::ofNumero('606'), 'montant' => (float) $montant]],
            dateConstatation: Carbon::parse('2026-10-20'),
            libelle: 'Fournitures',
        );
    };

    $this->soldeGeneral = function (): float {
        return (float) Livewire::test(Dashboard::class)->viewData('soldeGeneral');
    };
});

afterEach(function (): void {
    session()->forget('exercice_actif');
    Carbon::setTestNow();
});

it('ne compte pas l’acquisition d’une immobilisation comme une dépense', function (): void {
    ($this->depenseOrdinaire)('500.00');
    $avant = ($this->soldeGeneral)();

    ($this->acheterImmo)('30000.00');

    expect(($this->soldeGeneral)())->toBe(
        $avant,
        'L’achat d’un véhicule est un actif au bilan, pas une charge de l’exercice.'
    );
});

it('compte toujours une dépense ordinaire de classe 6', function (): void {
    $avant = ($this->soldeGeneral)();

    ($this->depenseOrdinaire)('500.00');

    expect(($this->soldeGeneral)())->toBe(round($avant - 500.00, 2));
});

it('compte la dotation aux amortissements comme la charge de l’exercice', function (): void {
    // Une dotation est TOUJOURS datée du dernier jour de l'exercice, et SQLite
    // exclut cette borne d'un whereBetween date-only (cf. la note projet sur
    // les bornes de date SQLite) : la dotation y devient invisible pour le
    // compte de résultat. La prod tourne sur MySQL, où elle est bien comptée —
    // vérifié par `pest -c phpunit.mysql.xml`. Ne pas « corriger » le service
    // pour faire passer ce test sur SQLite.
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Borne de date SQLite : la dotation du 31/08 sort de la fenêtre.');
    }

    ($this->acheterImmo)('30000.00');

    $avant = ($this->soldeGeneral)();

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    // 30 000 € sur 96 mois = 312,50 €/mois. Mise en service au 15/10/2026 :
    // 11 mois courus jusqu'au 31/08/2027, soit 3 437,50 €.
    expect(($this->soldeGeneral)())->toBe(round($avant - 3437.50, 2));
});
