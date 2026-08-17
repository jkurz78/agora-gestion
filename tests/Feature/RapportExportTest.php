<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('requires authentication for export', function () {
    auth()->logout();
    $this->get('/rapports/export/compte-resultat/xlsx')
        ->assertRedirect(route('login'));
});

it('rejects invalid rapport name', function () {
    $this->get('/rapports/export/invalid-report/xlsx')
        ->assertNotFound();
});

it('rejects invalid format', function () {
    $this->get('/rapports/export/compte-resultat/csv')
        ->assertNotFound();
});

it('rejects pdf format for analyse reports', function () {
    $this->get('/rapports/export/analyse-financier/pdf')
        ->assertNotFound();
});

it('exports compte-resultat as xlsx', function () {
    $this->get('/rapports/export/compte-resultat/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports compte-resultat as pdf', function () {
    $this->get('/rapports/export/compte-resultat/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

// SEL-04 : ces deux exports répondaient 200 avec un document vide. Un classeur
// sans ligne se lit comme un résultat nul, pas comme une sélection invalide —
// d'où le 422. Les deux tests testaient précisément ce silence.
it('refuse l\'export xlsx des opérations quand aucune n\'est éligible', function () {
    $this->get('/rapports/export/operations/xlsx?ops[]=999999&seances=1&tiers=1')
        ->assertStatus(422);
});

it('refuse l\'export pdf des opérations sans sélection', function () {
    $this->get('/rapports/export/operations/pdf')
        ->assertStatus(422);
});

it('exporte les opérations quand la sélection porte un mouvement de résultat', function () {
    session(['exercice_actif' => 2025]);

    $compte = Compte::create([
        'association_id' => $this->association->id, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 120.0,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 120.0,
        'credit' => 0.0,
    ]);

    $this->get('/rapports/export/operations/xlsx?exercice=2025&ops[]='.$operation->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->get('/rapports/export/operations/pdf?exercice=2025&ops[]='.$operation->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    session()->forget('exercice_actif');
});

it('refuse l\'export d\'une opération appartenant à une autre association', function () {
    session(['exercice_actif' => 2025]);

    $autre = Association::factory()->create();
    $opAutre = Operation::factory()->create(['association_id' => $autre->id]);

    $this->get('/rapports/export/operations/xlsx?exercice=2025&ops[]='.$opAutre->id)
        ->assertStatus(422);

    session()->forget('exercice_actif');
});

it('exports flux-tresorerie as xlsx', function () {
    $this->get('/rapports/export/flux-tresorerie/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports flux-tresorerie as pdf', function () {
    $this->get('/rapports/export/flux-tresorerie/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports analyse-financier as xlsx', function () {
    $this->get('/rapports/export/analyse-financier/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports analyse-participants as xlsx', function () {
    $this->get('/rapports/export/analyse-participants/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
