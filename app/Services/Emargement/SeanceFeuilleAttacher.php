<?php

declare(strict_types=1);

namespace App\Services\Emargement;

use App\Models\Seance;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class SeanceFeuilleAttacher
{
    public function __construct(
        private readonly QrCodeExtractor $extractor,
    ) {}

    public function attach(
        string $tempPath,
        string $originalFilename,
        Seance $seance,
    ): AttachResult {
        $qr = $this->extractor->extractSeanceIdFromPdf($tempPath);

        if ($qr->seanceId === null) {
            return AttachResult::failure($qr->reason, $qr->detail);
        }

        if ($qr->seanceId !== $seance->id) {
            return AttachResult::failure(
                'qr_mismatch',
                "Le QR pointe vers la séance {$qr->seanceId}, pas vers celle-ci ({$seance->id}).",
            );
        }

        if ($seance->feuille_signee_path !== null) {
            Log::info('Feuille signée écrasée (flux direct)', [
                'seance_id' => $seance->id,
                'previous_at' => $seance->feuille_signee_at?->toIso8601String(),
                'new_original_filename' => $originalFilename,
            ]);
        }

        $fullPath = $seance->storagePath('seances/'.$seance->id.'/feuille-signee.pdf');
        Storage::disk('local')->put($fullPath, file_get_contents($tempPath));

        $seance->update([
            'feuille_signee_path' => 'feuille-signee.pdf',
            'feuille_signee_at' => now(),
            'feuille_signee_source' => 'manual',
            'feuille_signee_sender_email' => null,
        ]);

        return AttachResult::success();
    }
}
