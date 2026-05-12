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
        ->assertSeeInOrder(['bi-eye', '2']);
});
