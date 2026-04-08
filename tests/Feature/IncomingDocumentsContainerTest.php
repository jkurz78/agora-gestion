<?php

declare(strict_types=1);

use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\EmargementDocumentHandler;
use App\Services\Emargement\ImagickQrCodeExtractor;
use App\Services\Emargement\SeanceFeuilleAttacher;
use App\Services\IncomingDocuments\IncomingDocumentIngester;

it('resolves the QrCodeExtractor interface to the Imagick implementation', function () {
    $extractor = app(QrCodeExtractor::class);

    expect($extractor)->toBeInstanceOf(ImagickQrCodeExtractor::class);
});

it('resolves EmargementDocumentHandler from the container', function () {
    $handler = app(EmargementDocumentHandler::class);

    expect($handler)->toBeInstanceOf(EmargementDocumentHandler::class);
});

it('resolves SeanceFeuilleAttacher from the container', function () {
    $attacher = app(SeanceFeuilleAttacher::class);

    expect($attacher)->toBeInstanceOf(SeanceFeuilleAttacher::class);
});

it('resolves the IncomingDocumentIngester with the handler chain', function () {
    $ingester = app(IncomingDocumentIngester::class);

    expect($ingester)->toBeInstanceOf(IncomingDocumentIngester::class);
});
