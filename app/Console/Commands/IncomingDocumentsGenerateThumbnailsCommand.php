<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IncomingDocument;
use App\Services\IncomingDocuments\IncomingDocumentThumbnailGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class IncomingDocumentsGenerateThumbnailsCommand extends Command
{
    protected $signature = 'incoming:generate-thumbnails {--force : Régénérer même si la vignette existe déjà}';

    protected $description = 'Génère les vignettes manquantes pour les documents de la boîte de réception';

    public function handle(IncomingDocumentThumbnailGenerator $generator): int
    {
        $force = (bool) $this->option('force');
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $docs = IncomingDocument::all();
        $this->info("Traitement de {$docs->count()} documents...");

        foreach ($docs as $doc) {
            $thumbPath = IncomingDocument::thumbnailPath($doc->storage_path);

            if (! $force && Storage::disk('local')->exists($thumbPath)) {
                $skipped++;

                continue;
            }

            $sourceAbsolute = Storage::disk('local')->path($doc->storage_path);
            if (! file_exists($sourceAbsolute)) {
                $this->warn("PDF introuvable : {$doc->storage_path}");
                $failed++;

                continue;
            }

            $destAbsolute = Storage::disk('local')->path($thumbPath);
            $ok = $generator->generate($sourceAbsolute, $destAbsolute);

            if ($ok) {
                $generated++;
            } else {
                $failed++;
            }
        }

        $this->info("Vignettes générées : {$generated}");
        $this->info("Vignettes sautées : {$skipped}");
        if ($failed > 0) {
            $this->warn("Échecs : {$failed}");
        }

        return self::SUCCESS;
    }
}
