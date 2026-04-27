<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────────────────────

function topbarAdminUser(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function topbarComptableUser(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function topbarGestionnaireUser(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function createIncomingDoc(Association $association): IncomingDocument
{
    return IncomingDocument::create([
        'association_id' => $association->id,
        'storage_path' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'sender_email' => 'test@example.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    TenantContext::clear();
    Storage::fake('local');

    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── (a) Admin avec NDF + Factures + Mails en attente ─────────────────────────

it('(a) Admin : trigger dropdown avec badge cumulatif, 3 items chacun avec leur compteur', function (): void {
    $admin = topbarAdminUser($this->association);

    // 2 NDF soumises
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    NoteDeFrais::factory()->soumise()->count(2)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);

    // 3 factures soumises
    $tiersFp = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id]);
    FacturePartenaireDeposee::factory()->soumise()->count(3)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiersFp->id,
    ]);

    // 1 document reçu
    createIncomingDoc($this->association);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));
    $response->assertOk();

    $html = $response->getContent();

    // Le trigger dropdown doit être présent
    expect($html)->toContain('data-bs-toggle="dropdown"');

    // Badge cumulatif = 2 + 3 + 1 = 6
    expect($html)->toContain('>6<');

    // Les 3 items du dropdown sont présents
    expect($html)->toContain(route('comptabilite.ndf.index'));
    expect($html)->toContain(route('comptabilite.factures-fournisseurs.index'));
    expect($html)->toContain(route('facturation.documents-en-attente'));

    // Les compteurs individuels apparaissent dans le dropdown
    expect($html)->toContain('>2<');
    expect($html)->toContain('>3<');
    expect($html)->toContain('>1<');
})->group('topbar-dropdown');

// ── (b) Comptable : même comportement que Admin ───────────────────────────────

it('(b) Comptable : même comportement que Admin (3 items, badge cumulatif)', function (): void {
    $comptable = topbarComptableUser($this->association);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    NoteDeFrais::factory()->soumise()->count(1)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);

    $tiersFp = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id]);
    FacturePartenaireDeposee::factory()->soumise()->count(1)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiersFp->id,
    ]);

    createIncomingDoc($this->association);

    $response = $this->actingAs($comptable)->get(route('comptabilite.transactions'));
    $response->assertOk();

    $html = $response->getContent();

    // Badge cumulatif = 1 + 1 + 1 = 3
    expect($html)->toContain('>3<');

    // Les 3 items sont présents
    expect($html)->toContain(route('comptabilite.ndf.index'));
    expect($html)->toContain(route('comptabilite.factures-fournisseurs.index'));
    expect($html)->toContain(route('facturation.documents-en-attente'));
})->group('topbar-dropdown');

// ── (c) Gestionnaire : badge = mails uniquement, items NDF/Factures absents ───

it('(c) Gestionnaire : badge = mails uniquement, items NDF et Factures fournisseurs absents du dropdown', function (): void {
    $gestionnaire = topbarGestionnaireUser($this->association);

    // NDF et factures existent mais ne doivent pas être visibles
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    NoteDeFrais::factory()->soumise()->count(5)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);

    $tiersFp = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id]);
    FacturePartenaireDeposee::factory()->soumise()->count(4)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiersFp->id,
    ]);

    // 2 documents reçus
    createIncomingDoc($this->association);
    createIncomingDoc($this->association);

    $response = $this->actingAs($gestionnaire)->get(route('comptabilite.transactions'));
    $response->assertOk();

    $html = $response->getContent();

    // Badge cumulatif = 2 (mails seulement, NDF et Factures filtrées)
    expect($html)->toContain('>2<');

    // Le dropdown est présent (docs reçus > 0)
    expect($html)->toContain('data-bs-toggle="dropdown"');

    // L'item Documents reçus est présent
    expect($html)->toContain(route('facturation.documents-en-attente'));

    // Les items NDF et Factures fournisseurs sont absents du topbar
    // (les routes NDF/FP ne doivent PAS apparaître dans la topbar pour un Gestionnaire)
    // La sidebar ne rend pas non plus ces routes pour le rôle Gestionnaire, donc
    // l'assertion en pleine page est sûre : 0 occurrence dans tout le HTML rendu.
    $response->assertDontSee(route('comptabilite.ndf.index'));
    $response->assertDontSee(route('comptabilite.factures-fournisseurs.index'));
})->group('topbar-dropdown');

