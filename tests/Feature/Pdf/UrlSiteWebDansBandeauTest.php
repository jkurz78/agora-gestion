<?php

declare(strict_types=1);

/**
 * Garde-fou statique : chaque template PDF à destination des tiers doit afficher
 * `$association->url_site_web` (ou `$asso->url_site_web` pour recu-fiscal-don)
 * dans son bandeau coordonnées asso.
 *
 * Périmètre tiers — DOIT contenir url_site_web :
 *  - facture
 *  - devis-manuel
 *  - document-previsionnel (devis prévisionnel + pro forma)
 *  - attestation-presence
 *  - recu-fiscal-don
 *  - participant-droit-image
 *
 * Hors périmètre (PDFs internes) — NE doivent PAS être modifiés :
 *  - rapprochement, remise-bancaire, rapport-layout
 *  - participants-{liste,annuaire}, seances-matrice, seance-emargement
 *  - participant-fiche
 *
 * Un rendu complet de chacun via le service correspondant serait préférable, mais
 * chaque template demande un setup spécifique (Facture, Devis, DocumentPrevisionnel,
 * Recu, etc.) — le grep statique est suffisant pour empêcher la régression « j'ai
 * supprimé la ligne url_site_web par mégarde ».
 */
dataset('templates_tiers_avec_url', [
    'facture' => ['resources/views/pdf/facture.blade.php', '$association->url_site_web'],
    'devis-manuel' => ['resources/views/pdf/devis-manuel.blade.php', '$association->url_site_web'],
    'document-previsionnel' => ['resources/views/pdf/document-previsionnel.blade.php', '$association->url_site_web'],
    'attestation-presence' => ['resources/views/pdf/attestation-presence.blade.php', '$association->url_site_web'],
    'recu-fiscal-don' => ['resources/views/pdf/recu-fiscal-don.blade.php', '$asso->url_site_web'],
    'participant-droit-image' => ['resources/views/pdf/participant-droit-image.blade.php', '$association->url_site_web'],
]);

it('PDF tiers affiche url_site_web dans le bandeau coordonnées', function (string $relativePath, string $expectedAccessor) {
    $absolute = base_path($relativePath);
    expect(file_exists($absolute))->toBeTrue();

    $content = file_get_contents($absolute);
    expect($content)->toContain($expectedAccessor);
})->with('templates_tiers_avec_url');

it('PDF tiers affiche email et telephone dans le bandeau coordonnées', function (string $relativePath, string $expectedAccessor) {
    // L'accesseur de base ($association ou $asso) est dérivé de celui de url_site_web.
    $base = str_replace('url_site_web', '', $expectedAccessor); // ex: '$association->'
    $absolute = base_path($relativePath);
    $content = file_get_contents($absolute);

    expect($content)
        ->toContain($base.'email')
        ->and($content)->toContain($base.'telephone');
})->with('templates_tiers_avec_url');

dataset('templates_internes_sans_url', [
    'rapprochement' => 'resources/views/pdf/rapprochement.blade.php',
    'remise-bancaire' => 'resources/views/pdf/remise-bancaire.blade.php',
    'rapport-layout' => 'resources/views/pdf/rapport-layout.blade.php',
    'participants-liste' => 'resources/views/pdf/participants-liste.blade.php',
    'participants-annuaire' => 'resources/views/pdf/participants-annuaire.blade.php',
    'seances-matrice' => 'resources/views/pdf/seances-matrice.blade.php',
    'seance-emargement' => 'resources/views/pdf/seance-emargement.blade.php',
    'participant-fiche' => 'resources/views/pdf/participant-fiche.blade.php',
]);

it('PDF interne n\'affiche PAS url_site_web (pas de tiers destinataire)', function (string $relativePath) {
    $absolute = base_path($relativePath);
    expect(file_exists($absolute))->toBeTrue();

    $content = file_get_contents($absolute);
    expect($content)->not->toContain('url_site_web');
})->with('templates_internes_sans_url');
