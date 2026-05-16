<?php

declare(strict_types=1);

use App\Helpers\EmailLogo;
use App\Mail\AttestationPresenceMail;
use App\Mail\CommunicationTiersMail;
use App\Mail\DocumentMail;
use App\Mail\FormulaireInvitation;
use App\Mail\MessageLibreMail;
use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ── EmailLogo::resolve() ─────────────────────────────────────────────────────

it('EmailLogo::resolve() returns null when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);

    expect(EmailLogo::resolve($association))->toBeNull();
});

it('EmailLogo::resolve() returns null when logo file does not exist on disk', function () {
    $association = Association::factory()->create(['logo_path' => 'missing.png']);
    // File not stored on disk

    expect(EmailLogo::resolve($association))->toBeNull();
});

it('EmailLogo::resolve() returns path and mime when logo exists on disk', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');

    $result = EmailLogo::resolve($association);

    expect($result)->not->toBeNull()
        ->and($result)->toHaveKey('path')
        ->and($result)->toHaveKey('mime')
        ->and($result['path'])->toContain("associations/{$id}/branding/logo.png");
});

// ── {logo} variable in corps → cid: reference ────────────────────────────────

it('{logo} variable resolves to cid:logo-asso img tag when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $vars = EmailLogo::variables();

    expect($vars['{logo}'])->toContain('cid:logo-asso');
});

it('{logo} variable resolves to empty string when no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $vars = EmailLogo::variables();

    expect($vars['{logo}'])->toBe('');
});

// ── AttestationPresenceMail ───────────────────────────────────────────────────

it('AttestationPresenceMail: corps contains cid:logo-asso when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>{logo}Bonjour {prenom}</p>',
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('AttestationPresenceMail: has 2 attachments (pdf + logo inline) when logo exists AND body references {logo}', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>{logo}Bonjour {prenom}</p>',
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    $attachments = $mail->attachments();

    // PDF + logo inline
    expect($attachments)->toHaveCount(2);

    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );
    expect($logoAttachment)->not->toBeNull();
});

it('AttestationPresenceMail: no logo attachment when logo exists but body does not reference {logo}', function () {
    // Reproduit le bug "logo géant" : un client mail (Apple Mail / Gmail) qui reçoit
    // une PJ inline (CID) NON référencée dans le corps l'affiche à la fin du message
    // à sa taille native. Le logo ne doit être attaché que s'il est utilisé.
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>Bonjour {prenom}</p>', // pas de {logo}
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
    expect($attachments)->toHaveCount(1); // PDF seul
});

it('AttestationPresenceMail: has 1 attachment (pdf only) when no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: null,
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->as)->toBe('attestation.pdf');
});

it('AttestationPresenceMail: corps does not contain cid:logo-asso when no logo and template has no {logo}', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>Bonjour {prenom}</p>',
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->corpsHtml)->not->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

// ── MessageLibreMail ──────────────────────────────────────────────────────────

it('MessageLibreMail: corps contains cid:logo-asso when logo exists and {logo} in template', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>{logo}Bonjour {prenom}</p>',
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('MessageLibreMail: logo attachment added when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>{logo}Bonjour</p>',
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
});

it('MessageLibreMail: no logo attachment when logo exists but body does not reference {logo}', function () {
    // Reproduit le bug "logo géant" — voir AttestationPresenceMail équivalent.
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>Bonjour</p>', // pas de {logo}
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

it('MessageLibreMail: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>Bonjour</p>',
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

// ── CommunicationTiersMail ────────────────────────────────────────────────────

it('CommunicationTiersMail: corps contains cid:logo-asso when logo exists and {logo} in template', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>{logo}Bonjour {prenom}</p>',
        trackingToken: null,
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('CommunicationTiersMail: logo attachment added when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>{logo}Bonjour</p>',
        trackingToken: null,
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
});

it('CommunicationTiersMail: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>Bonjour</p>',
        trackingToken: null,
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

// ── DocumentMail ──────────────────────────────────────────────────────────────

function makeDocumentMail(?string $customCorps): DocumentMail
{
    return new DocumentMail(
        prenomDestinataire: 'Alice',
        nomDestinataire: 'Durand',
        typeDocument: 'facture',
        typeDocumentArticle: 'la facture',
        typeDocumentArticleDe: 'de la facture',
        numeroDocument: 'F-2025-001',
        dateDocument: '01/01/2025',
        montantTotal: '150,00 €',
        customObjet: null,
        customCorps: $customCorps,
        pdfContent: '%PDF-fake',
        pdfFilename: 'facture.pdf',
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );
}

it('DocumentMail: logo attaché quand body référence {logo}', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = makeDocumentMail('<p>{logo}Bonjour {prenom}</p>');

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
    expect($attachments)->toHaveCount(2); // PDF + logo
});

it('DocumentMail: no logo attachment when logo exists but body does not reference {logo}', function () {
    // C'est le scénario du bug "logo géant" remonté par l'utilisateur le 16/05/2026
    // — le template document seedé n'a pas de {logo}, le logo était attaché en CID
    // sans référence, Apple Mail / Gmail l'affichait à la fin du message à sa taille
    // native (le fameux "logo géant").
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = makeDocumentMail('<p>Bonjour {prenom}</p>'); // pas de {logo}

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
    expect($attachments)->toHaveCount(1); // PDF seul
});

it('DocumentMail: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = makeDocumentMail('<p>{logo}Bonjour</p>');

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

// ── FormulaireInvitation ──────────────────────────────────────────────────────

function makeFormulaireInvitation(?string $customCorps): FormulaireInvitation
{
    return new FormulaireInvitation(
        prenomParticipant: 'Alice',
        nomParticipant: 'Durand',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        formulaireUrl: 'https://example.test/formulaire/abc',
        tokenCode: 'ABCD1234',
        dateExpiration: '31/12/2026',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        customObjet: null,
        customCorps: $customCorps,
        libelleArticle: null,
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );
}

it('FormulaireInvitation: logo attaché quand body référence {logo}', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = makeFormulaireInvitation('<p>{logo}Bonjour {prenom}</p>');

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
});

it('FormulaireInvitation: no logo attachment when logo exists but body does not reference {logo}', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = makeFormulaireInvitation('<p>Bonjour {prenom}</p>'); // pas de {logo}

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

it('FormulaireInvitation: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = makeFormulaireInvitation('<p>{logo}Bonjour</p>');

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});
