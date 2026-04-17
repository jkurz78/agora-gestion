<?php

declare(strict_types=1);

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\IncomingDocument;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\DocumentPrevisionnelService;
use App\Support\TenantAsset;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->assoA = Association::factory()->create(['nom' => 'Asso A']);
    $this->assoB = Association::factory()->create(['nom' => 'Asso B']);

    // UserA : admin de l'asso A
    $this->userA = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->userA->associations()->attach($this->assoA->id, ['role' => 'admin', 'joined_at' => now()]);

    // UserB : admin de l'asso B (utile pour créer des ressources)
    $this->userB = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->userB->associations()->attach($this->assoB->id, ['role' => 'admin', 'joined_at' => now()]);
});

afterEach(fn () => TenantContext::clear());

/**
 * Active le contexte tenant + session + auth pour un user donné.
 */
function actAsInAsso(User $user, Association $asso): void
{
    TenantContext::boot($asso);
    session(['current_association_id' => $asso->id]);
    test()->actingAs($user);
}

// ── Scénarios sur la route /tenant-assets ─────────────────────────────────

describe('tenant-assets : isolation cross-tenant', function () {

    it('bloque un user qui demande un fichier d\'un autre tenant avec signature valide forgée', function () {
        // User A loggé dans le contexte A, mais demande un path du tenant B
        actAsInAsso($this->userA, $this->assoA);

        $path = 'associations/'.$this->assoB->id.'/branding/logo.png';
        Storage::disk('local')->put($path, 'SECRET_B');

        // TenantAsset::url génère une URL signée valide, mais le path pointe vers B
        $url = TenantAsset::url($path);

        $response = $this->get($url);
        $response->assertForbidden();

        Storage::disk('local')->delete($path);
    });

    it('bloque un path traversal encodé vers un autre tenant', function () {
        actAsInAsso($this->userA, $this->assoA);

        // Path : associations/{A}/../{B}/logo.png — contient ".." → rejeté par VerifyTenantAsset
        $traversalPath = 'associations/'.$this->assoA->id.'/../'.$this->assoB->id.'/logo.png';
        $url = TenantAsset::url($traversalPath);

        $response = $this->get($url);
        $response->assertForbidden();
    });

    it('refuse une URL signée dont le TTL est expiré', function () {
        actAsInAsso($this->userA, $this->assoA);

        $path = 'associations/'.$this->assoA->id.'/branding/logo.png';
        Storage::disk('local')->put($path, 'OK');

        // Générer une URL avec une expiration dans le passé
        $expiredUrl = URL::temporarySignedRoute(
            'tenant-assets',
            now()->subMinute(),
            ['path' => $path],
        );

        $response = $this->get($expiredUrl);
        $response->assertForbidden();

        Storage::disk('local')->delete($path);
    });

    it('refuse une signature modifiée après génération (tamper)', function () {
        actAsInAsso($this->userA, $this->assoA);

        $path = 'associations/'.$this->assoA->id.'/branding/logo.png';
        Storage::disk('local')->put($path, 'OK');

        $validUrl = TenantAsset::url($path);

        // Remplace la valeur de signature par une chaîne arbitraire
        $tamperedUrl = preg_replace('/signature=[a-f0-9A-F]+/', 'signature=deadbeefdeadbeef', $validUrl);
        expect($tamperedUrl)->not->toBeNull()->and($tamperedUrl)->not->toBe($validUrl);

        $response = $this->get((string) $tamperedUrl);
        $response->assertForbidden();

        Storage::disk('local')->delete($path);
    });

    it('redirige un user déconnecté vers la page de login', function () {
        // Pas d'actingAs — utilisateur non authentifié
        $path = 'associations/1/branding/logo.png';

        // URL avec une signature syntaxiquement valide mais expirée/invalide
        $url = '/tenant-assets/'.$path.'?signature=x&expires=9999999999';
        $response = $this->get($url);

        // Le middleware 'auth' doit intercepter et rediriger (302) vers login
        expect($response->status())->toBeIn([302, 401, 403]);
        if ($response->status() === 302) {
            expect($response->headers->get('Location'))->toContain('login');
        }
    });
});

// ── Scénarios sur les controllers qui résolvent des modèles via route model binding ──

