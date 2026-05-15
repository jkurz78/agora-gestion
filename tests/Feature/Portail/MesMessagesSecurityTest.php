<?php

declare(strict_types=1);

use App\Livewire\Portail\MesMessages;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Pas de fuite intra-asso
// ─────────────────────────────────────────────────────────────────────────────
it('[sécurité] Alice ne voit pas les emails de Bob dans la même asso', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id]);
    $bob = Tiers::factory()->create(['association_id' => $asso->id]);

    for ($i = 1; $i <= 5; $i++) {
        EmailLog::factory()->create([
            'tiers_id' => $bob->id,
            'objet' => "BobObjet{$i}",
        ]);
    }

    Auth::guard('tiers-portail')->login($alice);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    for ($i = 1; $i <= 5; $i++) {
        expect($html)->not->toContain("BobObjet{$i}");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Cross-tenant
// ─────────────────────────────────────────────────────────────────────────────
it('[sécurité] Alice asso A ne voit pas les emails de l\'asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);

    // Créer un Tiers dans assoB et un EmailLog pour lui
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    EmailLog::factory()->create([
        'tiers_id' => $tiersB->id,
        'objet' => 'SecretAssoB',
    ]);

    // Alice se connecte sur assoA
    TenantContext::boot($assoA);
    Auth::guard('tiers-portail')->login($alice);

    $html = Livewire::test(MesMessages::class, ['association' => $assoA])
        ->assertStatus(200)
        ->html();

    expect($html)->not->toContain('SecretAssoB');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Intrusion toggleMessage ID hors page
// ─────────────────────────────────────────────────────────────────────────────
it('[sécurité] toggleMessage ignore silencieusement un ID hors page courante', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id]);
    $bob = Tiers::factory()->create(['association_id' => $asso->id]);

    // Bob a un email dont Alice n'est pas destinataire
    $bobLog = EmailLog::factory()->create([
        'tiers_id' => $bob->id,
        'objet' => 'EmailBobIntrusion',
        'corps_html' => '<p>Contenu confidentiel Bob</p>',
    ]);

    Auth::guard('tiers-portail')->login($alice);

    $component = Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $bobLog->id);

    // messageOuvertId reste null — intrusion ignorée silencieusement
    $component->assertSet('messageOuvertId', null);

    // Le contenu confidentiel de Bob ne doit pas apparaître
    expect($component->html())->not->toContain('Contenu confidentiel Bob');
});
