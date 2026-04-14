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
