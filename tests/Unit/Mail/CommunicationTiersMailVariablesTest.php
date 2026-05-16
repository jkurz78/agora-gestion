<?php

declare(strict_types=1);

use App\Mail\CommunicationTiersMail;

/**
 * Leak tests for CommunicationTiersMail.
 *
 * The canonical variable set for category "communication" is defined in:
 *   - app/Enums/CategorieEmail.php → Communication case
 *
 * Communication mails are NOT operation-scoped: no {operation}, {type_operation},
 * {date_debut}, etc. They carry tiers-level variables only.
 *
 * Note: {logo} is substituted by EmailLogo::variables() — when no logo exists
 * (test environment), the substitution resolves to an empty string, which is fine.
 * The {logo_operation} variable also resolves to empty string (no TypeOperation logo
 * in tests).
 */
it('CommunicationTiersMail substitue toutes les variables', function (): void {
    // Exhaustive subject + body covering every placeholder from CategorieEmail::Communication.
    $exhaustiveObjet = 'Bonjour {prenom} {nom} — {association}';

    $exhaustiveCorps = implode(' ', [
        '<p>{prenom} {nom} {email} {association}',
        '{civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie}',
        '{lien_optout} {lien_desinscription}</p>',
        // {logo} and {logo_operation} are substituted by EmailLogo::variables()
        // In the test environment, no logo file exists, so they resolve to empty string.
        '<p>{logo} {logo_operation}</p>',
    ]);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Durand',
        email: 'alice@test.fr',
        objet: $exhaustiveObjet,
        corps: $exhaustiveCorps,
        trackingToken: 'tok-abc123',
        attachmentPaths: [],
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    assertNoUnsubstitutedEmailVariables($mail);
});
