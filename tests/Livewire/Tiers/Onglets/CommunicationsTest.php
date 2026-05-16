<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Livewire\Tiers\Onglets\Communications;
use App\Models\Association;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\EmailOpen;
use App\Models\EmailTemplate;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
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

it('ouvre la modale de détail et affiche participant, envoyé par et modèle (regression bugs 2026-05-16)', function (): void {
    // Bugs remontés 2026-05-16 en prod (MySQL) — SQLite tolère les colonnes
    // inexistantes, donc on assert directement le rendu pour forcer les bons accès :
    //   - `participant:id,nom,prenom` → Participant n'a ni nom ni prenom (sur tiers)
    //   - `envoyePar:id,name` → users a `nom`, pas `name`
    //   - `emailTemplate:id,nom` → email_templates n'a ni nom ni name
    //   - `campagne:id,nom` → campagnes_email a `objet`, pas `nom`
    $tiersAdherent = Tiers::factory()->create(['prenom' => 'Bob', 'nom' => 'PARENT']);
    $tiersEnfant = Tiers::factory()->create(['prenom' => 'Alice', 'nom' => 'ENFANT']);
    $participant = Participant::factory()->create(['tiers_id' => $tiersEnfant->id]);
    $envoyeur = User::factory()->create(['nom' => 'AdminUser']);
    $typeOp = TypeOperation::factory()->create(['nom' => 'PSA Yoga']);
    $template = EmailTemplate::create([
        'association_id' => $tiersAdherent->association_id,
        'categorie' => CategorieEmail::Attestation->value,
        'type_operation_id' => $typeOp->id,
        'objet' => 'Attestation',
        'corps' => '<p>Corps</p>',
    ]);
    $campagne = CampagneEmail::create([
        'association_id' => $tiersAdherent->association_id,
        'objet' => 'Newsletter Mai',
        'corps' => '<p>Newsletter</p>',
        'envoye_par' => $envoyeur->id,
    ]);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiersAdherent->id, // email envoyé au parent
        'participant_id' => $participant->id, // pour le compte de l'enfant
        'envoye_par' => $envoyeur->id,
        'email_template_id' => $template->id,
        'campagne_id' => $campagne->id,
        'objet' => 'Attestation Alice',
        'corps_html' => '<p>Hello</p>',
    ]);

    Livewire::test(Communications::class, ['tiers' => $tiersAdherent])
        ->call('openDetail', $log->id)
        ->assertSet('selectedEmailId', $log->id)
        ->assertSee('Attestation Alice')
        ->assertSee('Alice')         // prénom de l'enfant (via participant.tiers)
        ->assertSee('ENFANT')        // nom de l'enfant
        ->assertSee('AdminUser')     // nom utilisateur ayant envoyé (envoyePar.nom)
        ->assertSee('PSA Yoga')      // type_operation du template (emailTemplate.typeOperation.nom)
        ->assertSee('Newsletter Mai'); // objet de la campagne (campagne.objet)
});

it('rend le corps_html sans double-échappement dans le srcdoc de l\'iframe', function (): void {
    // Bug 2026-05-16 : `srcdoc="{{ e(...) }}"` faisait un double-escape (le `{{ }}`
    // Blade escape déjà). Résultat : l'iframe affichait les tags comme du texte
    // au lieu de rendre le HTML. Le srcdoc doit contenir `&lt;p&gt;` (single-escape)
    // pas `&amp;lt;p&amp;gt;` (double-escape).
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Sujet',
        'corps_html' => '<p>Bonjour <strong>Jean</strong></p>',
    ]);

    $component = Livewire::test(Communications::class, ['tiers' => $tiers])
        ->call('openDetail', $log->id);

    $html = $component->html();

    // Single-escape attendu dans le srcdoc
    expect($html)->toContain('&lt;p&gt;Bonjour');
    // Le double-escape (bug) inclurait `&amp;lt;`
    expect($html)->not->toContain('&amp;lt;p&amp;gt;');
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
