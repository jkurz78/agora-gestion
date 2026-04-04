<?php

declare(strict_types=1);

use App\Models\ParticipantDonneesMedicales;

it('strips script tags from notes', function () {
    $dirty = '<p>Notes</p><script>alert("xss")</script>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))
        ->toBe('<p>Notes</p>alert("xss")');
});

it('strips event handlers from tags', function () {
    $dirty = '<p onmouseover="alert(1)">Texte</p>';
    $result = ParticipantDonneesMedicales::sanitizeNotes($dirty);
    expect($result)->not->toContain('onmouseover');
});

it('preserves allowed formatting tags', function () {
    $html = '<p>Texte <strong>gras</strong> et <em>italique</em></p><ul><li>Item</li></ul>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($html))->toBe($html);
});

it('strips iframe tags', function () {
    $dirty = '<p>Text</p><iframe src="evil.com"></iframe>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))
        ->toBe('<p>Text</p>');
});

it('returns empty string for empty input', function () {
    expect(ParticipantDonneesMedicales::sanitizeNotes(''))->toBe('');
});

it('strips img tags with onerror', function () {
    $dirty = '<img src=x onerror=alert(1)>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))->toBe('');
});
