<?php

declare(strict_types=1);

use App\Support\EmargementQrCode;
use Zxing\QrReader;

it('generates a base64 PNG string for a seance id', function () {
    $base64 = EmargementQrCode::generateBase64Png(42);

    expect($base64)->toBeString()->not->toBeEmpty();
    $decoded = base64_decode($base64, true);
    expect($decoded)->not->toBeFalse();
    // PNG magic bytes
    expect(substr($decoded, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('parses its own generated content (round trip)', function () {
    // config('app.env') will be 'testing' in Pest tests
    $content = EmargementQrCode::buildContent(42);

    expect(EmargementQrCode::parseContent($content))->toBe(42);
});

it('rejects content without the emargement prefix', function () {
    expect(EmargementQrCode::parseContent('facture:testing:42'))->toBeNull();
    expect(EmargementQrCode::parseContent('42'))->toBeNull();
    expect(EmargementQrCode::parseContent(''))->toBeNull();
});

it('rejects content with mismatched environment', function () {
    // 'testing' is the current env; these should be rejected
    expect(EmargementQrCode::parseContent('emargement:production:42'))->toBeNull();
    expect(EmargementQrCode::parseContent('emargement:staging:42'))->toBeNull();
});

it('rejects malformed content', function () {
    expect(EmargementQrCode::parseContent('emargement:testing:'))->toBeNull();
    expect(EmargementQrCode::parseContent('emargement:testing:abc'))->toBeNull();
    expect(EmargementQrCode::parseContent('emargement::42'))->toBeNull();
});

it('round-trips through QR decoding', function () {
    $base64 = EmargementQrCode::generateBase64Png(42);
    $pngBytes = base64_decode($base64, true);
    expect($pngBytes)->not->toBeFalse();

    // Decode the QR from the generated PNG using the same library Task 7 will use
    $reader = new QrReader($pngBytes, QrReader::SOURCE_TYPE_BLOB);
    $decoded = $reader->text();

    expect($decoded)->toBe(EmargementQrCode::buildContent(42));
    expect(EmargementQrCode::parseContent($decoded))->toBe(42);
});
