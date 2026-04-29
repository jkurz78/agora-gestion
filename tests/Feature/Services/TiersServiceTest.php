<?php

// tests/Feature/Services/TiersServiceTest.php
declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TiersService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($user);
});

afterEach(function () {
    TenantContext::clear();
});

it('crée un tiers', function () {
    $tiers = app(TiersService::class)->create([
        'type' => 'entreprise',
        'nom' => 'Mairie de Lyon',
        'prenom' => null,
        'email' => 'contact@mairie.fr',
        'telephone' => null,
        'adresse_ligne1' => null,
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    expect($tiers)->toBeInstanceOf(Tiers::class);
    expect($tiers->nom)->toBe('MAIRIE DE LYON');
    $this->assertDatabaseHas('tiers', ['nom' => 'Mairie de Lyon', 'pour_depenses' => true]);
});

it('met à jour un tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien nom']);

    app(TiersService::class)->update($tiers, ['nom' => 'Nouveau nom']);

    expect($tiers->fresh()->nom)->toBe('NOUVEAU NOM');
});

it('supprime un tiers sans contrainte', function () {
    $tiers = Tiers::factory()->create();

    app(TiersService::class)->delete($tiers);

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});

// ─── Fusion (merge) ─────────────────────────────────────────────────────────

it('fusionne le source dans le cible : transactions, factures, email_logs réaffectés et source supprimé', function () {
    $source = Tiers::factory()->create(['nom' => 'Doublon', 'email' => 'old@x.fr']);
    $target = Tiers::factory()->create(['nom' => 'Survivant', 'email' => null]);

    // Une transaction sur le source
    $tx = Transaction::factory()->asDepense()->create([
        'tiers_id' => $source->id,
        'association_id' => $this->association->id,
    ]);

    // Une facture sur le source
    $facture = Facture::create([
        'tiers_id' => $source->id,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'mentions_legales' => '',
        'conditions_reglement' => '',
        'montant_total' => 0,
        'exercice' => 2025,
        'saisi_par' => User::factory()->create()->id,
        'association_id' => $this->association->id,
    ]);

    // Un log email sur le source
    $log = EmailLog::create([
        'tiers_id' => $source->id,
        'operation_id' => null,
        'categorie' => 'message',
        'destinataire_email' => 'old@x.fr',
        'destinataire_nom' => 'Doublon',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => User::factory()->create()->id,
        'association_id' => $this->association->id,
    ]);

    $report = app(TiersService::class)->merge(
        $source,
        $target,
        ['nom' => 'Survivant', 'email' => 'old@x.fr'],
        ['pour_depenses' => true, 'pour_recettes' => false, 'est_helloasso' => false],
    );

    expect($report['counts']['transactions'])->toBe(1);
    expect($report['counts']['factures'])->toBe(1);
    expect($report['counts']['email_logs'])->toBe(1);

    expect($tx->fresh()->tiers_id)->toBe($target->id);
    expect($facture->fresh()->tiers_id)->toBe($target->id);
    expect($log->fresh()->tiers_id)->toBe($target->id);

    // Email du target hérité du source
    expect($target->fresh()->email)->toBe('old@x.fr');

    // Source supprimé
    $this->assertDatabaseMissing('tiers', ['id' => $source->id]);
});

it('rejette la fusion inter-association', function () {
    $autreAsso = Association::factory()->create();
    $source = Tiers::factory()->create(['association_id' => $autreAsso->id]);
    $target = Tiers::factory()->create(['association_id' => $this->association->id]);

    expect(fn () => app(TiersService::class)->merge($source, $target, [], []))
        ->toThrow(RuntimeException::class, 'inter-association');
});

it('rejette la fusion avec soi-même', function () {
    $tiers = Tiers::factory()->create();

    expect(fn () => app(TiersService::class)->merge($tiers, $tiers, [], []))
        ->toThrow(RuntimeException::class, 'identiques');
});

it('détecte le conflit participants même opération', function () {
    $source = Tiers::factory()->create();
    $target = Tiers::factory()->create();
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Participant::create([
        'tiers_id' => $source->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    Participant::create([
        'tiers_id' => $target->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    $conflicts = app(TiersService::class)->detectMergeConflicts($source, $target);

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['type'])->toBe('participants_dup');

    // La fusion explicite échoue aussi
    expect(fn () => app(TiersService::class)->merge($source, $target, [], []))
        ->toThrow(RuntimeException::class, 'bloquée');
});

it('détecte le conflit HelloAsso order_id partagé', function () {
    $source = Tiers::factory()->create();
    $target = Tiers::factory()->create();

    Transaction::factory()->asRecette()->create([
        'tiers_id' => $source->id,
        'association_id' => $this->association->id,
        'helloasso_order_id' => 99001,
    ]);
    Transaction::factory()->asRecette()->create([
        'tiers_id' => $target->id,
        'association_id' => $this->association->id,
        'helloasso_order_id' => 99001,
    ]);

    $conflicts = app(TiersService::class)->detectMergeConflicts($source, $target);

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['type'])->toBe('helloasso_dup');
});

it('compte les enregistrements dépendants pour le récapitulatif', function () {
    $tiers = Tiers::factory()->create();

    Transaction::factory()->asDepense()->count(3)->create([
        'tiers_id' => $tiers->id,
        'association_id' => $this->association->id,
    ]);

    $counts = app(TiersService::class)->countDependentRecords($tiers);

    expect($counts['transactions'])->toBe(3);
    expect($counts['factures'])->toBe(0);
    expect($counts['email_logs'])->toBe(0);
});

it('hérite l\'identité helloasso du source si la cible est vide', function () {
    $source = Tiers::factory()->create([
        'est_helloasso' => true,
        'helloasso_nom' => 'DOE',
        'helloasso_prenom' => 'John',
    ]);
    $target = Tiers::factory()->create([
        'est_helloasso' => false,
        'helloasso_nom' => null,
        'helloasso_prenom' => null,
    ]);

    app(TiersService::class)->merge(
        $source,
        $target,
        ['nom' => 'Doe'],
        ['est_helloasso' => true],
    );

    $fresh = $target->fresh();
    expect($fresh->helloasso_nom)->toBe('DOE');
    expect($fresh->helloasso_prenom)->toBe('John');
    expect($fresh->est_helloasso)->toBeTrue();
});
