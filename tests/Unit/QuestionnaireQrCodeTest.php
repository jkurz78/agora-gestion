<?php

declare(strict_types=1);

use App\Support\QuestionnaireQrCode;

it('returns a PNG data-URI for a URL', function (): void {
    $dataUri = QuestionnaireQrCode::dataUri('https://exemple.test/q/abc123');

    expect($dataUri)->toBeString()->not->toBeEmpty();
    expect($dataUri)->toStartWith('data:image/png;base64,');
});

it('base64 payload decodes to a valid PNG', function (): void {
    $dataUri = QuestionnaireQrCode::dataUri('https://exemple.test/q/abc123');

    $base64 = substr($dataUri, strlen('data:image/png;base64,'));
    $bytes = base64_decode($base64, strict: true);

    expect($bytes)->not->toBeFalse();
    // PNG signature: \x89PNG\r\n\x1a\n
    expect(substr((string) $bytes, 0, 4))->toBe("\x89PNG");
});
