<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    SystemeSeeder::seed();
});

afterEach(function () {
    TenantContext::clear();
});

test('PD mode — la dotation de provision ne compte qu\'une fois dans le résultat net', function () {
    Config::set('compta.use_partie_double', true);

    // Provision dépense 1000 € pour l'exercice 2025 : dotation 681 D / 486 C.
    // NOTE : on date la dotation au 30/06/2026 (et non au dernier jour 31/08) pour
    // contourner un artefact SQLite — la colonne `date` est castée datetime, et
    // « 2026-08-31 00:00:00 » trie APRÈS la borne haute « 2026-08-31 » dans un
    // whereBetween sous SQLite (en prod MySQL, colonne DATE → pas de souci). Une
    // date strictement intérieure à la fenêtre garantit que le 681 atteint les charges
    // dans les deux SGBD, ce qui est exactement la condition du double-comptage en prod.
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP loyer',
        'date' => '2026-06-30',
        'saisi_par' => $this->user->id,
    ]);

    app(ProvisionPDService::class)->generer($provision);

    $component = Livewire::test(RapportCompteResultat::class);

    // Le 681 (dotation) est présent UNE fois dans les charges via le grand livre.
    expect((float) $component->viewData('totalChargesN'))->toBe(1000.00);
    expect((float) $component->viewData('resultatCourant'))->toBe(-1000.00);

    // Les totaux legacy ne doivent PAS être ré-ajoutés (sinon double-comptage prod).
    expect((float) $component->viewData('totalProvisions'))->toBe(0.00);
    expect((float) $component->viewData('totalExtournes'))->toBe(0.00);

    // Pas de waterfall provisions : le résultat net = résultat courant (provision comptée une seule fois).
    expect((float) $component->viewData('resultatNet'))->toBe(-1000.00);

    // Le bloc « provisions » legacy est masqué (les écritures vivent dans charges/produits).
    expect($component->viewData('provisions'))->toBeEmpty();
    expect($component->viewData('extournes'))->toBeEmpty();
});

test('mode legacy — les provisions alimentent le résultat net via la table provisions (comportement inchangé)', function () {
    Config::set('compta.use_partie_double', false);

    Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP loyer',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $component = Livewire::test(RapportCompteResultat::class);

    // En legacy, pas d'écriture PD → résultat courant nul, la provision passe par le total dédié.
    expect((float) $component->viewData('resultatCourant'))->toBe(0.00);
    expect((float) $component->viewData('totalProvisions'))->toBe(1000.00);
    expect((float) $component->viewData('resultatNet'))->toBe(1000.00);
    expect($component->viewData('provisions'))->not->toBeEmpty();
});
