<?php

declare(strict_types=1);

use App\Mail\AttestationPresenceMail;
use App\Mail\CommunicationTiersMail;
use App\Mail\DevisManuelMail;
use App\Mail\DocumentMail;
use App\Mail\MessageLibreMail;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create(['nom' => 'Asso Test']);
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── MessageLibreMail ──────────────────────────────────────────────────────────

it('MessageLibreMail substitue {politesse_nom} avec civilité', function (): void {
    $mail = new MessageLibreMail(
        prenomParticipant: 'Alice',
        nomParticipant: 'KURZ',
        emailParticipant: 'alice@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'Stage',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 5,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 2,
        nbSeancesRestantes: 3,
        objet: 'Message pour {politesse_nom}',
        corps: '<p>Bonjour {politesse_nom},</p>',
        attachmentPaths: [],
        typeOperationId: null,
        trackingToken: null,
        seances: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    expect($mail->corpsHtml)->toContain('Bonjour Monsieur KURZ,');
    expect($mail->envelope()->subject)->toContain('Monsieur KURZ');
});

it('MessageLibreMail absorbe l\'espace si civilité vide', function (): void {
    $mail = new MessageLibreMail(
        prenomParticipant: 'Alice',
        nomParticipant: 'KURZ',
        emailParticipant: 'alice@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'Stage',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 5,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 2,
        nbSeancesRestantes: 3,
        objet: 'Message',
        corps: '<p>Bonjour {politesse} KURZ,</p>',
        attachmentPaths: [],
        typeOperationId: null,
        trackingToken: null,
        seances: null,
        civilite: null,
        politesse: null,
    );

    // Absorption : {politesse} vide + espace droit absorbé → "Bonjour KURZ,"
    expect($mail->corpsHtml)->toContain('Bonjour KURZ,');
    expect($mail->corpsHtml)->not->toContain('{politesse}');
});

// ── CommunicationTiersMail ────────────────────────────────────────────────────

it('CommunicationTiersMail substitue {politesse_nom} avec civilité', function (): void {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@test.fr',
        objet: 'Cher {politesse_nom}',
        corps: '<p>Bonjour {politesse_nom},</p>',
        trackingToken: null,
        attachmentPaths: [],
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    expect($mail->corpsHtml)->toContain('Bonjour Monsieur DUPONT,');
    expect($mail->envelope()->subject)->toContain('Monsieur DUPONT');
});

it('CommunicationTiersMail absorbe l\'espace si civilité vide', function (): void {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@test.fr',
        objet: 'Message',
        corps: '<p>Bonjour {politesse} DUPONT,</p>',
        trackingToken: null,
        attachmentPaths: [],
        civilite: null,
        politesse: null,
    );

    expect($mail->corpsHtml)->toContain('Bonjour DUPONT,');
    expect($mail->corpsHtml)->not->toContain('{politesse}');
});

// ── AttestationPresenceMail ───────────────────────────────────────────────────

it('AttestationPresenceMail substitue {politesse_nom} avec civilité', function (): void {
    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'MARTIN',
        nomOperation: 'Stage musique',
        nomTypeOperation: 'Stage',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '10',
        numeroSeance: '3',
        dateSeance: '15/10/2025',
        customObjet: null,
        customCorps: '<p>Bonjour {politesse_nom},</p>',
        pdfContent: 'fake-pdf',
        pdfFilename: 'attestation.pdf',
        libelleArticle: null,
        blocSeances: null,
        typeOperationId: null,
        civilite: 'Mme',
        politesse: 'Madame',
    );

    expect($mail->corpsHtml)->toContain('Bonjour Madame MARTIN,');
});

it('AttestationPresenceMail absorbe l\'espace si civilité vide', function (): void {
    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'MARTIN',
        nomOperation: 'Stage musique',
        nomTypeOperation: 'Stage',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '10',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>Bonjour {politesse} MARTIN,</p>',
        pdfContent: 'fake-pdf',
        pdfFilename: 'attestation.pdf',
        libelleArticle: null,
        blocSeances: null,
        typeOperationId: null,
        civilite: null,
        politesse: null,
    );

    expect($mail->corpsHtml)->toContain('Bonjour MARTIN,');
    expect($mail->corpsHtml)->not->toContain('{politesse}');
});

// ── DocumentMail ──────────────────────────────────────────────────────────────

it('DocumentMail substitue {politesse_nom} avec civilité', function (): void {
    $mail = new DocumentMail(
        prenomDestinataire: 'Paul',
        nomDestinataire: 'LEBRUN',
        typeDocument: 'facture',
        typeDocumentArticle: 'la facture',
        typeDocumentArticleDe: 'de la facture',
        numeroDocument: 'F2025-001',
        dateDocument: '01/01/2026',
        montantTotal: '100,00 €',
        customObjet: null,
        customCorps: '<p>Bonjour {politesse_nom},</p>',
        pdfContent: 'fake-pdf',
        pdfFilename: 'facture.pdf',
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    expect($mail->corpsHtml)->toContain('Bonjour Monsieur LEBRUN,');
});

it('DocumentMail absorbe l\'espace si civilité vide', function (): void {
    $mail = new DocumentMail(
        prenomDestinataire: 'Paul',
        nomDestinataire: 'LEBRUN',
        typeDocument: 'facture',
        typeDocumentArticle: 'la facture',
        typeDocumentArticleDe: 'de la facture',
        numeroDocument: 'F2025-001',
        dateDocument: '01/01/2026',
        montantTotal: '100,00 €',
        customObjet: null,
        customCorps: '<p>Bonjour {politesse} LEBRUN,</p>',
        pdfContent: 'fake-pdf',
        pdfFilename: 'facture.pdf',
        typeOperationId: null,
        civilite: null,
        politesse: null,
    );

    expect($mail->corpsHtml)->toContain('Bonjour LEBRUN,');
    expect($mail->corpsHtml)->not->toContain('{politesse}');
});

// ── DevisManuelMail ───────────────────────────────────────────────────────────

it('DevisManuelMail substitue {politesse_nom} dans le corps rendu', function (): void {
    $tiers = Tiers::factory()->create([
        'prenom' => 'Luc',
        'nom' => 'BERNARD',
        'email' => 'luc@test.fr',
    ]);
    $devis = Devis::factory()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $this->association->id,
    ]);

    $mail = new DevisManuelMail(
        devis: $devis,
        sujet: 'Votre devis',
        corps: '<p>Bonjour {politesse_nom},</p>',
        pdfPath: 'devis/fake.pdf',
        civilite: 'M.',
        politesse: 'Monsieur',
        prenom: 'Luc',
        nom: 'BERNARD',
    );

    expect($mail->corpsHtml)->toContain('Bonjour Monsieur BERNARD,');
});

it('DevisManuelMail absorbe l\'espace si civilité vide', function (): void {
    $tiers = Tiers::factory()->create([
        'prenom' => 'Luc',
        'nom' => 'BERNARD',
        'email' => 'luc@test.fr',
    ]);
    $devis = Devis::factory()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $this->association->id,
    ]);

    $mail = new DevisManuelMail(
        devis: $devis,
        sujet: 'Votre devis',
        corps: '<p>Bonjour {politesse} BERNARD,</p>',
        pdfPath: 'devis/fake.pdf',
        civilite: null,
        politesse: null,
        prenom: 'Luc',
        nom: 'BERNARD',
    );

    expect($mail->corpsHtml)->toContain('Bonjour BERNARD,');
    expect($mail->corpsHtml)->not->toContain('{politesse}');
});
