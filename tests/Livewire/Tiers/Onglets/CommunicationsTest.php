<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Livewire\Tiers\Onglets\Communications;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailOpen;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

// ── Task 4.4 : rendu tableau + filtre catégorie ──────────────────────────────

it('rend le tableau des emails du tiers', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Premier email',
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->assertSee('Premier email');
});

it('filtre par catégorie via setFiltre', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Attestation->value,
        'objet' => 'Attest A',
    ]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Message->value,
        'objet' => 'Msg B',
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->call('setFiltre', CategorieEmail::Attestation->value)
        ->assertSee('Attest A')
        ->assertDontSee('Msg B');
});

// ── Task 4.5 : modale détail + garde tenant ──────────────────────────────────

it('ouvre et ferme la modale de détail', function (): void {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Detail me',
        'corps_html' => '<p>Body</p>',
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->call('openDetail', $log->id)
        ->assertSet('selectedEmailId', $log->id)
        ->assertSee('Detail me')
        ->call('closeDetail')
        ->assertSet('selectedEmailId', null);
});

it('ouvre la modale de détail pour un email lié à un participant et affiche son nom (regression bug 2026-05-16)', function (): void {
    // Bug remonté 2026-05-16 en prod (MySQL) :
    //   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nom' in 'field list'
    // L'eager-load `participant:id,nom,prenom` était erroné — les colonnes nom/prenom
    // vivent sur Tiers (Participant n'a que tiers_id). SQLite est tolérant et ne
    // déclenchait pas le bug en test ; on assert donc directement le rendu du nom
    // pour qu'il vienne forcément du tiers du participant.
    $tiersAdherent = Tiers::factory()->create(['prenom' => 'Bob', 'nom' => 'PARENT']);
    $tiersEnfant = Tiers::factory()->create(['prenom' => 'Alice', 'nom' => 'ENFANT']);
    $participant = Participant::factory()->create(['tiers_id' => $tiersEnfant->id]);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiersAdherent->id, // email envoyé au parent
        'participant_id' => $participant->id, // pour le compte de l'enfant
        'objet' => 'Attestation Alice',
        'corps_html' => '<p>Hello</p>',
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiersAdherent])
        ->call('openDetail', $log->id)
        ->assertSet('selectedEmailId', $log->id)
        ->assertSee('Attestation Alice')
        ->assertSee('Alice')    // prénom de l'enfant (via participant.tiers)
        ->assertSee('ENFANT');  // nom de l'enfant
});

it("refuse d'ouvrir un email qui n'appartient pas au tiers", function (): void {
    $tiers = Tiers::factory()->create();
    $autre = Tiers::factory()->create();
    $logAutre = EmailLog::factory()->create([
        'tiers_id' => $autre->id,
        'participant_id' => null,
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->call('openDetail', $logAutre->id)
        ->assertSet('selectedEmailId', null);
});

// ── Task 4.6 : badges PJ + erreur + ouvertures ───────────────────────────────

it("affiche l'icône pièce jointe quand attachment_path est rempli", function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()
        ->avecPieceJointe('emails/1/file.pdf')
        ->create(['tiers_id' => $tiers->id, 'participant_id' => null]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->assertSeeHtml('bi-paperclip');
});

it('affiche le badge erreur quand statut=erreur', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->avecErreur('SMTP timeout')->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->assertSee('Erreur');
});

it("affiche le compteur d'ouvertures", function (): void {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);
    EmailOpen::factory()->count(2)->create(['email_log_id' => $log->id]);

    Livewire::test(Communications::class, ['tiers' => $tiers])
        ->assertSeeHtml('bi-eye')
        ->assertSeeHtml('<i class="bi bi-eye"')
        ->assertSee('2');
});
