<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\StatutInvitation;
use App\Livewire\Questionnaire\CampagneShow;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\User;
use App\Services\Questionnaire\QuestionnaireTokenService;
use App\Tenant\TenantContext;
use Illuminate\Support\Str;
use Livewire\Livewire;

function campagnePourFiche(string $statut = 'ouverte'): QuestionnaireCampaign
{
    $op = Operation::factory()->create();

    return QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => $statut]);
}

it('affiche la fiche sur l onglet suivi par défaut', function (): void {
    $campagne = campagnePourFiche();

    $this->actingAs(User::factory()->create())
        ->get(route('questionnaires.campagnes.show', $campagne))
        ->assertOk()
        ->assertSee($campagne->titre_affiche ?: $campagne->titre)
        ->assertSeeLivewire(CampagneShow::class);
});

it('ouvre l onglet demandé par ?tab=', function (): void {
    $campagne = campagnePourFiche();

    Livewire::withQueryParams(['tab' => 'resultats'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSet('tab', 'resultats');
});

it('retombe sur suivi pour un onglet inconnu', function (): void {
    $campagne = campagnePourFiche();

    Livewire::withQueryParams(['tab' => 'nimporte'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSet('tab', 'suivi');
});

it('lance une campagne brouillon depuis l en-tête', function (): void {
    $campagne = campagnePourFiche('brouillon');

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('ouvrir');

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
});

it('clôture une campagne ouverte depuis l en-tête', function (): void {
    $campagne = campagnePourFiche();

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('cloturer');

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Cloturee);
});

it('importe un scan ciblé pour une invitation depuis l onglet suivi', function (): void {
    $campagne = campagnePourFiche();
    $participant = Participant::factory()->create(['operation_id' => $campagne->operation_id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => (int) $participant->id,
        'token_hash' => hash('sha256', 'tok-'.uniqid()),
        'token_chiffre' => 'tok',
        'code_court' => strtoupper(substr(md5(uniqid()), 0, 8)),
        'statut' => StatutInvitation::NonOuvert,
    ]);

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('ouvrirScanPour', (int) $invitation->id)
        ->assertSet('scanPourInvitationId', (int) $invitation->id);
});

it('renvoie 404 pour la fiche d une campagne d une autre association (AC11)', function (): void {
    TenantContext::clear();

    // ── Asso A : propriétaire de la campagne ──
    $assoA = Association::factory()->create();
    TenantContext::boot($assoA);
    $campagneA = campagnePourFiche();

    // ── Asso B : utilisateur intrus ──
    $assoB = Association::factory()->create();
    $userB = User::factory()->create();
    $userB->associations()->attach($assoB->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($assoB);
    session(['current_association_id' => $assoB->id]);

    // TenantScope fail-closed + route model binding : la campagne A est invisible sous B → 404.
    $this->actingAs($userB)
        ->get(route('questionnaires.campagnes.show', ['campagne' => $campagneA->id]))
        ->assertNotFound();
});

// ── Assertions migrées depuis l'ancienne liste (OperationQuestionnairesTest) ──

it('affiche le bouton Lancer et pas Ouvrir sur une campagne brouillon', function (): void {
    $campagne = campagnePourFiche('brouillon');

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSee('Lancer')
        ->assertDontSee('Ouvrir');
});

it('l onglet diffusion montre les liens envoi et PDF papier (non anonyme)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => 'ouverte',
        'anonymise' => false,
    ]);

    Livewire::withQueryParams(['tab' => 'diffusion'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSee(route('questionnaires.campagnes.envoi', $campagne))
        ->assertSee(route('questionnaires.campagnes.pdf', $campagne))
        ->assertSee('PDF papier')
        ->assertDontSee('Imprimer (anonyme)');
});

it('l onglet diffusion d une campagne anonyme montre Imprimer (anonyme)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => 'ouverte',
        'anonymise' => true,
    ]);

    Livewire::withQueryParams(['tab' => 'diffusion'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSee('Imprimer (anonyme)')
        ->assertSee(route('questionnaires.campagnes.pdf-anonyme', $campagne));
});

it('permet à l admin de rouvrir une invitation soumise depuis la fiche', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => 'ouverte',
        'anonymise' => false,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::Soumis,
    ]);

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('rouvrirInvitation', (int) $invitation->id);

    expect($invitation->fresh()->statut->value)->toBe('commence');
});
