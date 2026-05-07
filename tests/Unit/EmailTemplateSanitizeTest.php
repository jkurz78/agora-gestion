<?php

declare(strict_types=1);

use App\Models\EmailTemplate;

it('supprime les balises script', function () {
    $dirty = '<p>Texte</p><script>alert("xss")</script>';
    expect(EmailTemplate::sanitizeCorps($dirty))->not->toContain('<script>');
});

it('supprime les attributs onerror sur img', function () {
    $dirty = '<img src="x" onerror="alert(1)">';
    $result = EmailTemplate::sanitizeCorps($dirty);
    expect($result)->not->toContain('onerror');
});

it('supprime les href javascript', function () {
    $dirty = '<a href="javascript:alert(1)">clique</a>';
    $result = EmailTemplate::sanitizeCorps($dirty);
    expect($result)->not->toContain('javascript:');
});

it('supprime les handlers onclick sur les balises', function () {
    $dirty = '<p onclick="alert(1)">Texte</p>';
    $result = EmailTemplate::sanitizeCorps($dirty);
    expect($result)->not->toContain('onclick');
    expect($result)->toContain('<p>Texte</p>');
});

it('conserve le formatage légitime', function () {
    $html = '<p>Texte <strong>gras</strong> et <em>italique</em></p>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('<strong>gras</strong>');
    expect($result)->toContain('<em>italique</em>');
});

it('conserve les liens https valides', function () {
    $html = '<a href="https://example.com" target="_blank">Lien</a>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('href="https://example.com"');
});

it('conserve les listes', function () {
    $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
    expect(EmailTemplate::sanitizeCorps($html))->toContain('<ul><li>Item 1</li><li>Item 2</li></ul>');
});

it('retourne une chaîne vide pour une entrée vide', function () {
    expect(EmailTemplate::sanitizeCorps(''))->toBe('');
});

// --- Bug 5: preserve template variables {var} through HTMLPurifier ---

it('préserve les variables {prenom} {nom} {email} en texte', function () {
    $html = '<p>Bonjour {prenom} {nom}, votre email est {email}.</p>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('{prenom}');
    expect($result)->toContain('{nom}');
    expect($result)->toContain('{email}');
});

it('préserve la variable {lien_desinscription} en texte', function () {
    $html = '<p>Pour vous désabonner : {lien_desinscription}</p>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('{lien_desinscription}');
});

it('préserve {lien_optout} dans un attribut href', function () {
    $html = '<a href="{lien_optout}">Se désabonner</a>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('href="{lien_optout}"');
});

it('préserve {logo} dans un attribut src', function () {
    $html = '<img src="{logo}" alt="Logo">';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('src="{logo}"');
});

it('préserve simultanément variables texte et href', function () {
    $html = '<p>Bonjour {prenom}, <a href="{lien_optout}">se désabonner</a> ou {lien_desinscription}</p>';
    $result = EmailTemplate::sanitizeCorps($html);
    expect($result)->toContain('{prenom}');
    expect($result)->toContain('href="{lien_optout}"');
    expect($result)->toContain('{lien_desinscription}');
});
