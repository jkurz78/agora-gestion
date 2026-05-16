<?php

declare(strict_types=1);

/**
 * E2E test : devis envoyé depuis ReglementTable → visible sur portail Mes Messages avec PJ téléchargeable.
 *
 * Chaîne complète :
 *   1. Back-office : ReglementTable::envoyerDocumentEmail() crée un EmailLog avec
 *      corps_html + attachment_path via EmailLogStorageService.
 *   2. Portail : GET /portail/mes-messages liste le message et expose le corps HTML.
 *   3. Portail : lien "Télécharger la pièce jointe" présent dans l'expand.
 *   4. Portail : GET sur l'URL de téléchargement → 200 + application/pdf + bytes %PDF.
 */

use App\Enums\CategorieEmail;
use App\Livewire\Portail\MesMessages;
use App\Livewire\ReglementTable;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Support\PortailRoute;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();
    TenantContext::clear();

    // ─── Association tenant ───────────────────────────────────────────────────
    $this->association = Association::factory()->create([
        'email_from'      => 'asso@example.com',
        'email_from_name' => 'Association Test',
    ]);
    TenantContext::boot($this->association);

    // ─── Back-office user (admin) ─────────────────────────────────────────────
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role'      => 'admin',
        'joined_at' => now(),
    ]);

    // ─── Tiers (le membre du portail) ─────────────────────────────────────────
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'email'          => 'membre@example.com',
        'prenom'         => 'Alice',
        'nom'            => 'MARTIN',
    ]);

    // ─── Opération + participant ───────────────────────────────────────────────
    $sousCategorie = SousCategorie::factory()->create();
    $typeOp = TypeOperation::factory()->create([
        'sous_categorie_id' => $sousCategorie->id,
        'email_from'        => 'asso@example.com',
        'email_from_name'   => 'Association Test',
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
    ]);

    $this->participant = Participant::create([
        'tiers_id'          => $this->tiers->id,
        'operation_id'      => $this->operation->id,
        'date_inscription'  => now(),
    ]);

    // ─── Devis DocumentPrevisionnel ───────────────────────────────────────────
    $this->doc = DocumentPrevisionnel::factory()->devis()->create([
        'operation_id'   => $this->operation->id,
        'participant_id' => $this->participant->id,
        'version'        => 1,
        'date'           => now()->toDateString(),
        'montant_total'  => 250.00,
        'lignes_json'    => [],
    ]);

    // ─── Template email catégorie Document ───────────────────────────────────
    $this->template = EmailTemplate::create([
        'association_id'   => $this->association->id,
        'categorie'        => CategorieEmail::Document->value,
        'type_operation_id'=> null,
        'objet'            => 'Votre devis n° {numero_document}',
        'corps'            => '<p>Bonjour {prenom},</p><p>Votre devis est joint.</p>',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test principal E2E
// ─────────────────────────────────────────────────────────────────────────────
it('le devis envoyé depuis ReglementTable est visible sur Mes Messages avec PJ téléchargeable', function (): void {

    // ── Étape 1 : back-office envoie le devis ─────────────────────────────────
    $this->actingAs($this->user);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('envoyerDocumentEmail', $this->participant->id, 'devis');

    // Vérification intermédiaire : un EmailLog a été créé avec les données attendues
    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Alice');   // {prenom} substitué
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");
    Storage::disk('local')->assertExists($log->attachment_path);

    // ── Étape 2 : portail — liste Mes Messages ────────────────────────────────
    // Authentifier le Tiers via le guard portail (pattern des tests MesMessagesTest.php)
    Auth::guard('tiers-portail')->login($this->tiers);

    // Assertion 1 : la liste affiche bien l'objet du message
    $listHtml = Livewire::test(MesMessages::class, ['association' => $this->association])
        ->assertStatus(200)
        ->html();

    // L'objet ne doit plus contenir de placeholders, et doit être visible
    expect($listHtml)->toContain('Votre devis');

    // ── Étape 3 : portail — expand du message ─────────────────────────────────
    // Assertion 2 : le corps HTML contient le prénom du tiers (substituion validée)
    // Assertion 3 : le bouton "Télécharger la pièce jointe" est présent
    $expandHtml = Livewire::test(MesMessages::class, ['association' => $this->association])
        ->call('toggleMessage', (int) $log->id)
        ->html();

    expect($expandHtml)->toContain('Alice');                     // corps_html substitué visible
    expect($expandHtml)->toContain('Télécharger la pièce jointe'); // bouton PJ présent

    // ── Étape 4 : portail — téléchargement de la PJ ──────────────────────────
    // Assertion 4 : GET sur l'URL de téléchargement → 200 + application/pdf + %PDF
    $attachUrl = route('portail.messages.attachment', [
        'association' => $this->association->slug,
        'emailLog'    => $log->id,
    ]);

    $response = $this->get($attachUrl);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');

    $pdfBytes = Storage::disk('local')->get($log->attachment_path);
    expect($pdfBytes)->toStartWith('%PDF');
});
