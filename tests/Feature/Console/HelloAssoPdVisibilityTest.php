<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Transaction;
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

function makeHelloAssoTx(int $associationId, int $userId, int $orderId, bool $equilibree, string $libelle): Transaction
{
    return Transaction::factory()->create([
        'association_id' => $associationId,
        'type' => 'recette',
        'date' => '2026-06-30',
        'libelle' => $libelle,
        'montant_total' => 50.00,
        'helloasso_order_id' => $orderId,
        'equilibree' => $equilibree,
        'saisi_par' => $userId,
    ]);
}

// ── C : assert-pd-complete signale les HA legacy sans faire échouer --check ──

test('assert-pd-complete signale les HelloAsso non enrichies (non bloquant)', function () {
    makeHelloAssoTx((int) $this->association->id, (int) $this->user->id, 111, false, 'HA legacy');
    makeHelloAssoTx((int) $this->association->id, (int) $this->user->id, 222, true, 'HA enrichie');

    // Avec --check : ne doit PAS échouer (le bypass HelloAsso est intentionnel),
    // mais doit signaler la transaction restée legacy.
    $this->artisan('compta:assert-pd-complete', ['--check' => true])
        ->expectsOutputToContain('1 transaction(s) HelloAsso non enrichie(s)')
        ->assertExitCode(0);
});

// ── B : commande dédiée qui liste les HA non enrichies ──────────────────────

test('compta:helloasso-non-enrichies liste les transactions legacy et ignore les enrichies', function () {
    makeHelloAssoTx((int) $this->association->id, (int) $this->user->id, 111, false, 'HA bloquée loyer');
    makeHelloAssoTx((int) $this->association->id, (int) $this->user->id, 222, true, 'HA enrichie OK');

    $this->artisan('compta:helloasso-non-enrichies')
        ->expectsOutputToContain('HA bloquée loyer')
        ->doesntExpectOutputToContain('HA enrichie OK')
        ->assertExitCode(0);
});

test('compta:helloasso-non-enrichies annonce une base saine quand tout est enrichi', function () {
    makeHelloAssoTx((int) $this->association->id, (int) $this->user->id, 222, true, 'HA enrichie OK');

    $this->artisan('compta:helloasso-non-enrichies')
        ->expectsOutputToContain('Aucune transaction HelloAsso non enrichie')
        ->assertExitCode(0);
});