describe('cross-tenant controller access : 404 via TenantScope global', function () {

    it('ParticipantDocumentController renvoie 404 pour un participant d\'un autre tenant', function () {
        Storage::fake('local');

        // Crée un participant dans le contexte de B
        TenantContext::boot($this->assoB);
        $typeOpB = TypeOperation::factory()->create(['association_id' => $this->assoB->id]);
        $operationB = Operation::factory()->create([
            'association_id' => $this->assoB->id,
            'type_operation_id' => $typeOpB->id,
        ]);
        $tiersB = Tiers::factory()->create(['association_id' => $this->assoB->id]);
        $participantB = Participant::create([
            'tiers_id' => $tiersB->id,
            'operation_id' => $operationB->id,
            'date_inscription' => '2026-01-15',
        ]);
        $pidB = $participantB->id;
        // Dépose un vrai fichier pour s'assurer que le 404 vient du scope, pas du fichier manquant
        Storage::disk('local')->put(
            "associations/{$this->assoB->id}/participants/{$pidB}/certificat.pdf",
            'SECRET_B_DOC'
        );
        TenantContext::clear();

        // User A dans le contexte A tente d'accéder au participant de B
        actAsInAsso($this->userA, $this->assoA);

        $response = $this->get(route('operations.participants.documents.download', [
            'participant' => $pidB,
            'filename' => 'certificat.pdf',
        ]));

        // Le TenantScope filtre par association_id=A → participant B introuvable → 404
        $response->assertNotFound();
    });

    it('TransactionPieceJointeController renvoie 404 pour une transaction d\'un autre tenant', function () {
        Storage::fake('local');

        // Crée une transaction dans le contexte de B
        TenantContext::boot($this->assoB);
        $compteB = CompteBancaire::factory()->create(['association_id' => $this->assoB->id]);
        $txB = Transaction::factory()->create([
            'association_id' => $this->assoB->id,
            'compte_id' => $compteB->id,
            'piece_jointe_path' => 'facture-b.pdf',
            'piece_jointe_nom' => 'facture-b.pdf',
            'piece_jointe_mime' => 'application/pdf',
        ]);
        $txIdB = $txB->id;
        TenantContext::clear();

        // User A dans le contexte A tente d'accéder à la transaction de B
        actAsInAsso($this->userA, $this->assoA);

        $response = $this->get(route('transactions.piece-jointe', ['transaction' => $txIdB]));

        // Le TenantScope filtre par association_id=A → transaction B introuvable → 404
        $response->assertNotFound();
    });

    it('SeanceFeuilleController renvoie 404 pour une séance d\'un autre tenant', function () {
        Storage::fake('local');

        // Crée opération + séance dans le contexte de B
        TenantContext::boot($this->assoB);
        $typeOpB = TypeOperation::factory()->create(['association_id' => $this->assoB->id]);
        $operationB = Operation::factory()->create([
            'association_id' => $this->assoB->id,
            'type_operation_id' => $typeOpB->id,
        ]);
        $seanceB = Seance::create([
            'operation_id' => $operationB->id,
            'numero' => 1,
            'date' => '2026-03-15',
            'feuille_signee_path' => 'feuille-b.pdf',
        ]);
        $oidB = $operationB->id;
        $sidB = $seanceB->id;
        TenantContext::clear();

        // User A dans le contexte A tente d'accéder à la séance de B
        actAsInAsso($this->userA, $this->assoA);

        $response = $this->get(route('operations.seances.feuille-signee.download', [
            'operation' => $oidB,
            'seance' => $sidB,
        ]));

        // Le TenantScope filtre par association_id=A → opération/séance B introuvables → 404
        $response->assertNotFound();
    });

    it('IncomingDocumentsController renvoie 404 pour un document d\'un autre tenant', function () {
        Storage::fake('local');

        // Crée un IncomingDocument dans le contexte de B
        TenantContext::boot($this->assoB);
        $shortName = 'inbox-b-'.uniqid().'.pdf';
        $fullPath = 'associations/'.$this->assoB->id.'/incoming-documents/'.$shortName;
        Storage::disk('local')->put($fullPath, '%PDF-1.4 secret_b');
        $docB = IncomingDocument::create([
            'association_id' => $this->assoB->id,
            'storage_path' => $shortName,
            'original_filename' => 'facture-b.pdf',
            'sender_email' => 'fournisseur-b@example.com',
            'received_at' => now(),
            'reason' => 'unclassified',
        ]);
        $docIdB = $docB->id;
        TenantContext::clear();

        // User A dans le contexte A tente d'accéder au document de B
        actAsInAsso($this->userA, $this->assoA);

        $response = $this->get(route('facturation.documents-en-attente.download', ['document' => $docIdB]));

        // Le TenantScope filtre par association_id=A → document B introuvable → 404
        $response->assertNotFound();
    });

    it('DocumentPrevisionnelPdfController renvoie 404 pour un document d\'un autre tenant', function () {
        Storage::fake('local');

        // Crée un DocumentPrevisionnel dans le contexte de B
        // DocumentPrevisionnelService::emettre() appelle Auth::id(), donc on auth userB d'abord
        TenantContext::boot($this->assoB);
        $this->actingAs($this->userB);

        Exercice::create([
            'annee' => 2025,
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-08-31',
            'statut' => 'ouvert',
        ]);

        $typeOpB = TypeOperation::factory()->create(['association_id' => $this->assoB->id]);
        $operationB = Operation::factory()->create([
            'association_id' => $this->assoB->id,
            'type_operation_id' => $typeOpB->id,
            'date_debut' => '2025-10-01',
            'date_fin' => '2025-12-15',
        ]);
        $tiersB = Tiers::factory()->create(['association_id' => $this->assoB->id]);
        $participantB = Participant::create([
            'operation_id' => $operationB->id,
            'tiers_id' => $tiersB->id,
            'date_inscription' => '2025-09-15',
        ]);

        /** @var DocumentPrevisionnelService $service */
        $service = app(DocumentPrevisionnelService::class);
        $docB = $service->emettre($operationB, $participantB, TypeDocumentPrevisionnel::Devis);
        $docIdB = $docB->id;
        TenantContext::clear();

        // User A dans le contexte A tente d'accéder au document de B
        actAsInAsso($this->userA, $this->assoA);

        $response = $this->get(route('operations.documents-previsionnels.pdf', ['document' => $docIdB]));

        // Le TenantScope filtre par association_id=A → document B introuvable → 404
        $response->assertNotFound();
    });
});
