<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
});

afterEach(function () {
    TenantContext::clear();
});

test('SystemeSeeder seeds provision accounts 486, 487, 681, 781', function () {
    $assocId = TenantContext::currentId();

    foreach (['486', '487', '681', '781'] as $pcg) {
        $compte = Compte::where('association_id', $assocId)
            ->where('numero_pcg', $pcg)
            ->first();

        expect($compte)->not->toBeNull("Compte {$pcg} not found");
        expect((bool) $compte->est_systeme)->toBeTrue();
        expect((bool) $compte->actif)->toBeTrue();
        expect((bool) $compte->lettrable)->toBeFalse();
    }

    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '486')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '487')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '681')->first()->classe)->toBe(6);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '781')->first()->classe)->toBe(7);
});

test('pourProvisionDotation — dépense generates 681 D / 486 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('normale');
    expect((float) $tx->montant_total)->toBe(1500.00);
    expect((bool) $tx->equilibree)->toBeTrue();
    expect($tx->lignes)->toHaveCount(2);

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(1500.00);
    expect((float) $ligne681->credit)->toBe(0.0);

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(0.0);
    expect((float) $ligne486->credit)->toBe(1500.00);
});

test('pourProvisionDotation — recette generates 487 D / 781 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);

    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->debit)->toBe(800.00);
    expect((float) $ligne487->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(800.00);
});

test('pourProvisionExtourne — dépense generates 486 D / 781 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');
    expect((float) $tx->montant_total)->toBe(1500.00);

    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(1500.00);
    expect((float) $ligne486->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(1500.00);
});

test('pourProvisionExtourne — recette generates 681 D / 487 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(800.00);

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->credit)->toBe(800.00);
});
