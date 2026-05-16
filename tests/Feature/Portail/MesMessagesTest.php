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
// Test 1 : Affichage liste — chronologique inverse
// ─────────────────────────────────────────────────────────────────────────────
it('affiche les messages en ordre chronologique inverse', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Message A ancien',
        'created_at' => now()->subDays(10),
    ]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Message B milieu',
        'created_at' => now()->subDays(5),
    ]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Message C récent',
        'created_at' => now()->subDay(),
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    $posA = strpos($html, 'Message A ancien');
    $posB = strpos($html, 'Message B milieu');
    $posC = strpos($html, 'Message C récent');

    // Ordre desc : C avant B avant A
    expect($posC)->toBeLessThan($posB);
    expect($posB)->toBeLessThan($posA);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Pagination 25
// ─────────────────────────────────────────────────────────────────────────────
it('pagine les messages par 25 et affiche les liens de pagination', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    // 30 emails → page 1 = 25, page 2 = 5
    $objets = [];
    for ($i = 1; $i <= 30; $i++) {
        $log = EmailLog::factory()->create([
            'tiers_id' => $tiers->id,
            'objet' => "Objet-{$i}",
            'created_at' => now()->subMinutes($i),
        ]);
        $objets[] = "Objet-{$i}";
    }

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    // Compte les occurrences de "Objet-" dans la page 1 → attendu 25
    $count = substr_count($html, 'Objet-');
    expect($count)->toBe(25);

    // Liens de pagination présents (pagination Bootstrap Livewire, aria-label traduit en fr)
    expect($html)->toContain('nextPage');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Click ligne — expand inline
// ─────────────────────────────────────────────────────────────────────────────
it('toggleMessage ouvre le corps HTML du message cliqué', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Message test expand',
        'corps_html' => '<p>Contenu secret expand</p>',
    ]);

    Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log->id)
        ->assertSet('messageOuvertId', (int) $log->id)
        ->assertSee('Contenu secret expand');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Click 2nd ligne — ferme le 1er, ouvre le 2nd
// ─────────────────────────────────────────────────────────────────────────────
it('toggleMessage sur un autre ID ferme le premier et ouvre le second', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log1 = EmailLog::factory()->create(['tiers_id' => $tiers->id, 'objet' => 'Premier']);
    $log2 = EmailLog::factory()->create(['tiers_id' => $tiers->id, 'objet' => 'Second']);

    Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log1->id)
        ->assertSet('messageOuvertId', (int) $log1->id)
        ->call('toggleMessage', (int) $log2->id)
        ->assertSet('messageOuvertId', (int) $log2->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Toggle off — re-click même ligne ferme
// ─────────────────────────────────────────────────────────────────────────────
it('toggleMessage sur le même ID ferme le message (toggle off)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log->id)
        ->assertSet('messageOuvertId', (int) $log->id)
        ->call('toggleMessage', (int) $log->id)
        ->assertSet('messageOuvertId', null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Métadonnées masquées
// ─────────────────────────────────────────────────────────────────────────────
it('ne révèle pas les métadonnées internes (envoye_par, statut, tracking_token)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Email avec métadonnées',
        'statut' => 'envoye',
        'tracking_token' => 'TOKEN_SECRET_XYZ',
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)
        ->not->toContain('TOKEN_SECRET_XYZ')
        ->not->toContain('envoye_par');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6b : Métadonnées masquées — statut + erreur_message
// ─────────────────────────────────────────────────────────────────────────────
it('ne révèle pas statut ni erreur_message dans le rendu portail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Email avec erreur',
        'statut' => 'envoye',
        'erreur_message' => 'SMTP timeout connexion refusée',
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log->id)
        ->html();

    expect($html)
        ->not->toContain('envoye')
        ->not->toContain('SMTP timeout connexion refusée');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : PJ bouton présent (positif)
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bouton Télécharger la pièce jointe avec target="_blank" quand attachment_path est non null', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Email avec pièce jointe',
        'attachment_path' => 'associations/1/emails/doc.pdf',
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log->id)
        ->html();

    expect($html)
        ->toContain('Télécharger la pièce jointe')
        ->toContain('target="_blank"');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : PJ bouton absent (négatif)
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas le bouton Télécharger la pièce jointe quand attachment_path est null', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Email sans pièce jointe',
        'attachment_path' => null,
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->call('toggleMessage', (int) $log->id)
        ->html();

    expect($html)->not->toContain('Télécharger la pièce jointe');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Vocabulaire — pas de jargon technique
// ─────────────────────────────────────────────────────────────────────────────
it('ne contient pas le terme EmailLog dans le rendu HTML portail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Message ordinaire',
    ]);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect(strtolower($html))->not->toContain('emaillog');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Empty state
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le message vide si aucun email reçu', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesMessages::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Vous n')
        ->toContain('avez pas encore re')
        ->toContain('u de message.');
});
