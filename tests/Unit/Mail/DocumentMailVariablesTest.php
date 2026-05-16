<?php

declare(strict_types=1);

use App\Mail\DocumentMail;

/**
 * Leak tests for DocumentMail.
 *
 * The canonical variable set for category "document" is defined in:
 *   - app/Enums/CategorieEmail.php → Document case
 *   - database/seeders/EmailTemplateSeeder.php → 'document' template
 *
 * The seeder's default objet is:
 *   '{type_document_uc} n°{numero_document} — {operation}'
 *
 * Bug 1 (tracked here): DocumentMail::variables() does NOT map {operation}
 * or {type_operation}. The test below that covers {operation}/{type_operation}
 * is marked ->todo() and will turn green once Step 2 wires those in.
 */

/**
 * Build a DocumentMail with all non-{operation}/{type_operation} variables
 * using a custom objet + corps that exercises every supported placeholder.
 */
function makeDocumentMailBase(?string $objet = null, ?string $corps = null): DocumentMail
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
        customObjet: $objet,
        customCorps: $corps,
        pdfContent: '%PDF-dummy',
        pdfFilename: 'facture.pdf',
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );
}

// ---------------------------------------------------------------------------
// Test 1 — Variables supported today (MUST STAY GREEN)
// Covers every placeholder that DocumentMail::variables() already maps.
// ---------------------------------------------------------------------------
it('DocumentMail substitue toutes les variables courantes (sans {operation})', function (): void {
    $exhaustiveObjet = '{type_document_uc} n°{numero_document}';
    $exhaustiveCorps = implode(' ', [
        '<p>{prenom} {nom} {civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie}',
        '{type_document} {type_document_uc} {type_document_article} {type_document_article_de}',
        '{numero_document} {date_document} {montant_total}</p>',
    ]);

    $mail = makeDocumentMailBase($exhaustiveObjet, $exhaustiveCorps);

    assertNoUnsubstitutedEmailVariables($mail);
});

// ---------------------------------------------------------------------------
// Test 2 — Bug 1: {operation} and {type_operation} NOT yet wired (INTENTIONAL RED)
// This test is marked ->todo() so CI stays green.
// It will turn green automatically when Step 2 adds {operation}/{type_operation}
// to DocumentMail::variables().
// TODO Step 2: remove ->todo() once {operation} is wired in DocumentMail.
// ---------------------------------------------------------------------------
it('DocumentMail substitue {operation} et {type_operation} dans le sujet', function (): void {
    // This is the seeder's default objet — the one users configure in their templates.
    $objetSeedeur = '{type_document_uc} n°{numero_document} — {operation}';

    $mail = makeDocumentMailBase($objetSeedeur, null);

    // We verify the rendered subject no longer contains raw placeholders.
    assertNoUnsubstitutedEmailVariables($mail);
});
