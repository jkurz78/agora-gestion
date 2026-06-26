<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\EmargementDocumentHandler;
use App\Services\Emargement\ImagickQrCodeExtractor;
use App\Services\IncomingDocuments\IncomingDocumentIngester;
use App\Services\Questionnaire\Contracts\QrDecoderContract;
use App\Services\Questionnaire\QuestionnaireQrDecoder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class IncomingDocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the QR extractor interface to the Imagick-based implementation.
        $this->app->bind(QrCodeExtractor::class, ImagickQrCodeExtractor::class);

        // Bind the questionnaire QR decoder interface.
        $this->app->bind(QrDecoderContract::class, QuestionnaireQrDecoder::class);

        // Bind the ingester with its handler chain.
        // Order matters: handlers are tried in the order listed.
        $this->app->bind(IncomingDocumentIngester::class, function (Application $app): IncomingDocumentIngester {
            return new IncomingDocumentIngester([
                $app->make(EmargementDocumentHandler::class),
                // v2.9+ : ajouter d'autres handlers ici (ex: FactureDocumentHandler)
            ]);
        });
    }
}
