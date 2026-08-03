<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Symfony\Component\Yaml\Yaml;

/*
 * Le bandeau de /login est le contrat public de la démo. Les comptes qu'il
 * annonce doivent exister dans le snapshot que `demo:reset` rejoue chaque nuit.
 *
 * LoginBannerTest vérifie que le bandeau s'affiche ; il ne dit rien de la
 * réalité derrière. Le 2026-07-13, `demo:capture` a été lancée sur une base de
 * développement sans passer par l'étape de renommage du runbook : le snapshot
 * s'est mis à porter les comptes en monasso.fr, tandis que le bandeau
 * continuait d'annoncer ceux en demo.fr. La démo est restée inconnectable
 * jusqu'au 2026-08-03.
 *
 * Le renommage manuel a été abandonné : le snapshot fait foi, le bandeau le
 * suit. Ce test tient les deux ensemble, sans présumer lequel a raison — il
 * échoue dès qu'ils divergent, quel que soit le côté qui a bougé.
 */
it('les comptes annoncés par le bandeau existent dans le snapshot de démo', function (): void {
    $bandeau = (string) file_get_contents(
        resource_path('views/components/demo-login-banner.blade.php')
    );

    preg_match_all(
        '#<td><code>([^<]+)</code></td>\s*<td><code>([^<]+)</code></td>#',
        $bandeau,
        $lignes,
        PREG_SET_ORDER,
    );

    expect($lignes)->not->toBeEmpty(
        'Aucun couple email/mot de passe lisible dans le bandeau de démo.'
    );

    $snapshot = Yaml::parseFile(base_path('database/demo/snapshot.yaml'));
    $utilisateurs = collect($snapshot['tables']['users'] ?? [])->keyBy('email');

    foreach ($lignes as [, $email, $motDePasse]) {
        expect($utilisateurs->has($email))->toBeTrue(
            "Le bandeau annonce {$email}, mais ce compte est absent du snapshot."
        );

        expect(Hash::check($motDePasse, $utilisateurs[$email]['password']))->toBeTrue(
            "Le mot de passe annoncé pour {$email} ne correspond pas au hash du snapshot."
        );
    }
});
