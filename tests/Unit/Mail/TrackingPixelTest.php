<?php

declare(strict_types=1);

use App\Mail\AttestationPresenceMail;
use App\Mail\DevisManuelMail;
use App\Mail\DocumentMail;
use App\Mail\FormulaireInvitation;
use App\Mail\MessageLibreMail;
use App\Models\Devis;
use App\Models\Tiers;

/**
 * Vérifie que les Mailables qui supportent `trackingToken` :
 * 1. embarquent le pixel <img src=".../t/{token}.gif"> dans le corps quand un token est fourni
 * 2. n'embarquent rien quand le token est absent
 *
 * Le pixel est servi par EmailTrackingController qui crée un EmailOpen — c'est ce
 * mécanisme qui peuple la colonne "ouvertures" sur l'onglet Communications.
 *
 * MessageLibreMail et CommunicationTiersMail ont déjà ce pattern. Ce test couvre
 * l'extension faite 2026-05-16 à DocumentMail, AttestationPresenceMail,
 * FormulaireInvitation et DevisManuelMail (ainsi que MessageLibreMail en garde-fou).
 */

// ── DocumentMail ─────────────────────────────────────────────────────────────

function makeDocumentMailTracking(?string $token): DocumentMail
{
    return new DocumentMail(
        prenomDestinataire: 'Alice',
        nomDestinataire: 'Durand',
        typeDocument: 'facture',
        typeDocumentArticle: 'la facture',
        typeDocumentArticleDe: 'de la facture',
        numeroDocument: 'F-001',
        dateDocument: '01/01/2025',
        montantTotal: '150,00 €',
        customObjet: null,
        customCorps: '<p>Bonjour {prenom}</p>',
        pdfContent: '%PDF',
        pdfFilename: 'f.pdf',
        trackingToken: $token,
    );
}

it('DocumentMail: pixel de tracking présent quand trackingToken fourni', function () {
    $mail = makeDocumentMailTracking('abc123token');

    expect($mail->corpsHtml)->toContain('/t/abc123token.gif')
        ->and($mail->corpsHtml)->toContain('width="1"')
        ->and($mail->corpsHtml)->toContain('height="1"');
});

it('DocumentMail: aucun pixel quand trackingToken absent', function () {
    $mail = makeDocumentMailTracking(null);

    expect($mail->corpsHtml)->not->toContain('/t/')
        ->and($mail->corpsHtml)->not->toContain('.gif');
});

// ── AttestationPresenceMail ──────────────────────────────────────────────────

function makeAttestationPresenceMail(?string $token): AttestationPresenceMail
{
    return new AttestationPresenceMail(
        prenomParticipant: 'Bob',
        nomParticipant: 'Martin',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>Bonjour {prenom}</p>',
        pdfContent: '%PDF',
        pdfFilename: 'a.pdf',
        trackingToken: $token,
    );
}

it('AttestationPresenceMail: pixel de tracking présent quand trackingToken fourni', function () {
    $mail = makeAttestationPresenceMail('xyz789token');

    expect($mail->corpsHtml)->toContain('/t/xyz789token.gif');
});

it('AttestationPresenceMail: aucun pixel quand trackingToken absent', function () {
    $mail = makeAttestationPresenceMail(null);

    expect($mail->corpsHtml)->not->toContain('/t/');
});

// ── FormulaireInvitation ─────────────────────────────────────────────────────

function makeFormulaireInvitationTracking(?string $token): FormulaireInvitation
{
    return new FormulaireInvitation(
        prenomParticipant: 'Charlie',
        nomParticipant: 'Renard',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        formulaireUrl: 'https://example.test/formulaire/abc',
        tokenCode: 'CODE1234',
        dateExpiration: '31/12/2026',
        customCorps: '<p>Bonjour {prenom}</p>',
        trackingToken: $token,
    );
}

it('FormulaireInvitation: pixel de tracking présent quand trackingToken fourni', function () {
    $mail = makeFormulaireInvitationTracking('form123token');

    expect($mail->corpsHtml)->toContain('/t/form123token.gif');
});

it('FormulaireInvitation: aucun pixel quand trackingToken absent', function () {
    $mail = makeFormulaireInvitationTracking(null);

    expect($mail->corpsHtml)->not->toContain('/t/');
});

// ── DevisManuelMail ──────────────────────────────────────────────────────────

function makeDevisManuelMail(?string $token): DevisManuelMail
{
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create([
        'tiers_id' => $tiers->id,
        'numero' => 'D-001',
    ]);

    return new DevisManuelMail(
        devis: $devis,
        sujet: 'Devis',
        corps: '<p>Bonjour</p>',
        pdfPath: 'tmp/d.pdf',
        prenom: 'David',
        nom: 'Klein',
        trackingToken: $token,
    );
}

it('DevisManuelMail: pixel de tracking présent quand trackingToken fourni', function () {
    $mail = makeDevisManuelMail('devistoken');

    expect($mail->corpsHtml)->toContain('/t/devistoken.gif');
});

it('DevisManuelMail: aucun pixel quand trackingToken absent', function () {
    $mail = makeDevisManuelMail(null);

    expect($mail->corpsHtml)->not->toContain('/t/');
});

// ── MessageLibreMail (garde-fou de non-régression) ───────────────────────────

it('MessageLibreMail: pixel de tracking présent quand trackingToken fourni (regression guard)', function () {
    $mail = new MessageLibreMail(
        prenomParticipant: 'Eve',
        nomParticipant: 'Noir',
        emailParticipant: 'eve@test.fr',
        operationNom: 'Op',
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
        nbSeancesEffectuees: 0,
        nbSeancesRestantes: 10,
        objet: 'Test',
        corps: '<p>Bonjour</p>',
        trackingToken: 'msglib123',
    );

    expect($mail->corpsHtml)->toContain('/t/msglib123.gif');
});