// ── (d) Tous les compteurs = 0 → trigger absent ───────────────────────────────

it('(d) Tous les compteurs à 0 → le trigger dropdown est absent du DOM', function (): void {
    $admin = topbarAdminUser($this->association);

    // S'assurer qu'il n'y a aucun document, NDF ou facture
    IncomingDocument::where('association_id', $this->association->id)->delete();
    NoteDeFrais::where('association_id', $this->association->id)->delete();
    FacturePartenaireDeposee::where('association_id', $this->association->id)->delete();

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));
    $response->assertOk();

    $html = $response->getContent();

    // Le trigger dropdown de réception ne doit pas apparaître
    // On vérifie qu'il n'y a pas de badge "Boîte de réception" dans la topbar
    // Le div.dropdown avec bi-inbox n'est pas rendu
    expect($html)->not->toContain('bi bi-inbox-fill');

    // Plus précisément : la topbar ne contient pas le titre "Boîte de réception"
    // avec un dropdown trigger — on cherche l'attribut title spécifique au widget
    expect($html)->not->toContain('pièce(s) en attente');
})->group('topbar-dropdown');

// ── (e) Les 3 anciens widgets individuels sont supprimés ─────────────────────

it('(e) Les 3 anciens widgets topbar individuels sont absents (remplacés par le dropdown)', function (): void {
    $admin = topbarAdminUser($this->association);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    NoteDeFrais::factory()->soumise()->count(1)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);

    $tiersFp = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id]);
    FacturePartenaireDeposee::factory()->soumise()->count(1)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiersFp->id,
    ]);

    createIncomingDoc($this->association);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));
    $response->assertOk();

    $html = $response->getContent();

    $fpRoute = route('comptabilite.factures-fournisseurs.index');
    $docsRoute = route('facturation.documents-en-attente');

    // Le topbar du layout app-sidebar est un <header class="sticky-top ...">
    // On extrait la zone topbar (entre <header et </header>)
    preg_match('/<header[^>]*sticky-top[^>]*>.*?<\/header>/s', $html, $headerMatches);
    $topbarHtml = $headerMatches[0] ?? '';

    // Si le header n'a pas été trouvé, fallback : vérifier directement dans le HTML
    // que les anciens widgets standalone ne sont plus présents.
    // Les anciens widgets étaient des <a> directs (non dans dropdown-item) avec bi-receipt-cutoff
    // ou bi-file-earmark-text immédiatement suivis d'un badge standalone.
    // Proxy sûr : les anciens widgets avaient title="X note(s) de frais à traiter" et
    // title="X facture(s) en attente de traitement" — ces titres ne doivent plus exister.
    expect($html)->not->toContain('note(s) de frais à traiter');
    expect($html)->not->toContain('facture(s) en attente de traitement');
    expect($html)->not->toContain('document(s) en attente');

    // Vérifier que la route FP n'apparaît pas comme lien standalone dans la topbar :
    // dans l'ancien code, c'était un <a> direct. Maintenant elle est dans un dropdown-item.
    // On vérifie l'absence du pattern standalone : <a href="fpRoute" class="text-decoration-none ...">
    $standalonePattern = '/<a[^>]*href="'.preg_quote($fpRoute, '/').'"[^>]*class="text-decoration-none[^"]*"/';
    expect(preg_match($standalonePattern, $html))->toBe(0);

    // La route docs ne doit plus être un lien standalone non-dropdown (ancien pattern) :
    $standaloneDocsPattern = '/<a[^>]*href="'.preg_quote($docsRoute, '/').'"[^>]*class="text-decoration-none[^"]*"/';
    expect(preg_match($standaloneDocsPattern, $html))->toBe(0);

    // Les routes sont maintenant uniquement dans le dropdown (classe dropdown-item)
    expect($html)->toContain('dropdown-item');
    expect($html)->toContain($fpRoute);
    expect($html)->toContain($docsRoute);
})->group('topbar-dropdown');
