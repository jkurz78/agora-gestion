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

// --- v4.2.9: HTML email allowlist élargie ---

it('conserve le style inline sur les balises p, h2, a et table', function () {
    $html = '<table style="background: #f7f4f1; padding: 32px 0;" bgcolor="#f7f4f1" width="100%">'
        .'<tr><td bgcolor="#fff" style="padding: 24px;">'
        .'<h2 style="color: #A6145A; font-size: 22px;">Titre</h2>'
        .'<p style="color: #444; font-family: Arial;">Texte</p>'
        .'<a href="https://example.com" style="color: #712C8D;">Lien</a>'
        .'</td></tr></table>';

    $result = EmailTemplate::sanitizeCorps($html);

    // HTMLPurifier minifie les espaces autour des `:` — on tolère les deux formes.
    expect($result)->toMatch('/background:\s*#f7f4f1/');
    expect($result)->toMatch('/padding:\s*32px/');
    expect($result)->toMatch('/color:\s*#A6145A/');
    expect($result)->toMatch('/font-family:\s*Arial/');
    expect($result)->toMatch('/color:\s*#712C8D/');
    expect($result)->toContain('bgcolor="#f7f4f1"');
    expect($result)->toContain('width="100%"');
});

it('conserve les attributs legacy de tableau (cellpadding, cellspacing, border, role)', function () {
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600">'
        .'<tr valign="top"><td align="center" colspan="2">cell</td></tr></table>';

    $result = EmailTemplate::sanitizeCorps($html);

    expect($result)->toContain('cellpadding="0"');
    expect($result)->toContain('cellspacing="0"');
    expect($result)->toContain('border="0"');
    expect($result)->toContain('width="600"');
    expect($result)->toContain('valign="top"');
    expect($result)->toContain('align="center"');
    expect($result)->toContain('colspan="2"');
});

it('conserve les classes CSS', function () {
    $html = '<table class="email-container body-bg"><tr><td class="px-40">contenu</td></tr></table>';

    $result = EmailTemplate::sanitizeCorps($html);

    expect($result)->toContain('class="email-container body-bg"');
    expect($result)->toContain('class="px-40"');
});

it('conserve les propriétés CSS étendues utilisées dans les emails', function () {
    $html = '<div style="display: inline-block; max-width: 600px; border-radius: 6px; '
        .'line-height: 1.6; letter-spacing: 0.12em; text-transform: uppercase; '
        .'box-shadow: 0 2px 8px rgba(0,0,0,0.05);">Email-friendly</div>';

    $result = EmailTemplate::sanitizeCorps($html);

    expect($result)->toMatch('/display:\s*inline-block/');
    expect($result)->toMatch('/max-width:\s*600px/');
    expect($result)->toMatch('/border-radius:\s*6px/');
    expect($result)->toMatch('/line-height:\s*1\.6/');
    expect($result)->toContain('letter-spacing');
    expect($result)->toContain('text-transform');
    expect($result)->toContain('box-shadow');
});
