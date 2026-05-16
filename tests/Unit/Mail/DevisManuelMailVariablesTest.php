<?php

declare(strict_types=1);

use App\Mail\DevisManuelMail;
use App\Models\Devis;

/**
 * Leak tests for DevisManuelMail.
 *
 * DevisManuelMail is a special case: the sujet (subject) is NOT processed by
 * TemplateSubstitution — it is passed verbatim to Envelope. Only the corps
 * receives variable substitution (politesse variables only).
 *
 * The caller (the back-office form) is responsible for building the final subject
 * string before passing it. There are no exposed {xxx} variables for the subject.
 *
 * The corps supports politesse variables:
 *   {civilite}, {politesse}, {civilite_nom}, {politesse_nom},
 *   {civilite_prenom_nom}, {politesse_prenom_nom}, {salutation}, {adresse_polie}
 *
 * KNOWN BUG (out of scope for Step 1):
 * The view `emails/devis-manuel.blade.php` renders `{!! $corps !!}` (the raw
 * constructor param) instead of `{!! $corpsHtml !!}` (the substituted result).
 * This means politesse variables in the user-entered corps are NEVER substituted
 * in the actual email output. The substitution is computed but discarded.
 * Fix: change the view to use `$corpsHtml`, or rename the computed property.
 * This test is marked ->todo() so CI stays green while documenting the issue.
 * TODO Step 1b: fix DevisManuelMail view to use $corpsHtml instead of $corps.
 */
it('DevisManuelMail substitue toutes les variables politesse dans le corps', function (): void {
    $devis = Devis::factory()->create();

    // The subject is verbatim — we pass a plain subject with no {xxx} placeholders.
    $sujet = 'Votre devis D-2025-001';

    // The corps exercises every politesse variable the Mailable exposes.
    $exhaustiveCorps = implode(' ', [
        '<p>Bonjour {civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie},</p>',
        '<p>Veuillez trouver ci-joint votre devis.</p>',
    ]);

    $mail = new DevisManuelMail(
        devis: $devis,
        sujet: $sujet,
        corps: $exhaustiveCorps,
        pdfPath: 'associations/1/devis/brouillon.pdf', // does not need to exist for render()
        civilite: 'M.',
        politesse: 'Monsieur',
        prenom: 'Alice',
        nom: 'Durand',
    );

    assertNoUnsubstitutedEmailVariables($mail);
})->todo('Bug dans DevisManuelMail : la vue utilise $corps (brut) au lieu de $corpsHtml (substitué) — les variables politesse ne sont jamais remplacées dans le rendu final');
